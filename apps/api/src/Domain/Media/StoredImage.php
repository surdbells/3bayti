<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Media;

/**
 * Value object returned by ImageStorageService after a successful write.
 * Carries the Flysystem-relative storage path and metadata.
 * Callers use ImageStorageService::publicUrl($storagePath) to build
 * the full public URL they store in the database.
 */
final class StoredImage
{
    public function __construct(
        /** Flysystem-relative path, e.g. "products/my-store/01J....jpg" */
        public readonly string $storagePath,
        public readonly string $mimeType,
        public readonly int    $sizeBytes,
    ) {}

    /** Convenience: resolve to public URL. */
    public function publicUrl(): string
    {
        return ImageStorageService::publicUrl($this->storagePath);
    }
}
