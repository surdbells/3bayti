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

    /**
     * data: URL matcher. Tolerant of extra mime parameters (e.g.
     * `;charset=utf-8`) some legacy rows carry; the `s` flag lets the base64
     * payload span newlines (MIME-chunked legacy blobs).
     */
    private const DATA_URL_RE = '#^data:([\w/+.-]+)(?:;[\w.=+-]+)*;base64,(.+)$#s';

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
        if (!preg_match(self::DATA_URL_RE, $dataUrl, $m)) {
            throw new \InvalidArgumentException('Document must be a base64 data URL.');
        }
        $mime = strtolower($m[1]);
        if (!isset(self::MIME_EXT[$mime])) {
            throw new \InvalidArgumentException("Unsupported document type '{$mime}'.");
        }

        $bytes = $this->decodeBase64($m[2]);
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
     * If $value is an inline base64 blob (a data URL, or raw un-prefixed base64
     * as some legacy rows were migrated), store it as a private file and return
     * the new storage path. Returns null when there's nothing to localize, the
     * value is empty, already a storage path, or a remote URL.
     *
     * Powers compliance:localize-documents, which moves legacy/base64 document
     * bytes out of the vendor row and into private storage.
     */
    public function localizeInline(int $vendorId, string $type, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return null; // external reference, not an inline blob
        }
        if (str_starts_with($value, 'data:')) {
            return $this->store($vendorId, $type, $value);
        }
        // Raw (un-prefixed) base64 → wrap as a data URL so store() accepts it.
        // rawBase64() returns null for real storage paths (they contain '/'/'-'
        // /'.' outside the base64 alphabet), so those are left untouched.
        $raw = $this->rawBase64($value);
        if ($raw === null) {
            return null;
        }
        $dataUrl = 'data:' . $raw['mime'] . ';base64,' . base64_encode($raw['bytes']);
        return $this->store($vendorId, $type, $dataUrl);
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
        // Back-compat: a row written before hardening already holds a data URL,
        // or a legacy migrated row holds a full URL, both are usable verbatim.
        if (str_starts_with($path, 'data:')
            || str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')) {
            return $path;
        }
        $bytes = $this->storage->read($path);
        if ($bytes !== null) {
            $mime = $this->mimeForPath($path);
            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }
        // Legacy fallback: a migrated row may hold raw base64 image data with no
        // data: prefix. Wrap it as a data URL so it displays.
        $raw = $this->rawBase64($path);
        if ($raw !== null) {
            return 'data:' . $raw['mime'] . ';base64,' . base64_encode($raw['bytes']);
        }
        return null;
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
            if (!preg_match(self::DATA_URL_RE, $path, $m)) {
                return null;
            }
            $bytes = $this->decodeBase64($m[2]);
            if ($bytes === false || $bytes === '') {
                return null;
            }
            return ['bytes' => $bytes, 'mime' => strtolower($m[1])];
        }
        $bytes = $this->storage->read($path);
        if ($bytes !== null) {
            return ['bytes' => $bytes, 'mime' => $this->mimeForPath($path)];
        }
        // Legacy fallback: raw base64 image data migrated without a data: prefix.
        return $this->rawBase64($path);
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

    /**
     * Interpret a value as raw (un-prefixed) base64 image/PDF data, the shape
     * some legacy rows were migrated in. Returns ['bytes','mime'] or null when
     * the value isn't plausibly base64. Real storage paths are rejected because
     * they contain '-'/'.'/':' which aren't in the base64 alphabet.
     *
     * @return array{bytes: string, mime: string}|null
     */
    private function rawBase64(string $value): ?array
    {
        // Legacy blobs may carry MIME-style line breaks; normalise first so the
        // base64 alphabet check + decode don't reject an otherwise-valid image.
        $clean = preg_replace('/\s+/', '', $value) ?? $value;
        if (strlen($clean) < 100 || preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $clean) !== 1) {
            return null;
        }
        $bytes = base64_decode($clean, true);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $mime = $this->sniffMime($bytes);
        return $mime !== null ? ['bytes' => $bytes, 'mime' => $mime] : null;
    }

    /**
     * base64_decode that tolerates whitespace/newlines in legacy payloads -
     * strict mode rejects them, which broke serving MIME-chunked legacy blobs
     * imported from the legacy DB.
     */
    private function decodeBase64(string $b64): string|false
    {
        $clean = preg_replace('/\s+/', '', $b64) ?? $b64;
        return base64_decode($clean, true);
    }

    /** Detect a document mime from magic bytes; null if unrecognised. */
    private function sniffMime(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\x0D\x0A\x1A\x0A")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, '%PDF')) {
            return 'application/pdf';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return null;
    }

    private function token(): string
    {
        return bin2hex(random_bytes(8));
    }
}
