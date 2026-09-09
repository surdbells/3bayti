<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Order\OrderShipment;
use DateTimeInterface;

/**
 * Shapes an OrderShipment (per-vendor courier shipment) for API responses:
 * the vendor + admin ship endpoints and the order detail views.
 */
final class ShipmentSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function shape(OrderShipment $s): array
    {
        return [
            'id' => $s->getId(),
            'vendor_id' => $s->getVendor()->getId(),
            'vendor_name' => $s->getVendor()->getName(),
            'provider' => $s->getProvider(),
            'provider_order_id' => $s->getProviderOrderId(),
            'tracking_number' => $s->getTrackingNumber(),
            'dc_tracking_number' => $s->getDcTrackingNumber(),
            'delivery_company' => $s->getDeliveryCompany(),
            'delivery_option_id' => $s->getDeliveryOptionId(),
            'status' => $s->getStatus(),
            'created_at' => $s->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $s->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param iterable<OrderShipment> $shipments
     * @return list<array<string, mixed>>
     */
    public function shapeMany(iterable $shipments): array
    {
        $out = [];
        foreach ($shipments as $s) {
            $out[] = $this->shape($s);
        }
        return $out;
    }
}
