<?php

declare(strict_types=1);

namespace Bayti\Api\Shipping\Oto;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Shipping\ShippingException;

/**
 * Builds the OTO `createOrder` request body for ONE vendor's slice of an order.
 *
 * 3bayti is multi-vendor, so each order splits into one OTO order/shipment per
 * store. The OTO `orderId` must be unique, so we suffix the order reference
 * with the vendor id (e.g. "3B-123-2C27-V7"). Payment is always `paid`
 * (Noon collects up-front at checkout), so `amount_due` is 0.
 *
 * Preconditions (throw, nothing is sent):
 *   - the order must have a delivery address (customer block);
 *   - the vendor must have a complete structured pickup address (sender block).
 */
final class OtoOrderPayloadBuilder
{
    /** Fallback parcel weight (kg) when we can't derive one from the items. */
    private const DEFAULT_WEIGHT_KG = 1.0;

    public function __construct(
        private readonly float $defaultWeightKg = self::DEFAULT_WEIGHT_KG,
    ) {
    }

    /**
     * @param OrderItem[] $vendorItems
     * @param string|null $deliveryOptionId force a specific carrier option; null = auto-assign
     * @return array<string, mixed>
     * @throws ShippingException
     */
    public function build(
        Order $order,
        Vendor $vendor,
        array $vendorItems,
        ?string $deliveryOptionId = null,
    ): array {
        $address = $order->getShippingAddress();
        if ($address === null) {
            throw ShippingException::noAddress();
        }
        if (!$vendor->pickupAddressIsComplete()) {
            throw ShippingException::incompletePickup();
        }

        $amount = '0.00';
        $items = [];
        $totalQty = 0;
        foreach ($vendorItems as $item) {
            $amount = bcadd($amount, $item->getSubtotal(), 2);
            $totalQty += $item->getQuantity();
            $items[] = [
                'productId' => $item->getProduct()->getId() ?? 0,
                'name' => $item->getProductNameSnapshot(),
                'price' => (float) $item->getUnitPrice(),
                'rowTotal' => (float) $item->getSubtotal(),
                'quantity' => $item->getQuantity(),
                'image' => $item->getProductImageSnapshot() ?? '',
            ];
        }

        $recipientName = trim($address->getFirstName() . ' ' . ($address->getLastName() ?? ''));

        $payload = [
            // Unique per vendor split; the webhook echoes this back as orderId.
            'orderId' => $order->getOrderReference() . '-V' . $vendor->getId(),
            'payment_method' => 'paid',
            'amount' => (float) $amount,
            'amount_due' => 0.0,
            'currency' => $order->getCurrency(),
            'createShipment' => true,
            'packageCount' => 1,
            'packageWeight' => $this->weightFor($totalQty),

            // Recipient (customer) — nested object per OTO createOrder.
            'customer' => array_filter([
                'name' => $recipientName,
                'mobile' => $address->getPhone(),
                'email' => $address->getEmail(),
                'address' => $address->getStreet(),
                'street' => $address->getStreet(),
                'city' => $address->getCity(),
                'district' => $address->getStateProvince(),
                'country' => $address->getCountryCode(),
                'postcode' => $address->getPostalCode(),
            ], static fn ($v): bool => $v !== null && $v !== ''),

            // Sender (vendor pickup) — flat sender* fields per OTO createOrder.
            'senderName' => $vendor->getName(),
            'senderFullName' => $vendor->getPickupContactName(),
            'senderMobile' => $vendor->getPickupPhone(),
            'senderCity' => $vendor->getPickupCity(),
            'senderDistrict' => $vendor->getPickupArea(),
            'senderStreet' => $vendor->getPickupStreet(),
            'senderBuildingNo' => $vendor->getPickupBuildingNo(),
            'senderPostcode' => $vendor->getPickupPostcode(),
            'senderCountry' => $vendor->getCountry() ?? $address->getCountryCode(),

            'items' => $items,
        ];

        // Optional pickup geo, only when the vendor set it.
        if ($vendor->getPickupLat() !== null && $vendor->getPickupLon() !== null) {
            $payload['lat'] = $vendor->getPickupLat();
            $payload['lon'] = $vendor->getPickupLon();
        }

        // A specific carrier option (the "choose per shipment" flow); omitted
        // lets OTO auto-assign.
        if ($deliveryOptionId !== null && $deliveryOptionId !== '') {
            $payload['deliveryOptionId'] = $deliveryOptionId;
        }

        // Drop null sender fields so we never send empty strings.
        return array_filter($payload, static fn ($v): bool => $v !== null);
    }

    private function weightFor(int $totalQty): float
    {
        $qty = max(1, $totalQty);
        // Half a kilo per garment as a sane floor, but never below the flat
        // default; refine once products carry real weights.
        return max($this->defaultWeightKg, 0.5 * $qty);
    }
}
