<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Support\Otp;

use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStoreException;

/**
 * KeyValueStore double whose TTLs follow an injected clock (so tests can jump
 * past a cooldown), that counts operations (to prove the success hot path is
 * one read per scope), that can be switched to "Redis is down" mode where
 * every call throws KeyValueStoreException, and that can run a ONE-SHOT hook
 * just before a matching operation (to interleave a concurrent request at an
 * exact point, e.g. a recovery between two calls of a failure).
 */
final class FakeClockKeyValueStore implements KeyValueStore
{
    /** @var array<string, array{value: string, expiresAt: int|null}> */
    private array $data = [];

    /** @var array<string, int> operation name => count */
    public array $ops = [];

    public bool $down = false;

    /** @var (\Closure(string, string): bool)|null decides whether the hook fires for (op, key) */
    private ?\Closure $hookWhen = null;

    /** @var (\Closure(): void)|null */
    private ?\Closure $hook = null;

    /**
     * @param \Closure(): int $clock
     */
    public function __construct(private readonly \Closure $clock)
    {
    }

    /**
     * Run $hook once, right before the first operation for which $when(op, key)
     * is true (the hook's own store calls don't re-trigger it).
     *
     * @param \Closure(string, string): bool $when
     * @param \Closure(): void               $hook
     */
    public function runOnceBefore(\Closure $when, \Closure $hook): void
    {
        $this->hookWhen = $when;
        $this->hook = $hook;
    }

    public function get(string $key): ?string
    {
        $this->op('get', $key);
        $this->purge($key);
        return $this->data[$key]['value'] ?? null;
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): void
    {
        $this->op('set', $key);
        $this->data[$key] = ['value' => $value, 'expiresAt' => $this->expiry($ttlSeconds)];
    }

    public function incr(string $key): int
    {
        $this->op('incr', $key);
        $this->purge($key);
        $next = (int) ($this->data[$key]['value'] ?? '0') + 1;
        $this->data[$key] = ['value' => (string) $next, 'expiresAt' => $this->data[$key]['expiresAt'] ?? null];
        return $next;
    }

    public function setIfAbsent(string $key, string $value, int $ttlSeconds): bool
    {
        $this->op('setIfAbsent', $key);
        $this->purge($key);
        if (isset($this->data[$key])) {
            return false;
        }
        $this->data[$key] = ['value' => $value, 'expiresAt' => $this->expiry($ttlSeconds)];
        return true;
    }

    public function expire(string $key, int $ttlSeconds): void
    {
        $this->op('expire', $key);
        $this->purge($key);
        if (isset($this->data[$key])) {
            $this->data[$key]['expiresAt'] = $this->expiry($ttlSeconds);
        }
    }

    public function delete(string $key): void
    {
        $this->op('delete', $key);
        unset($this->data[$key]);
    }

    public function ping(): bool
    {
        return !$this->down;
    }

    /** @return list<string> live keys, sorted */
    public function keys(): array
    {
        foreach (array_keys($this->data) as $key) {
            $this->purge($key);
        }
        $keys = array_keys($this->data);
        sort($keys);
        return $keys;
    }

    public function writeOps(): int
    {
        return ($this->ops['set'] ?? 0) + ($this->ops['incr'] ?? 0) + ($this->ops['setIfAbsent'] ?? 0)
            + ($this->ops['expire'] ?? 0) + ($this->ops['delete'] ?? 0);
    }

    private function op(string $name, string $key): void
    {
        if ($this->hook !== null && $this->hookWhen !== null && ($this->hookWhen)($name, $key)) {
            $hook = $this->hook;
            $this->hook = null;
            $this->hookWhen = null;
            $hook();
        }
        if ($this->down) {
            throw new KeyValueStoreException("Redis connection failed: {$name} refused");
        }
        $this->ops[$name] = ($this->ops[$name] ?? 0) + 1;
    }

    private function expiry(int $ttlSeconds): ?int
    {
        return $ttlSeconds > 0 ? ($this->clock)() + $ttlSeconds : null;
    }

    private function purge(string $key): void
    {
        $expiresAt = $this->data[$key]['expiresAt'] ?? null;
        if ($expiresAt !== null && $expiresAt <= ($this->clock)()) {
            unset($this->data[$key]);
        }
    }
}
