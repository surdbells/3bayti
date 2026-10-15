<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Ota;

use Bayti\Api\Domain\Media\ImageStorageService;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Visibility;

/**
 * Stores OTA bundle .zips on the application's own server, the shared uploads
 * volume at apps/api/var/uploads/ota/<app_id>/<channel>/<platform>/<version>.zip,
 * which Apache already serves publicly under /uploads/ (no extra web-server
 * config). The @capgo/capacitor-updater plugin then downloads the .zip
 * statically from that URL, so bundle downloads never stream through PHP.
 *
 * The path carries every column of uniq_ota_bundle_version (app_id, platform,
 * channel, version), so the same version published to "production" and "beta"
 * (or to two app ids) gets two files. Two guarantees keep one file from ever
 * backing two different uploads: {@see pathFor()} REJECTS a segment it would
 * have to rewrite (so "com.app." can't alias "com.app"), and
 * {@see promote()} refuses to overwrite an existing file (a rollback row may
 * still serve a file whose original row was deleted; re-uploading that
 * version must not replace the bytes under the rollback's checksum). Bundles
 * uploaded before this layout live at the old
 * ota/<platform>/<version>.zip; nothing needs migrating because each row
 * already stores its own download URL and every file operation here
 * (isAvailable/deleteUrl) resolves the file FROM that URL, never by
 * recomputing the path.
 *
 * Writes are two-phase so the upload controller can keep files and rows in
 * step: {@see stage()} writes the bytes to a private, randomly named file
 * under ota/.staging/, {@see promote()} copies it to its public path once the
 * row is about to commit, and {@see discard()} removes either on failure.
 *
 * Reuses ImageStorageService::publicUrl to build the download URL from
 * UPLOADS_PUBLIC_URL (falling back to APP_URL + /uploads).
 */
final class OtaBundleStorageService
{
    private const STAGING_DIR = 'ota/.staging';

    public function __construct(
        private readonly FilesystemOperator $filesystem,
    ) {
    }

    /**
     * Write the uploaded bytes to a private staging file and return its path.
     * The name is random, so concurrent uploads never collide and the file
     * can't be guessed while it briefly sits under the uploads root.
     */
    public function stage(string $bytes): string
    {
        $path = self::STAGING_DIR . '/' . bin2hex(random_bytes(16)) . '.part';
        $this->filesystem->write($path, $bytes, ['visibility' => Visibility::PRIVATE]);

        return $path;
    }

    /**
     * Copy a staged file to its final public path and return that path. Copy
     * (not move) so one staged upload can be promoted to several platforms
     * (`platform=both`); the caller discards the staging file afterwards.
     *
     * Never overwrites: if a file already sits at the target (e.g. it still
     * backs a rollback row), this throws and the caller's cleanup leaves that
     * file alone (it only discards paths promote() returned). Callers should
     * check {@see isTaken()} first to fail with a clear message.
     *
     * @throws \RuntimeException when the target file already exists
     */
    public function promote(string $stagingPath, string $appId, string $channel, string $platform, string $version): string
    {
        $path = $this->pathFor($appId, $channel, $platform, $version);
        if ($this->filesystem->fileExists($path)) {
            throw new \RuntimeException(sprintf('OTA bundle file %s already exists; refusing to overwrite it.', $path));
        }
        $this->filesystem->copy($stagingPath, $path, ['visibility' => Visibility::PUBLIC]);

        return $path;
    }

    /**
     * Whether the file a new upload would be promoted to already exists, e.g.
     * because a rollback row still serves it after its original row was
     * deleted. Such a version must not be re-uploaded.
     */
    public function isTaken(string $appId, string $channel, string $platform, string $version): bool
    {
        return $this->filesystem->fileExists($this->pathFor($appId, $channel, $platform, $version));
    }

    /** The public download URL that {@see promote()} makes available. */
    public function publicUrlFor(string $appId, string $channel, string $platform, string $version): string
    {
        return ImageStorageService::publicUrl($this->pathFor($appId, $channel, $platform, $version));
    }

    /** Best-effort removal of a staged or promoted file (no-op if absent). */
    public function discard(string $path): void
    {
        try {
            if ($this->filesystem->fileExists($path)) {
                $this->filesystem->delete($path);
            }
        } catch (\Throwable) {
            // Cleanup must never mask the error that triggered it.
        }
    }

    /**
     * Remove the file behind a bundle URL, if we host it (a no-op for external
     * URLs or a file that is already gone). Resolving by URL rather than by
     * the row's columns matters for rollback rows, which point at their source
     * bundle's file, and for rows stored under the pre-namespacing layout.
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
     * Deterministic storage path (relative to the uploads root) for a new
     * upload. Every segment is validated upstream; here an unsafe one is
     * REJECTED rather than sanitised, because rewriting could map two
     * different inputs onto the same file (and "." / ".." could climb out of
     * ota/).
     *
     * @throws \InvalidArgumentException on a segment that isn't already safe
     */
    public function pathFor(string $appId, string $channel, string $platform, string $version): string
    {
        if (!in_array($platform, OtaBundle::ALL_PLATFORMS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsafe OTA platform segment "%s".', $platform));
        }

        return sprintf(
            'ota/%s/%s/%s/%s.zip',
            self::segment($appId),
            self::segment($channel),
            $platform,
            self::segment($version),
        );
    }

    /**
     * A path segment is safe when it is non-empty, uses only [0-9A-Za-z._-],
     * and neither starts nor ends with a dot (so never "." / "..", and never a
     * trailing-dot alias of another segment).
     */
    private static function segment(string $value): string
    {
        if (!preg_match('/^[0-9A-Za-z_-](?:[0-9A-Za-z._-]*[0-9A-Za-z_-])?\z/', $value)) {
            throw new \InvalidArgumentException(sprintf('Unsafe OTA path segment "%s".', $value));
        }

        return $value;
    }
}
