<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Storage abstraction for OrderReturnRequest photo evidence
 * (M3.2.X.18-B).
 *
 * Owns the photo upload pipeline:
 *   - Generates deterministic storage paths
 *   - Validates mime type + size from the UploadedFile
 *   - Streams uploaded content into Flysystem (no full-buffer)
 *   - Streams content back out for the auth-gated serve endpoint
 *   - Deletes photos when needed (e.g., test cleanup)
 *
 * Path scheme
 * ===========
 *   return-photos/{YYYY}/{MM}/{return-request-id}/{ulid}.{ext}
 *
 * Year + month sharding caps the inode count per directory at a
 * reasonable level even with high upload volume. ULID filenames
 * are time-sortable (helps when listing within a directory) and
 * collision-safe (no chance of two simultaneous uploads colliding
 * even within the same millisecond).
 *
 * v1 of return-request-id may be 0 when storing photos for a
 * request that hasn't been persisted yet (the create endpoint
 * uploads + persists in the same transaction); we accept 0 as a
 * sentinel and use `pending` as the directory name in that case.
 * The path is captured at OrderReturnRequestPhoto creation time
 * so the in-flight path is permanent, no rename later.
 *
 * Why pre-resolve the path before persisting
 * ===========================================
 * In a transaction:
 *   1. Validate the upload (mime + size from headers)
 *   2. Resolve a storage path (no file I/O yet)
 *   3. Write to Flysystem (file I/O)
 *   4. Persist OrderReturnRequestPhoto with the path
 *   5. Commit
 *
 * If the transaction rolls back AFTER step 3 but before step 5,
 * we have an orphan blob on disk. The operator playbook's
 * orphan-cleanup cron sweeps these out periodically (matches
 * blob paths against the photos table; deletes blobs with no
 * row). This is acceptable for v1, the blobs are small and
 * the cleanup window is configurable.
 *
 * Mime detection
 * ==============
 * We trust the client-declared Content-Type header for the upload
 * mime check (sufficient for evidence photos, a malicious client
 * lying about mime gets a blob stored under .jpg with non-image
 * bytes; admin reviewer sees a broken image and can deny). For
 * stronger detection, future work can read the first bytes and
 * verify the magic number, but v1 is reasonable.
 */
