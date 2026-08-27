<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

/**
 * Result of a successful ReturnPhotoStorageService::store() call
 * (M3.2.X.18-B).
 *
 * Carries the storage_path + normalized metadata that the caller
 * uses to construct the OrderReturnRequestPhoto entity. Pure
 * value object, immutable, no logic.
 *
 * The originalFilename may be null if the client didn't send one
 * (or sent an empty/whitespace-only filename, which the service
 * normalizes to null).
 */
final class StoredPhoto
{
    public function __construct(
        public readonly string $storagePath,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly ?string $originalFilename,
    ) {
    }
}
