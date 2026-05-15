<?php

declare(strict_types=1);

namespace Bayti\Api\Payment\Noon;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * M3.1.6 default webhook signature verifier.
 *
 * ACCEPTS EVERYTHING. Logs the supplied signature header and a
 * SHA-256 of the body for forensic analysis (so M3.1.7 can
 * confirm the real algorithm by comparing logged headers to
 * the body content known to be signed).
 *
 * The load-bearing safety mechanism in M3.1.6 is NOT this class
 * — it's the retrieve-order-before-acting pattern in
 * NoonWebhookController. Even with no signature verification,
 * a spoofed webhook cannot make us mark an order paid because
 * we always call retrieveOrder() (server-to-server, authenticated
 * with our merchant credentials) before applying the state
 * transition. The webhook is merely a poke that says "look at
 * order X again"; the actual authority is Noon's response to
 * our GET ORDER call.
 *
 * This pattern is explicitly endorsed by Noon's own docs page
 * docs.noonpayments.com/test/evaluating-api:
 *
 *   "It is suggested to use the webhook feature to rely on a
 *    more robust mechanism to receive events (server-to-server);
 *    it is also possible to call the GET ORDER API to determine
 *    the order status and the subsequent actions required."
 *
 * M3.1.7 replaces this with HmacSha256SignatureVerifier (or
 * whatever algorithm sandbox capture confirms).
 */
final class LoggingOnlyVerifier implements NoonWebhookSignatureVerifier
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function verify(string $rawBody, ?string $signatureHeader): bool
    {
        $this->logger->info('Noon webhook received (signature verification deferred to M3.1.7)', [
            'has_signature_header' => $signatureHeader !== null,
            'signature_header_length' => $signatureHeader === null ? 0 : strlen($signatureHeader),
            // Hash both so M3.1.7 can correlate; never log raw body or raw signature
            // (PII / secret risk if the body ever carries one).
            'body_sha256' => hash('sha256', $rawBody),
            'signature_sha256' => $signatureHeader === null
                ? null
                : hash('sha256', $signatureHeader),
            'body_length' => strlen($rawBody),
        ]);

        // Always accept. The retrieve-order-before-acting pattern
        // in NoonWebhookController is the real authority.
        return true;
    }
}