final class ReturnPhotoStorageService
{
    /**
     * Map from canonical mime type to filesystem extension. Used to
     * build the storage path. Public so tests / serializers can
     * compute extensions consistently.
     *
     * @var array<string, string>
     */
    public const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly FilesystemOperator $filesystem,
    ) {
    }

    /**
     * Validate + store a single uploaded file. Returns the
     * resulting storage path. The caller is responsible for
     * constructing the OrderReturnRequestPhoto entity with this
     * path + the size + mime returned in the result object.
     *
     * @param UploadedFileInterface $upload The PSR-7 uploaded file
     *        (from $request->getUploadedFiles()).
     * @param int $returnRequestId The persisted return-request id, or 0
     *        if the parent isn't persisted yet (use 'pending' subdir).
     *
     * @throws \InvalidArgumentException for invalid mime/size or
     *         upload-error states.
     * @throws FilesystemException on backing-store failure.
     */
    public function store(UploadedFileInterface $upload, int $returnRequestId = 0): StoredPhoto
    {
        // 1. PSR-7 upload error states.
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(
                "Upload failed (PHP error code {$upload->getError()})."
            );
        }

        // 2. Size validation. PSR-7 returns null if size is unknown,
        // which we reject to keep the contract simple.
        $size = $upload->getSize();
        if ($size === null) {
            throw new \InvalidArgumentException('Upload size could not be determined.');
        }
        if ($size <= 0) {
            throw new \InvalidArgumentException("Upload size must be > 0; got {$size}.");
        }
        if ($size > OrderReturnRequestPhoto::MAX_PHOTO_SIZE_BYTES) {
            throw new \InvalidArgumentException(
                "Upload size {$size} exceeds max "
                . OrderReturnRequestPhoto::MAX_PHOTO_SIZE_BYTES . '.'
            );
        }

        // 3. Mime type validation. We trust the client-supplied
        // Content-Type header per the docblock rationale; the
        // accepted set is jpeg/png/webp.
        $mime = $upload->getClientMediaType() ?? '';
        $mime = strtolower(trim($mime));
        if (!isset(self::MIME_TO_EXTENSION[$mime])) {
            throw new \InvalidArgumentException(
                "Unsupported upload mime type '{$mime}'. "
                . 'Must be one of: ' . implode(', ', array_keys(self::MIME_TO_EXTENSION)),
            );
        }

        // 4. Resolve the storage path.
        $path = $this->resolvePath($returnRequestId, self::MIME_TO_EXTENSION[$mime]);

        // 5. Stream the upload into Flysystem. PSR-7's
        // getStream() returns a StreamInterface; Flysystem's
        // writeStream takes a resource handle. We bridge via
        // detach() if possible (zero-copy), else fall back to a
        // temporary copy for streams that don't support detach.
        $psrStream = $upload->getStream();
        $resource = $this->resourceFromPsrStream($psrStream);
        try {
            $this->filesystem->writeStream($path, $resource);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }

        $originalName = $upload->getClientFilename();
        return new StoredPhoto(
            storagePath: $path,
            mimeType: $mime,
            sizeBytes: $size,
            originalFilename: $originalName !== null && trim($originalName) !== '' ? $originalName : null,
        );
    }

    /**
     * Open a read stream for an existing photo. Returns a PHP
     * stream resource the caller must close. Used by the photo-
     * serve endpoint to pump the bytes into the HTTP response
     * without buffering the whole blob in memory.
     *
     * @return resource
     * @throws FilesystemException if the path doesn't exist.
     */
    public function readStream(string $storagePath)
    {
        return $this->filesystem->readStream($storagePath);
    }

    /**
     * Delete a stored photo blob. Used by test cleanup and the
     * orphan-cleanup cron. Idempotent, silently no-ops if the
     * path is already gone.
     */
    public function delete(string $storagePath): void
    {
        try {
            $this->filesystem->delete($storagePath);
        } catch (FilesystemException) {
            // Idempotent, already gone is fine.
        }
    }

    /**
     * Check whether a stored blob exists at the given path. Used
     * by the orphan-cleanup cron to identify table rows whose
     * blob has gone missing (cleanup direction is the other way
     *, find blobs with no row, but this is the symmetric primitive).
     */
    public function exists(string $storagePath): bool
    {
        try {
            return $this->filesystem->fileExists($storagePath);
        } catch (FilesystemException) {
            return false;
        }
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    private function resolvePath(int $returnRequestId, string $extension): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $yearMonth = $now->format('Y/m');
        // ULID is time-sortable + collision-safe. Trim implementation
        //, we don't pull a ULID library; generate from time + random.
        $ulid = $this->generateUlid();
        $idSegment = $returnRequestId > 0 ? (string) $returnRequestId : 'pending';
        return "return-photos/{$yearMonth}/{$idSegment}/{$ulid}.{$extension}";
    }

    /**
     * Generate a Crockford-Base32 ULID-like 26-character identifier.
     * Time-sortable (first 10 chars are millisecond timestamp) and
     * cryptographically random in the last 16 chars.
     *
     * We hand-roll instead of pulling a dep because the encoding is
     * straightforward and we only need uniqueness, not strict ULID
     * spec compliance.
     */
    private function generateUlid(): string
    {
        $timeMs = (int) (microtime(true) * 1000);
        $timestamp = '';
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        for ($i = 9; $i >= 0; $i--) {
            $timestamp = $alphabet[$timeMs & 0x1f] . $timestamp;
            $timeMs >>= 5;
        }

        $randomness = '';
        for ($i = 0; $i < 16; $i++) {
            $randomness .= $alphabet[random_int(0, 31)];
        }

        return $timestamp . $randomness;
    }

    /**
     * Bridge a PSR-7 StreamInterface into a PHP resource handle
     * suitable for Flysystem's writeStream. If the PSR stream is
     * already backed by a resource we detach it (zero-copy);
     * otherwise we copy to a tmpfile and return that.
     *
     * @return resource
     */
    private function resourceFromPsrStream(\Psr\Http\Message\StreamInterface $stream)
    {
        // Slim/Guzzle streams expose detach() to release the underlying
        // resource. If we get a resource we use it directly.
        $detached = null;
        try {
            $detached = $stream->detach();
        } catch (\Throwable) {
            $detached = null;
        }
        if (is_resource($detached)) {
            // Rewind in case the caller (or PSR-7 itself) advanced it.
            if (stream_get_meta_data($detached)['seekable']) {
                rewind($detached);
            }
            return $detached;
        }

        // Fallback: copy stream contents to a temp file.
        $tmp = tmpfile();
        if ($tmp === false) {
            throw new \RuntimeException('Failed to allocate temp file for upload streaming.');
        }
        $stream->rewind();
        while (!$stream->eof()) {
            fwrite($tmp, $stream->read(8192));
        }
        rewind($tmp);
        return $tmp;
    }
}
