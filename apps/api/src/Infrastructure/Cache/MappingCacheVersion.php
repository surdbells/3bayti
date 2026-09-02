<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Cache;

/**
 * Derives a per-deploy version tag used to namespace Doctrine's metadata +
 * query cache (see config/doctrine.php).
 *
 * Why this exists — incident PHP-24 / PHP-26 (2026-09-02)
 * ------------------------------------------------------
 * Doctrine's file cache stores serialized ClassMetadata and parsed DQL. It
 * does NOT invalidate when an entity's mapping changes (unlike the proxy
 * classes, which self-heal via AUTOGENERATE_FILE_NOT_EXISTS_OR_CHANGED). A
 * deploy that added Order::$deletedAt while the stale metadata lingered made
 * every DQL referencing the new field fail with a Doctrine semantical error
 * ("Class Order has no field or association named deletedAt") — the entire
 * customer order list 500'd for all users until the cache was cleared by hand.
 *
 * Namespacing the cache by a value that changes on every deploy makes that
 * impossible: a redeploy rotates to a fresh (empty) namespace, so stale
 * metadata can never be read. Old namespaces are orphaned and pruned by
 * bin/migrate.php on the next deploy.
 *
 * Resolution order:
 *   1. An explicit release identifier from the environment (APP_RELEASE,
 *      RELEASE_ID, GIT_SHA, GIT_COMMIT) — cheapest + exact when the deploy
 *      sets one.
 *   2. A fingerprint of the domain source tree (path + mtime + size of every
 *      *.php under src/Domain, where all mapped entities live). Any deploy
 *      that changes a mapping changes the fingerprint — so the guard holds
 *      even for a bare file-sync deploy that sets no release env var.
 *
 * The scan is ~155 stat() calls (sub-millisecond) and runs once per EM build
 * (once per request under php-fpm), so it is cheap enough to compute inline.
 */
final class MappingCacheVersion
{
    /** Env vars checked, in order, for an explicit release id. */
    private const RELEASE_ENV_KEYS = ['APP_RELEASE', 'RELEASE_ID', 'GIT_SHA', 'GIT_COMMIT'];

    /**
     * @param string $rootPath the API application root (the dir containing src/).
     * @return string a short, filesystem-safe tag ([A-Za-z0-9], 1-32 chars).
     */
    public static function compute(string $rootPath): string
    {
        return self::fromEnv() ?? self::fingerprint($rootPath . '/src/Domain');
    }

    private static function fromEnv(): ?string
    {
        foreach (self::RELEASE_ENV_KEYS as $key) {
            $raw = $_ENV[$key] ?? getenv($key);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            // Keep only filesystem-safe chars so it's a valid cache namespace.
            $clean = substr((string) preg_replace('/[^A-Za-z0-9]/', '', $raw), 0, 32);
            if ($clean !== '') {
                return $clean;
            }
        }
        return null;
    }

    private static function fingerprint(string $domainDir): string
    {
        if (!is_dir($domainDir)) {
            // No source tree to fingerprint (unexpected) — a stable constant is
            // still safe: it just means the cache doesn't auto-rotate, matching
            // the historical behaviour before this guard existed.
            return 'base';
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($domainDir, \FilesystemIterator::SKIP_DOTS),
        );

        // Collect (path:mtime:size) per PHP file, then sort so the fingerprint
        // is independent of filesystem iteration order (which is not stable).
        $entries = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $entries[] = $file->getPathname() . ':' . $file->getMTime() . ':' . $file->getSize();
            }
        }
        sort($entries, SORT_STRING);

        return substr(hash('sha256', implode("\n", $entries)), 0, 16);
    }
}
