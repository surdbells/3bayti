<?php

declare(strict_types=1);

namespace Bayti\Api\Messaging\WhatsApp;

/**
 * Authenticates inbound Meta WhatsApp webhooks.
 *
 * Two mechanisms, both configured in the Meta app:
 *   - the GET verification handshake echoes `hub.challenge` only when
 *     `hub.verify_token` matches `WHATSAPP_VERIFY_TOKEN`;
 *   - every POST event is signed: `X-Hub-Signature-256: sha256=<hex>` is an
 *     HMAC-SHA256 of the RAW request body keyed by the app secret
 *     (`WHATSAPP_APP_SECRET`). Unlike OTO/Noon (which sign a constructed string
 *     and base64-encode), Meta signs the raw bytes and hex-encodes, so the
 *     controller MUST hash the body exactly as received, before json_decode.
 *
 * Unconfigured = reject: a WhatsApp webhook with no signature check is a
 * spoofing vector (fake inbound messages would trigger replies), so an unset
 * app secret fails verification rather than accepting (the OTO dev-accept
 * posture is unsafe here).
 */
final class MetaWhatsAppWebhookVerifier
{
    public function __construct(
        private readonly ?string $appSecret = null,
        private readonly ?string $verifyToken = null,
    ) {
    }

    /** Verify the GET subscription handshake. */
    public function verifyChallenge(string $mode, string $token): bool
    {
        if ($this->verifyToken === null || $this->verifyToken === '') {
            return false;
        }
        return $mode === 'subscribe' && hash_equals($this->verifyToken, $token);
    }

    /**
     * Verify a POST event's signature against the raw request body.
     * `$signatureHeader` is the `X-Hub-Signature-256` header ('sha256=<hex>').
     */
    public function verifyEvent(string $rawBody, string $signatureHeader): bool
    {
        if ($this->appSecret === null || $this->appSecret === '') {
            return false;
        }
        if ($signatureHeader === '') {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $this->appSecret);
        return hash_equals($expected, $signatureHeader);
    }
}
