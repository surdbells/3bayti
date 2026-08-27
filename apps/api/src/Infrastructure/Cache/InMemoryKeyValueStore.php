<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Cache;

/**
 * In-memory implementation of KeyValueStore for tests and local
 * development environments where Redis isn't available.
 *
 * NOT FOR PRODUCTION
 * ------------------
 * This backend is per-process. Each PHP-FPM worker would have its
 * own copy, defeating the entire purpose of a shared store. If you
 * see this being used in production, that's a misconfiguration.
 *
 * The DI container binds RedisKeyValueStore by default; this class
 * is registered only in test bootstrapping and the (rare) local-dev
 * setup where Redis isn't running.
 *
 * Behaviour
 * ---------
 * Stores values in a private array. Honors TTLs by stamping each
 * entry with an expiry timestamp and lazily checking on read. No
 * background reaper, expired entries linger in memory until next
 * read.
 *
 * Never throws (no backend to fail).
 */
final class InMemoryKeyValueStore implements KeyValueStore
{
    /**
     * @var array<string, array{value: string, expiresAt: ?int}>
     *   expiresAt is unix timestamp or null for no expiry
     */
    private array $store = [];

    public function get(string $key): ?string
    {
        if (!isset($this->store[$key])) {
            return null;
        }

        $entry = $this->store[$key];
        if ($this->isExpired($entry)) {
            unset($this->store[$key]);
            return null;
        }

        return $entry['value'];
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): void
    {
        $this->store[$key] = [
            'value' => $value,
            'expiresAt' => $ttlSeconds > 0 ? time() + $ttlSeconds : null,
        ];
    }

    public function incr(string $key): int
    {
        // Start fresh if the key doesn't exist OR has expired.
        if (!isset($this->store[$key]) || $this->isExpired($this->store[$key])) {
            $this->store[$key] = [
                'value' => '1',
                'expiresAt' => null,
            ];
            return 1;
        }

        // Existing key, increment its int value.
        // Match Redis: non-numeric values would error in real Redis;
        // we mimic that to catch test-time bugs early.
        $current = $this->store[$key]['value'];
        if (!ctype_digit($current) && !(str_starts_with($current, '-') && ctype_digit(substr($current, 1)))) {
            throw new KeyValueStoreException(
                sprintf('INCR called on non-integer value at key "%s"', $key),
            );
        }

        $next = ((int) $current) + 1;
        $this->store[$key]['value'] = (string) $next;
        return $next;
    }

    public function setIfAbsent(string $key, string $value, int $ttlSeconds): bool
    {
        // Mirror Redis SET NX EX: only set when the key is absent (or
        // has lazily expired). A live entry means someone else owns the
        // claim → return false without touching it.
        if (isset($this->store[$key]) && !$this->isExpired($this->store[$key])) {
            return false;
        }

        $this->store[$key] = [
            'value' => $value,
            'expiresAt' => $ttlSeconds > 0 ? time() + $ttlSeconds : null,
        ];
        return true;
    }

    public function expire(string $key, int $ttlSeconds): void
    {
        if (!isset($this->store[$key])) {
            // No-op per interface contract.
            return;
        }

        $this->store[$key]['expiresAt'] = $ttlSeconds > 0 ? time() + $ttlSeconds : null;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }

    public function ping(): bool
    {
        return true;
    }

    /**
     * Test helper: clear the entire store. Tests that share an
     * instance across multiple checks call this between assertions.
     */
    public function flushAll(): void
    {
        $this->store = [];
    }

    /**
     * @param array{value: string, expiresAt: ?int} $entry
     */
    private function isExpired(array $entry): bool
    {
        return $entry['expiresAt'] !== null && $entry['expiresAt'] < time();
    }
}
