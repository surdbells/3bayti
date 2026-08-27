<?php

declare(strict_types=1);

namespace Bayti\Api\Payment;

/**
 * Result of initiating a checkout with a payment gateway.
 *
 * Provider-agnostic: every gateway must surface at minimum a
 * `checkoutUrl` (the page the user navigates to to complete
 * payment) and a `providerOrderRef` (the gateway's identifier
 * for this order, Noon's "orderId", Stripe's "PaymentIntent ID",
 * etc.).
 *
 * Extra provider-specific fields (e.g. Noon's full response
 * including all the EMI/offer/3DS details) live in $rawResponse
 * as an opaque map, controllers persist this to
 * payment_transactions.response_payload for audit; they don't
 * read it directly.
 */
final class CheckoutInitiation
{
    /**
     * @param array<string, mixed> $rawResponse Full provider response (JSONB audit)
     */
    public function __construct(
        public readonly string $checkoutUrl,
        public readonly string $providerOrderRef,
        public readonly array $rawResponse,
    ) {
    }
}
