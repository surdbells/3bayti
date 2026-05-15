<?php

declare(strict_types=1);

namespace Bayti\Api\Payment;

/**
 * Result of querying a payment gateway for order status.
 *
 * `status` is the gateway's order-level status (provider-specific
 * strings — Noon returns AUTHORIZED, CAPTURED, FAILED, etc.).
 * Mapping to our v3 orders.status state machine happens in the
 * controller layer, not here — we don't want gateway changes to
 * cascade silently into our domain state.
 *
 * `terminal` is a derived boolean: true for finalised states
 * where no further state transition is possible from the gateway
 * (paid, failed, refunded, cancelled). Used by the polled
 * GetCheckoutStatusController to know when to stop fetching.
 *
 * `amount` and `currency` echo the order amount as the gateway
 * sees it — used to verify our v3 order's total matches what
 * the gateway processed (no silent total drift).
 */
final class OrderStatusResponse
{
    /**
     * @param array<string, mixed> $rawResponse Full provider response (JSONB audit)
     */
    public function __construct(
        public readonly string $providerOrderRef,
        public readonly string $status,
        public readonly bool $terminal,
        public readonly bool $paid,
        public readonly string $amount,
        public readonly string $currency,
        public readonly array $rawResponse,
    ) {
    }
}
