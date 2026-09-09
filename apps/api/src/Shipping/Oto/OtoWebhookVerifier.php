<?php

declare(strict_types=1);

namespace Bayti\Api\Shipping\Oto;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Authenticates an inbound OTO webhook.
 *
 * OTO offers two optional mechanisms, set when the webhook is registered:
 *   - an `authorizationKey` — a static shared secret OTO echoes back (we accept
 *     it in the `Authorization` header or an `authorizationKey` body field);
 *   - a `secretKey` — used to sign `"{orderId}:{status}:{timestamp}"` with
 *     HMAC-SHA256, Base64-encoded (we accept it in a `Signature`/`X-OTO-Signature`
 *     header or a `signature` body field).
 *
 * When either is configured, at least one must match or the request is rejected.
 * When NEITHER is configured the request is accepted (dev), and the controller
 * still applies the RETRIEVE-BEFORE-ACTING posture: a webhook only advances a
 * shipment we already booked and whose provider id it matches, so a spoofed call
 * can't invent shipments.
 */
final class OtoWebhookVerifier
{
    public function __construct(
        private readonly ?string $secret = null,
        private readonly ?string $authKey = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->secret !== null || $this->authKey !== null;
    }

    /**
     * @param array<string, mixed> $body the decoded webhook payload
     */
    public function verify(ServerRequestInterface $request, array $body): bool
    {
        if (!$this->isConfigured()) {
            return true;
        }

        if ($this->authKey !== null) {
            $provided = $request->getHeaderLine('Authorization');
            if ($provided === '' && isset($body['authorizationKey']) && is_string($body['authorizationKey'])) {
                $provided = $body['authorizationKey'];
            }
            if ($provided !== '' && hash_equals($this->authKey, $provided)) {
                return true;
            }
        }

        if ($this->secret !== null) {
            $orderId = (string) ($body['orderId'] ?? '');
            $status = (string) ($body['status'] ?? '');
            $timestamp = (string) ($body['timestamp'] ?? '');
            $expected = base64_encode(
                hash_hmac('sha256', "{$orderId}:{$status}:{$timestamp}", $this->secret, true),
            );

            $provided = $request->getHeaderLine('X-OTO-Signature');
            if ($provided === '') {
                $provided = $request->getHeaderLine('Signature');
            }
            if ($provided === '' && isset($body['signature']) && is_string($body['signature'])) {
                $provided = $body['signature'];
            }
            if ($provided !== '' && hash_equals($expected, $provided)) {
                return true;
            }
        }

        return false;
    }
}
