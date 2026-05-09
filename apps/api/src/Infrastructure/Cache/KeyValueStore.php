<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Cache;

/**
 * Minimal key-value store abstraction.
 *
 * Backed by Redis in production (RedisKeyValueStore) and an in-memory
 * array in tests / local dev (InMemoryKeyValueStore). The interface
 * stays deliberately small — when more operations are needed, we
 * extend; we don't pre-empt.
 *
 * What this is for
 * ----------------
 * State that needs to be SHARED across PHP-FPM workers. The classic
 * example: rate-limit counters. With per-worker in-memory storage,
 * a worker pool of N silently divides every limit by N because each
 * worker has its own counter. The KeyValueStore is the fix.
 *
 * What this is NOT for
 * --------------------
 *   - Anything that needs durability across Redis restarts. Use
 *     Postgres for that. Redis CAN persist (RDB/AOF) but treat it
 *     as cache — restarts may lose data.
 *   - Anything large. Values should be small (a counter, a token,
 *     a timestamp). For caching query results or fragments, a
 *     dedicated cache abstraction is better.
 *   - Complex data structures. We have get/set/incr/expire/delete.
 *     Hash/set/sorted-set ops belong in a richer Redis abstraction
 *     when the need arises.
 *
 * Failure semantics
 * -----------------
 * Implementations MAY throw on infrastructure failure (Redis down,
 * network timeout, etc.). Callers are responsible for catching
 * and degrading gracefully — the canonical pattern is:
 *
 *   try {
 *       $count = $store->incr($key);
 *       if ($count > $limit) { throw new RateLimitExceeded(); }
 *   } catch (KeyValueStoreException $e) {
 *       $this->logger->error('cache backend failed', [...]);
 *       // Fail open: allow the request, log for ops to investigate.
 *   }
 *
 * The implementations DO NOT silently swallow errors — that hides
 * outages. They throw KeyValueStoreException; callers decide.
 */
interface KeyValueStore
{
    /**
     * Get the value at $key, or null if not present (or expired).
     *
     * @throws KeyValueStoreException on backend failure
     */
    public function get(string $key): ?string;

    /**
     * Set $key to $value. If $ttlSeconds is positive, the key
     * auto-expires after that many seconds. If 0 or negative, no
     * expiration (the key persists until explicitly deleted or
     * evicted by maxmemory policy).
     *
     * @throws KeyValueStoreException on backend failure
     */
    public function set(string $key, string $value, int $ttlSeconds = 0): void;

    /**
     * Atomically increment $key (creating it at 0 if absent) and
     * return the NEW value.
     *
     * Combine with expire() to build sliding-window rate limits:
     *   $count = $store->incr("rl:$ip");
     *   if ($count === 1) { $store->expire("rl:$ip", 60); }  // first hit sets TTL
     *
     * Why return new value, not old: the caller almost always wants
     * "is this the Nth hit?" not "what was the previous count?"
     *
     * @throws KeyValueStoreException on backend failure
     */
    public function incr(string $key): int;

    /**
     * Set/refresh the TTL on an existing key. No-op if the key
     * doesn't exist (we don't error here — the typical use case is
     * "set TTL if first hit", and getting a key not found means
     * something else cleaned up).
     *
     * @throws KeyValueStoreException on backend failure
     */
    public function expire(string $key, int $ttlSeconds): void;

    /**
     * Delete $key. No-op if not present.
     *
     * @throws KeyValueStoreException on backend failure
     */
    public function delete(string $key): void;

    /**
     * Check whether the backend is reachable. Used by /v3/health/ready
     * to surface Redis health.
     *
     * Returns true on success, false on any failure. Does NOT throw —
     * that's the explicit difference from the other methods.
     */
    public function ping(): bool;
}
