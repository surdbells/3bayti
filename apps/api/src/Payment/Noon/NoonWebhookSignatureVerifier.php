<?php

declare(strict_types=1);

namespace Bayti\Api\Payment\Noon;

/**
 * Noon webhook signature verifier contract.
 *
 * Why an interface (not a single class):
 * Noon's webhook signature algorithm is documented behind the
 * merchant portal — not publicly available. M3.1.6 ships
 * WITHOUT trusting webhook signatures, relying instead on the
 * "retrieve-order-before-acting" pattern (Noon-recommended per
 * docs.noonpayments.com/test/evaluating-api — they explicitly
 * advise "it is also possible to call the GET ORDER API to
 * determine the order status").
 *
 * M3.1.7 opens with empirical sandbox webhook capture →
 * confirms the algorithm → binds a real verifier. The interface
 * exists in M3.1.6 so the swap is one-line DI change, not a
 * controller refactor.
 *
 * Two implementations ship in M3.1.6:
 *
 *   - LoggingOnlyVerifier — accepts everything, records the
 *     supplied signature header to payment_webhook_events for
 *     forensic analysis (M3.1.7 input). Bound by default.
 *
 *   - HmacSha256SignatureVerifier — best-guess implementation
 *     using industry-standard HMAC-SHA256 over the raw request
 *     body with NOON_WEBHOOK_SECRET as the key. NOT bound by
 *     default; tests instantiate directly. M3.1.7 confirms or
 *     replaces.
 */
interface NoonWebhookSignatureVerifier
{
    /**
     * Verify the signature header against the raw body.
     *
     * @return bool true if the signature is valid (or trusted by
     *              policy, e.g. LoggingOnlyVerifier). Caller MUST
     *              NOT take the return value as the sole basis
     *              for trusting the payload — the canonical
     *              authority is GET_ORDER, not the webhook
     *              signature.
     */
    public function verify(string $rawBody, ?string $signatureHeader): bool;
}
