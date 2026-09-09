<?php

declare(strict_types=1);

namespace Bayti\Api\Shipping;

/**
 * The outcome of pushing one vendor's items to the courier provider.
 *
 * Provider-agnostic: `providerOrderId` is the shipping provider's own id for
 * the created order/shipment (OTO's `otoId`), which the webhook later
 * references. `trackingNumber` / `deliveryCompany` are populated when the
 * provider auto-created the shipment (createShipment: true) and assigned a
 * carrier; they may be null when only the order was created and the carrier is
 * assigned asynchronously (arriving via webhook).
 */
final class ShipmentResult
{
    /**
     * @param array<string, mixed> $raw the full provider response, for logging/audit
     */
    public function __construct(
        public readonly string $providerOrderId,
        public readonly ?string $trackingNumber = null,
        public readonly ?string $deliveryCompany = null,
        public readonly array $raw = [],
    ) {
    }
}
