<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\TryOn;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Media\ImageStorageService;

/**
 * Whether a product's garment image can be sent to the virtual try-on image
 * model. This is the ONE place that rule lives: the admin try-on screens
 * (list, toggle, bulk), the public product serializer (which hides the
 * "Try it on" CTA for ineligible products) AND the ai:process-tryon-jobs worker
 * ({@see TryOnJobProcessor}) all ask this class, so the admin UI can never mark
 * a product eligible that the worker would then reject.
 *
 * The garment is the product's PRIMARY image (primary_image_url). It must be:
 *   - present                                  else REASON_NO_IMAGE
 *   - one of OUR current stored uploads, i.e. under UPLOADS_PUBLIC_URL (the
 *     worker reads the bytes from {@see ImageStorageService}; a hot-linked
 *     third-party URL cannot be read, and neither can the LEGACY-hosted
 *     images that migrated products still point at,
 *     https://api.3bayti.ae/vendors/products/..., until they are re-uploaded)
 *                                              else REASON_EXTERNAL_IMAGE
 *   - a JPEG, PNG or WebP by file extension (the image model rejects anything
 *     else, notably GIF)                       else REASON_UNSUPPORTED_FORMAT
 *
 * Pure string checks only (no file I/O), cheap enough for every row of a list.
 * The worker still reads the file and fails on a missing one.
 */
final class TryOnGarmentEligibility
{
    public const REASON_NO_IMAGE = 'no_image';
    public const REASON_EXTERNAL_IMAGE = 'external_image';
    public const REASON_UNSUPPORTED_FORMAT = 'unsupported_format';

    /** Accepted garment file extension → MIME type sent to the image model. */
    public const EXTENSION_MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    /** The accepted formats, as reported to admins. */
    public const ELIGIBLE_FORMATS = ['jpeg', 'png', 'webp'];

    /** Null when the product's garment image is eligible, else a REASON_* code. */
    public function reasonFor(Product $product): ?string
    {
        return $this->reasonForUrl($product->getPrimaryImageUrl());
    }

    /** Null when an image at $url is eligible, else a REASON_* code. */
    public function reasonForUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return self::REASON_NO_IMAGE;
        }
        $path = $this->storagePathForUrl($url);
        if ($path === null) {
            return self::REASON_EXTERNAL_IMAGE;
        }
        if ($this->mimeForPath($path) === null) {
            return self::REASON_UNSUPPORTED_FORMAT;
        }
        return null;
    }

    public function isEligible(Product $product): bool
    {
        return $this->reasonFor($product) === null;
    }

    /**
     * @return array{eligible: bool, reason: string|null}
     */
    public function describe(Product $product): array
    {
        $reason = $this->reasonFor($product);

        return ['eligible' => $reason === null, 'reason' => $reason];
    }

    /**
     * Storage path of the product's garment image (query string / fragment
     * removed), or null when it has none or it is not one of our uploads.
     */
    public function garmentStoragePath(Product $product): ?string
    {
        return $this->storagePathForUrl($product->getPrimaryImageUrl());
    }

    /**
     * Storage path for one of our upload URLs (query string / fragment
     * removed), or null for an empty or foreign URL.
     */
    public function storagePathForUrl(?string $url): ?string
    {
        $path = ImageStorageService::storagePathFromUrl($url);
        if ($path === null) {
            return null;
        }
        $path = self::stripQuery($path);

        return $path === '' ? null : $path;
    }

    /**
     * MIME type for an accepted garment path or URL (extension match,
     * case-insensitive, query string / fragment ignored), or null when the
     * format is not accepted (GIF, unknown or missing extension).
     */
    public function mimeForPath(string $path): ?string
    {
        $extension = strtolower(pathinfo(self::stripQuery($path), PATHINFO_EXTENSION));

        return self::EXTENSION_MIMES[$extension] ?? null;
    }

    /** Human-readable explanation of a REASON_* code, for admin error messages. */
    public static function reasonMessage(string $reason): string
    {
        return match ($reason) {
            self::REASON_NO_IMAGE => 'This product has no primary image, so it cannot be enabled for try-on.',
            self::REASON_EXTERNAL_IMAGE => 'This product\'s primary image is not in 3bayti\'s current image storage (legacy or external link), so try-on cannot read it. Re-upload the primary image first.',
            self::REASON_UNSUPPORTED_FORMAT => 'This product\'s primary image format is not supported for try-on. Use a JPEG, PNG or WebP image.',
            default => 'This product\'s primary image cannot be used for try-on.',
        };
    }

    private static function stripQuery(string $value): string
    {
        $cut = strcspn($value, '?#');

        return substr($value, 0, $cut);
    }
}
