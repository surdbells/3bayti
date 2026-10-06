<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Setting;

use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStoreException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Durable, admin-editable app settings: a small JSON key→value store backed by
 * the app_settings table (source of truth) with a short-lived KeyValueStore
 * (Redis) cache in front, so hot reads — e.g. the OTP router reading the
 * provider order on every send — don't hit Postgres each time.
 *
 * Cache failures degrade to the DB (never block a read/write); a negative
 * cache (sentinel) keeps "key not set yet" from hitting the DB on every read.
 */
final class SettingsService
{
    private const CACHE_PREFIX = 'settings:';
    private const CACHE_TTL = 300;
    /** Cached marker for "this key is known to be absent". */
    private const NULL_SENTINEL = "\0settings-null\0";

    private LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KeyValueStore $cache,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * The stored JSON value for $key, or null when unset.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $raw = $this->cacheGet($key);
        if ($raw === self::NULL_SENTINEL) {
            return null;
        }
        if ($raw !== null) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
            // Corrupt cache entry — fall through to the DB.
        }

        $setting = $this->repo()->findByKey($key);
        $value = $setting?->getValue();
        $this->cacheSet($key, $value);

        return $value;
    }

    /**
     * Upsert the JSON value for $key + refresh the cache.
     *
     * @param array<string, mixed> $value
     */
    public function set(string $key, array $value): void
    {
        $repo = $this->repo();
        $setting = $repo->findByKey($key);
        if ($setting === null) {
            $setting = new AppSetting($key, $value);
        } else {
            $setting->setValue($value);
        }
        $repo->save($setting);
        $this->cacheSet($key, $value);
    }

    private function cacheGet(string $key): ?string
    {
        try {
            return $this->cache->get(self::CACHE_PREFIX . $key);
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('settings.cache_get_failed', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** @param array<string, mixed>|null $value */
    private function cacheSet(string $key, ?array $value): void
    {
        $encoded = $value === null ? self::NULL_SENTINEL : json_encode($value);
        if ($encoded === false) {
            return;
        }
        try {
            $this->cache->set(self::CACHE_PREFIX . $key, $encoded, self::CACHE_TTL);
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('settings.cache_set_failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    private function repo(): AppSettingRepository
    {
        /** @var AppSettingRepository $repo */
        $repo = $this->em->getRepository(AppSetting::class);
        return $repo;
    }
}
