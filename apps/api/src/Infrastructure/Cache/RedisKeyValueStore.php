<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Cache;

use Redis;
use RedisException;
use Throwable;

/**
 * Redis-backed implementation of KeyValueStore using the phpredis
 * extension (pecl-redis).
 *
 * Why phpredis not predis
 * -----------------------
 * phpredis is a C extension — installed server-side, used directly.
 * Predis is a pure PHP library installed via composer. We use phpredis
 * because:
 *   - Already installed on our production server (M1.6.1.A prep)
 *   - 5-10x faster on hot paths (rate-limit checks happen on every
 *     auth attempt)
 *   - Native pconnect() for true persistent connections per FPM worker
 *
 * Connection management
 * ---------------------
 * Uses Redis::pconnect() — persistent connection per FPM worker.
 * The first request a worker handles opens the connection; subsequent
 * requests in the same worker reuse it. PHP-FPM's worker recycle
 * (pm.max_requests) eventually closes them.
 *
 * Why not connect() (non-persistent): every request would do a TCP
 * handshake to Redis. ~1ms each. At 100rps that's 100ms/sec of pure
 * connection overhead.
 *
 * Connection is established lazily on first operation, not in the
 * constructor — we don't want DI container instantiation to fail
 * if Redis is briefly down.
 *
 * Failure handling
 * ----------------
 * phpredis throws RedisException on any operational failure
 * (connection refused, auth failed, network timeout, command error).
 * We wrap those in our own KeyValueStoreException so callers don't
 * have a phpredis-specific dependency.
 *
 * The interface contract is "throw on backend failure." Callers
 * decide whether to fail open or fail closed. We don't make that
 * decision here.
 */
final class RedisKeyValueStore implements KeyValueStore
{
    private ?Redis $client = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly ?string $password,
        private readonly int $database = 0,
        private readonly float $connectTimeoutSeconds = 1.5,
        private readonly float $readTimeoutSeconds = 1.0,
    ) {
    }

    public function get(string $key): ?string
    {
        try {
            $value = $this->client()->get($key);
            // phpredis returns false on miss, the value (string) on hit.
            // We normalize to null on miss for the interface contract.
            return $value === false ? null : (string) $value;
        } catch (RedisException $e) {
            throw new KeyValueStoreException("Redis GET failed: {$e->getMessage()}", $e);
        }
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): void
    {
        try {
            if ($ttlSeconds > 0) {
                // SETEX: atomic SET-with-expiry.
                $ok = $this->client()->setex($key, $ttlSeconds, $value);
            } else {
                $ok = $this->client()->set($key, $value);
            }

            if ($ok !== true) {
                throw new KeyValueStoreException("Redis SET returned non-true for key '$key'");
            }
        } catch (RedisException $e) {
            throw new KeyValueStoreException("Redis SET failed: {$e->getMessage()}", $e);
        }
    }

    public function incr(string $key): int
    {
        try {
            // Redis INCR is atomic. Creates the key at 0 if absent.
            $result = $this->client()->incr($key);
            // phpredis returns int on success, false on failure (which
            // it converts to RedisException in non-pipeline mode, but
            // be defensive).
            if ($result === false) {
                throw new KeyValueStoreException("Redis INCR returned false for key '$key'");
            }
            return (int) $result;
        } catch (RedisException $e) {
            throw new KeyValueStoreException("Redis INCR failed: {$e->getMessage()}", $e);
        }
    }

    public function setIfAbsent(string $key, string $value, int $ttlSeconds): bool
    {
        try {
            // SET key value NX [EX ttl] — atomic set-if-not-exists.
            // phpredis takes the options as an associative array; 'nx'
            // makes it conditional, 'ex' attaches the expiry in the same
            // atomic command (no SET-then-EXPIRE race / leaked-lock gap).
            // On success Redis returns true; when the key already exists
            // the NX condition fails and Redis returns false — that false
            // is the "someone else owns it" signal, NOT an error.
            $options = $ttlSeconds > 0
                ? ['nx', 'ex' => $ttlSeconds]
                : ['nx'];

            $result = $this->client()->set($key, $value, $options);

            return $result === true;
        } catch (RedisException $e) {
            throw new KeyValueStoreException("Redis SET NX failed: {$e->getMessage()}", $e);
        }
    }

    public function expire(string $key, int $ttlSeconds): void
    {
        try {
            // EXPIRE returns true if TTL was set, false if key doesn't exist.
            // We accept both — the "key doesn't exist" case is a no-op
            // by design (interface contract).
            $this->client()->expire($key, $ttlSeconds);
        } catch (RedisException $e) {
            throw new KeyValueStoreException("Redis EXPIRE failed: {$e->getMessage()}", $e);
        }
    }

    public function delete(string $key): void
    {
        try {
            // DEL returns count of deleted keys. We don't care if it
            // was 0 (key didn't exist) or 1 (deleted) — both satisfy
            // "ensure key is gone."
            $this->client()->del($key);
        } catch (RedisException $e) {
            throw new KeyValueStoreException("Redis DEL failed: {$e->getMessage()}", $e);
        }
    }

    public function ping(): bool
    {
        try {
            // PING returns "+PONG" on phpredis < 5.0 and bool on newer.
            // Both truthy outcomes mean Redis is alive.
            $result = $this->client()->ping();
            return $result === true || $result === '+PONG' || $result === 'PONG';
        } catch (Throwable $e) {
            // ping() is the explicit "does not throw" method per the
            // interface — used by /v3/health/ready. Swallow any error
            // and report unhealthy.
            return false;
        }
    }

    /**
     * Lazy-open the connection on first use. Subsequent calls reuse
     * it. The phpredis extension handles pconnect() reuse across
     * requests within the same FPM worker.
     */
    private function client(): Redis
    {
        if ($this->client !== null) {
            return $this->client;
        }

        try {
            $client = new Redis();

            // pconnect = persistent connection. Survives across
            // request boundaries within the same FPM worker.
            $ok = $client->pconnect(
                $this->host,
                $this->port,
                $this->connectTimeoutSeconds,
                // persistent_id: empty string = one connection per
                // worker (good). A unique-per-key string would create
                // multiple persistent connections; not what we want.
                '',
                0,
                $this->readTimeoutSeconds,
            );

            if (!$ok) {
                throw new KeyValueStoreException(
                    "Redis pconnect to {$this->host}:{$this->port} failed",
                );
            }

            // Authenticate if a password is set. Redis accepts AUTH on
            // unauthenticated connections too (no-op), so this is
            // safe even when password is null... but only do it when
            // we actually have one to avoid sending an empty string.
            if ($this->password !== null && $this->password !== '') {
                if (!$client->auth($this->password)) {
                    throw new KeyValueStoreException(
                        'Redis AUTH failed (wrong password?)',
                    );
                }
            }

            // Select database. 0 is fine for us; higher numbers let
            // multiple apps share one Redis without key collisions.
            if ($this->database !== 0) {
                $client->select($this->database);
            }

            $this->client = $client;
            return $client;
        } catch (RedisException $e) {
            throw new KeyValueStoreException(
                "Redis connection failed: {$e->getMessage()}",
                $e,
            );
        }
    }
}
