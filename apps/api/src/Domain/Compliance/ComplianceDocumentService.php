<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Compliance;

use Bayti\Api\Domain\Media\ImageStorageService;

/**
 * Stores vendor KYC documents as PRIVATE files (never on the public
 * uploads path) and reads them back as base64 data URLs for display in
 * authenticated responses only. Replaces base64-in-DB: the vendor row now
 * holds only the storage path, so document bytes never bloat row scans and
 * are never web-reachable.
 */
class ComplianceDocumentService
{
    /** Accepted document mime types → file extension. */
    private const MIME_EXT = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/gif'       => 'gif',
        'application/pdf' => 'pdf',
    ];

    /** Cap on the decoded document size (≈9 MB after base64 overhead). */
    private const MAX_BYTES = 9_000_000;

    public function __construct(
        private readonly ImageStorageService $storage,
    ) {
    }

    /**
     * Decode a base64 data URL, store it as a private file, and return the
     * storage path. Throws \InvalidArgumentException on a malformed or
     * unsupported payload.
     */
    public function store(int $vendorId, string $type, string $dataUrl): string
    {
        if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $dataUrl, $m)) {
            throw new \InvalidArgumentException('Document must be a base64 data URL.');
        }
        $mime = strtolower($m[1]);
        if (!isset(self::MIME_EXT[$mime])) {
            throw new \InvalidArgumentException("Unsupported document type '{$mime}'.");
        }

        $bytes = base64_decode($m[2], true);
        if ($bytes === false || $bytes === '') {
            throw new \InvalidArgumentException('Document base64 payload is invalid.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Document is too large.');
        }

        $ext = self::MIME_EXT[$mime];
        $path = sprintf('compliance/vendor-%d/%s-%s.%s', $vendorId, $type, $this->token(), $ext);
        $this->storage->storeRawPrivate($bytes, $path);

        return $path;
    }

    /**
     * Read a stored document path back into a base64 data URL for display.
     * Returns null when the path is null/empty or the file is missing.
     * Tolerates a legacy value that is already a data URL (pre-hardening
     * rows) by returning it verbatim.
     */
    public function readAsDataUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        // Back-compat: a row written before hardening already holds a data URL.
        if (str_starts_with($path, 'data:')) {
            return $path;
        }
        $bytes = $this->storage->read($path);
        if ($bytes === null) {
            return null;
        }
        $mime = $this->mimeForPath($path);
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * Read a stored document for streaming. Returns
     * ['bytes' => string, 'mime' => string] or null when the path is
     * null/empty or the file is missing. Tolerates a legacy data-URL value.
     */
    public function openForDownload(?string $path): ?array
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (str_starts_with($path, 'data:')) {
            if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $path, $m)) {
                return null;
            }
            $bytes = base64_decode($m[2], true);
            if ($bytes === false || $bytes === '') {
                return null;
            }
            return ['bytes' => $bytes, 'mime' => strtolower($m[1])];
        }
        $bytes = $this->storage->read($path);
        if ($bytes === null) {
            return null;
        }
        return ['bytes' => $bytes, 'mime' => $this->mimeForPath($path)];
    }

    /** Delete a stored document file (idempotent; ignores data-URL legacy values). */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '' || str_starts_with($path, 'data:')) {
            return;
        }
        $this->storage->delete($path);
    }

    private function mimeForPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $flip = array_flip(self::MIME_EXT);
        return $flip[$ext] ?? 'application/octet-stream';
    }

    private function token(): string
    {
        return bin2hex(random_bytes(8));
    }
}
