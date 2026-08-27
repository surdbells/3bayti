<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Compliance;

/**
 * Signs short-lived URLs for serving private KYC documents.
 *
 * SPA clients display these documents in an <img>/anchor, which cannot carry
 * an Authorization header, so instead of running auth middleware on the serve
 * endpoint, we mint a short-lived HMAC signature bound to the vendor id, the
 * document field, and an expiry. The serve endpoint recomputes and verifies
 * it. Signed URLs are produced ONLY inside the already-authenticated
 * compliance GET responses (vendor self + admin review), so issuing one
 * requires passing the normal auth + ownership checks first.
 */
final class ComplianceDocumentSigner
{
    /** Default validity window for a minted URL. */
    private const DEFAULT_TTL = 1800; // 30 minutes, forgiving review window

    /** The document fields that can be served. */
    public const FIELDS = ['front', 'back', 'license_doc'];

    public function __construct(private readonly string $secret)
    {
        if (strlen($this->secret) < 32) {
            throw new \RuntimeException('ComplianceDocumentSigner requires a secret of at least 32 bytes.');
        }
    }

    /**
     * Relative signed path:
     *   /v3/compliance-documents/{vendorId}/{field}?exp={ts}&sig={hmac}
     *
     * Callers prepend the absolute API origin for cross-origin <img> use.
     */
    public function signedPath(int $vendorId, string $field, ?int $ttlSeconds = null): string
    {
        $exp = time() + ($ttlSeconds ?? self::DEFAULT_TTL);
        $sig = $this->sign($vendorId, $field, $exp);

        return sprintf(
            '/v3/compliance-documents/%d/%s?exp=%d&sig=%s',
            $vendorId,
            rawurlencode($field),
            $exp,
            $sig,
        );
    }

    /** Constant-time verification of a presented signature + expiry. */
    public function verify(int $vendorId, string $field, int $exp, string $sig): bool
    {
        if ($exp < time()) {
            return false;
        }
        if (!in_array($field, self::FIELDS, true)) {
            return false;
        }

        return hash_equals($this->sign($vendorId, $field, $exp), $sig);
    }

    private function sign(int $vendorId, string $field, int $exp): string
    {
        // Domain-separated so this HMAC can never collide with another use of
        // the same secret (e.g. JWTs).
        return hash_hmac('sha256', "compliance-doc:{$vendorId}:{$field}:{$exp}", $this->secret);
    }
}
