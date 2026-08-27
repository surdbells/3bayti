<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Media;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Stores product and vendor images through Flysystem.
 *
 * Path scheme
 * ===========
 *   products/{vendor-slug}/{ULID}.{ext}  , product images
 *   vendors/{vendor-slug}/logo.{ext}     , vendor logo
 *   vendors/{vendor-slug}/cover.{ext}    , vendor cover image
 *
 * The vendor-slug segment keeps vendor images isolated. ULID
 * filenames on product images give time-sortable, collision-safe
 * identifiers. Logo/cover use a stable name (logo.jpg) so re-
 * uploading replaces the file in-place (no orphan accumulation).
 *
 * Public URL construction
 * =======================
 * The local adapter stores files in apps/api/var/uploads/. Apache
 * serves that directory under /uploads/ via an Alias directive.
 * So storagePath "products/my-store/01J...jpg" becomes
 * https://api-v3.3bayti.ae/uploads/products/my-store/01J...jpg
 *
 * The UPLOADS_PUBLIC_URL env var holds the base
 * (e.g. "https://api-v3.3bayti.ae/uploads") so callers can
 * construct the full URL without knowing the server origin.
 * Cloudflare image transforms are applied by the front-end -
 * the API always stores and returns the canonical origin URL.
 *
 * Adapter swap
 * ============
 * Swapping to R2 in the future is a single change in config/di.php -
 * the AwsS3V3Adapter points at the same logical paths. The UPLOADS_PUBLIC_URL
 * then becomes the R2 public bucket URL (e.g. https://cdn.3bayti.ae).
 * No consumer-side changes needed.
 */
final class ImageStorageService
{
    /** Accepted mime types → file extensions. */
    public const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /** 10 MB upload ceiling. */
    public const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly FilesystemOperator $filesystem,
    ) {}

    /**
     * Store a product image for the given vendor.
     *
     * @throws \InvalidArgumentException on invalid mime / size / upload error.
     * @throws FilesystemException on backing-store failure.
     */
    public function storeProductImage(
        UploadedFileInterface $upload,
        string $vendorSlug,
    ): StoredImage {
        [$mime, $ext] = $this->validate($upload);
        $ulid = $this->ulid();
        $path = "products/{$vendorSlug}/{$ulid}.{$ext}";
        $this->write($upload, $path);
        return new StoredImage($path, $mime, (int) $upload->getSize());
    }

    /**
     * Store a vendor logo. Stable path, re-upload overwrites.
     *
     * @throws \InvalidArgumentException on invalid mime / size / upload error.
     * @throws FilesystemException on backing-store failure.
     */
    public function storeVendorLogo(
        UploadedFileInterface $upload,
        string $vendorSlug,
    ): StoredImage {
        [$mime, $ext] = $this->validate($upload);
        $path = "vendors/{$vendorSlug}/logo.{$ext}";
        $this->write($upload, $path);
        return new StoredImage($path, $mime, (int) $upload->getSize());
    }

    /**
     * Store a vendor cover image. Stable path, re-upload overwrites.
     *
     * @throws \InvalidArgumentException on invalid mime / size / upload error.
     * @throws FilesystemException on backing-store failure.
     */
    public function storeVendorCover(
        UploadedFileInterface $upload,
        string $vendorSlug,
    ): StoredImage {
        [$mime, $ext] = $this->validate($upload);
        $path = "vendors/{$vendorSlug}/cover.{$ext}";
        $this->write($upload, $path);
        return new StoredImage($path, $mime, (int) $upload->getSize());
    }

    /**
     * Store a gift-card recipient photo for the given buyer (luxury theme).
     *
     * Path: gift-cards/{userId}/{ULID}.{ext}. ULID filenames are
     * time-sortable, collision-safe, and unguessable.
     *
     * Visibility is PUBLIC by design: the gift-card *recipient*, who is
     * not the uploader, renders this photo on the card via a plain
     * <img> tag (on the redeem / detail / my-cards views), so an
     * auth-gated or private URL would not display for them. The
     * unguessable ULID path is the privacy lever, mirroring how product
     * images are served.
     *
     * @throws \InvalidArgumentException on invalid mime / size / upload error.
     * @throws FilesystemException on backing-store failure.
     */
    public function storeGiftCardPhoto(
        UploadedFileInterface $upload,
        int $userId,
    ): StoredImage {
        [$mime, $ext] = $this->validate($upload);
        $ulid = $this->ulid();
        $path = "gift-cards/{$userId}/{$ulid}.{$ext}";
        $this->write($upload, $path);
        return new StoredImage($path, $mime, (int) $upload->getSize());
    }

    /**
     * Store a raw decoded image (from base64 blob, used by the
     * image migration script for legacy vendor logos).
     *
     * @throws FilesystemException on backing-store failure.
     */
    public function storeRaw(string $bytes, string $storagePath): StoredImage
    {
        $tmp = tmpfile();
        if ($tmp === false) {
            throw new \RuntimeException('Failed to allocate temp file for raw image write.');
        }
        try {
            fwrite($tmp, $bytes);
            rewind($tmp);
            $this->writeStreamPublic($storagePath, $tmp);
        } finally {
            fclose($tmp);
        }
        $mime = $this->mimeFromExtension(pathinfo($storagePath, PATHINFO_EXTENSION));
        return new StoredImage($storagePath, $mime, strlen($bytes));
    }

    /**
     * Store raw bytes with PRIVATE visibility (not web-reachable). Used for
     * sensitive documents (KYC) that must only be served through
     * authenticated endpoints.
     */
    public function storeRawPrivate(string $bytes, string $storagePath): StoredImage
    {
        $tmp = tmpfile();
        if ($tmp === false) {
            throw new \RuntimeException('Failed to allocate temp file for raw private write.');
        }
        try {
            fwrite($tmp, $bytes);
            rewind($tmp);
            $this->writeStreamPrivate($storagePath, $tmp);
        } finally {
            fclose($tmp);
        }
        $mime = $this->mimeFromExtension(pathinfo($storagePath, PATHINFO_EXTENSION));
        return new StoredImage($storagePath, $mime, strlen($bytes));
    }

    /**
     * Read a stored file's raw bytes. Returns null when the file is absent.
     */
    public function read(string $storagePath): ?string
    {
        try {
            if (!$this->filesystem->fileExists($storagePath)) {
                return null;
            }
            return $this->filesystem->read($storagePath);
        } catch (FilesystemException) {
            return null;
        }
    }

    /**
     * Delete a stored image. Idempotent.
     */
    public function delete(string $storagePath): void
    {
        try {
            $this->filesystem->delete($storagePath);
        } catch (FilesystemException) {
            // Already gone, fine.
        }
    }

    /**
     * Check whether a file exists in the store.
     */
    public function exists(string $storagePath): bool
    {
        try {
            return $this->filesystem->fileExists($storagePath);
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * Resolve the public URL for a stored image path.
     * Reads UPLOADS_PUBLIC_URL from env, falling back to APP_URL + /uploads.
     */
    public static function publicUrl(string $storagePath): string
    {
        $base = rtrim($_ENV['UPLOADS_PUBLIC_URL'] ?? '', '/');
        if ($base === '') {
            $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/');
            $base   = $appUrl . '/uploads';
        }
        return $base . '/' . ltrim($storagePath, '/');
    }

    /**
     * The inverse of publicUrl(): given a public image URL, return the
     * storage path relative to the uploads root, or null when the URL is
     * not one we host (external/legacy URLs must never be deleted).
     */
    public static function storagePathFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $base = rtrim($_ENV['UPLOADS_PUBLIC_URL'] ?? '', '/');
        if ($base === '') {
            $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/');
            $base   = $appUrl . '/uploads';
        }
        $prefix = $base . '/';
        if (!str_starts_with($url, $prefix)) {
            return null;
        }
        $path = ltrim(substr($url, strlen($prefix)), '/');
        return $path === '' ? null : $path;
    }

    // ── private helpers ──────────────────────────────────────────────

    /**
     * Validate mime type, size, and PHP upload errors.
     *
     * @return array{string, string} [mime, extension]
     */
    private function validate(UploadedFileInterface $upload): array
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(
                "Upload failed (PHP error code {$upload->getError()})."
            );
        }

        $size = $upload->getSize();
        if ($size === null || $size <= 0) {
            throw new \InvalidArgumentException('Upload size could not be determined or is zero.');
        }
        if ($size > self::MAX_SIZE_BYTES) {
            $mb = round(self::MAX_SIZE_BYTES / 1024 / 1024);
            throw new \InvalidArgumentException(
                "File size " . round($size / 1024 / 1024, 1) . "MB exceeds the {$mb}MB limit."
            );
        }

        $mime = strtolower(trim($upload->getClientMediaType() ?? ''));
        if (!isset(self::ALLOWED_MIMES[$mime])) {
            throw new \InvalidArgumentException(
                "Unsupported image type '{$mime}'. Accepted: jpeg, png, webp, gif."
            );
        }

        return [$mime, self::ALLOWED_MIMES[$mime]];
    }

    /**
     * Stream an uploaded file into Flysystem.
     */
    private function write(UploadedFileInterface $upload, string $path): void
    {
        $psrStream = $upload->getStream();
        $resource  = null;

        try {
            // Attempt zero-copy detach first.
            $resource = $psrStream->detach();
        } catch (\Throwable) {
            $resource = null;
        }

        if (!is_resource($resource)) {
            // Fallback: copy to a tmpfile.
            $tmp = tmpfile();
            if ($tmp === false) {
                throw new \RuntimeException('Failed to allocate temp file for upload streaming.');
            }
            $psrStream->rewind();
            while (!$psrStream->eof()) {
                fwrite($tmp, $psrStream->read(8192));
            }
            rewind($tmp);
            $resource = $tmp;
        } elseif (stream_get_meta_data($resource)['seekable']) {
            rewind($resource);
        }

        try {
            $this->writeStreamPublic($path, $resource);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * Write a stream with public visibility, forcing a 0022 umask for the
     * duration so any directories Flysystem creates via mkdir() come out
     * 0755 (world-traversable) rather than being clipped by a restrictive
     * server umask (e.g. 0077 → 0700, which makes served uploads 403/404).
     * Flysystem's LocalFilesystemAdapter does NOT chmod directories after
     * mkdir, so the umask is the only lever for directory mode. The file
     * itself is also chmod'd to 0644 by the 'public' visibility option.
     *
     * @param resource $resource
     */
    private function writeStreamPublic(string $path, $resource): void
    {
        $previousUmask = umask(0022);
        try {
            $this->filesystem->writeStream($path, $resource, [
                'visibility' => \League\Flysystem\Visibility::PUBLIC,
            ]);
        } finally {
            umask($previousUmask);
        }
    }

    private function writeStreamPrivate(string $path, $resource): void
    {
        $previousUmask = umask(0077);
        try {
            $this->filesystem->writeStream($path, $resource, [
                'visibility' => \League\Flysystem\Visibility::PRIVATE,
            ]);
        } finally {
            umask($previousUmask);
        }
    }

    private function ulid(): string
    {
        $timeMs   = (int) (microtime(true) * 1000);
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $ts = '';
        for ($i = 9; $i >= 0; $i--) {
            $ts       = $alphabet[$timeMs & 0x1f] . $ts;
            $timeMs >>= 5;
        }
        $rnd = '';
        for ($i = 0; $i < 16; $i++) {
            $rnd .= $alphabet[random_int(0, 31)];
        }
        return $ts . $rnd;
    }

    private function mimeFromExtension(string $ext): string
    {
        $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',  'webp'  => 'image/webp',
                'gif' => 'image/gif'];
        return $map[strtolower($ext)] ?? 'application/octet-stream';
    }
}
