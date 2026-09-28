<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Ota;

use Bayti\Api\Domain\Media\ImageStorageService;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Visibility;

/**
 * Stores OTA bundle .zips on the application's own server, the shared uploads
 * volume at apps/api/var/uploads/ota/<platform>/<version>.zip, which Apache
 * already serves publicly under /uploads/ (no extra web-server config). The
 * @capgo/capacitor-updater plugin then downloads the .zip statically from that
 * URL, so bundle downloads never stream through PHP.
 *
 * Reuses ImageStorageService::publicUrl to build the download URL from
 * UPLOADS_PUBLIC_URL (falling back to APP_URL + /uploads).
 */
final class OtaBundleStorageService
{
    public function __construct(
        private readonly FilesystemOperator $filesystem,
    ) {
    }

    /**
     * Persist the bundle bytes and return where it lives + its integrity hash.
     *
     * @return array{path: string, url: string, checksum: string}
     */
    public function store(string $bytes, string $platform, string $version): array
    {
        $path = $this->pathFor($platform, $version);
        $this->filesystem->write($path, $bytes, ['visibility' => Visibility::PUBLIC]);

        return [
            'path' => $path,
            'url' => ImageStorageService::publicUrl($path),
            'checksum' => hash('sha256', $bytes),
        ];
    }

    /**
     * Remove the file behind a bundle URL, if we host it (a no-op for external
     * URLs or a file that is already gone). Resolving by URL rather than by
     * platform/version matters for rollback rows, which point at their source
     * bundle's file.
     */
    public function deleteUrl(string $url): void
    {
        $path = ImageStorageService::storagePathFromUrl($url);
        if ($path !== null && $this->filesystem->fileExists($path)) {
            $this->filesystem->delete($path);
        }
    }

    /**
     * Whether the file behind a bundle URL can still be downloaded. URLs we
     * don't host (a CDN registered via `ota:publish`) are assumed available;
     * a URL under our uploads root must still exist on disk.
     */
    public function isAvailable(string $url): bool
    {
        $path = ImageStorageService::storagePathFromUrl($url);
        if ($path === null) {
            return true;
        }

        return $this->filesystem->fileExists($path);
    }

    /**
     * Deterministic storage path (relative to the uploads root). Segments are
     * sanitised defensively even though platform/version are validated upstream.
     */
    public function pathFor(string $platform, string $version): string
    {
        $safePlatform = preg_replace('/[^a-z]/', '', strtolower($platform)) ?: 'unknown';
        $safeVersion = preg_replace('/[^0-9A-Za-z.\-]/', '_', $version) ?: 'unknown';

        return "ota/{$safePlatform}/{$safeVersion}.zip";
    }
}
