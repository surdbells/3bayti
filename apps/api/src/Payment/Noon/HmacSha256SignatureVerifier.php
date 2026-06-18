<?php

declare(strict_types=1);

namespace Bayti\Api\Payment\Noon;

/**
 * HMAC-SHA256 webhook signature verifier — SCAFFOLDED but NOT
 * bound to the DI container in M3.1.6.
 *
 * Why scaffolded now:
 * The exact algorithm Noon uses isn't published. Industry
 * convention for payment-provider webhooks is HMAC-SHA256 of
 * the raw body using a shared secret as the key, with the
 * signature delivered as a hex string in a request header. This
 * class implements that convention so M3.1.7 has one less moving
 * piece if sandbox capture confirms it. If sandbox capture
 * reveals a different algorithm (e.g. SHA-512, or a timestamp +
 * body concatenation like Stripe/Clover do), we'll either
 * replace this class or add a sibling — the
 * NoonWebhookSignatureVerifier interface is the only contract
 * the controller depends on.
 *
 * Constant-time comparison via hash_equals() defends against
 * timing attacks on the signature check.
 *
 * Tests in M3.1.6c instantiate this directly to verify the
 * happy path + tampered-body rejection, even though it's not
 * the bound implementation. M3.1.7 will:
 *
 *   1. Capture real sandbox webhook + body
 *   2. Run brute-force matching: HMAC-SHA256, HMAC-SHA512,
 *      timestamp+body variants, etc. — comparing each against
 *      the captured signature
 *   3. Update or extend this class to match the discovered algo
 *   4. Swap the DI binding from LoggingOnlyVerifier to this
 */
final class HmacSha256SignatureVerifier implements NoonWebhookSignatureVerifier
{
    public function __construct(
        private readonly string $sharedSecret,
    ) {
        if ($sharedSecret === '') {
            throw new \InvalidArgumentException(
                'HmacSha256SignatureVerifier requires a non-empty shared secret. '
                . 'Set NOON_WEBHOOK_SECRET in the environment.'
            );
        }
    }

    public function verify(string $rawBody, ?string $signatureHeader): bool
    {
        if ($signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        // Normalise: some providers prefix with 'sha256=' (GitHub-style),
        // some send the hex digest bare. Strip a known prefix if present.
        $normalised = strtolower(trim($signatureHeader));
        if (str_starts_with($normalised, 'sha256=')) {
            $normalised = substr($normalised, strlen('sha256='));
        }

        $expected = hash_hmac('sha256', $rawBody, $this->sharedSecret);

        // Constant-time comparison — defends against signature
        // timing attacks. PHP hash_equals returns false for
        // length-mismatched inputs without leaking timing info.
        return hash_equals($expected, $normalised);
    }

    /**
     * Real cryptographic verifier — a passing verify() means the HMAC
     * checked out, so the controller records the event as
     * payment_webhook_events.signature_verified=true.
     */
    public function isCryptographic(): bool
    {
        return true;
    }
}
