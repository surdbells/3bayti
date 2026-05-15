<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderAddress;
use Bayti\Api\Domain\Order\OrderItem;

/**
 * Convert Order entities into mobile-friendly response shapes.
 *
 * Three shapes:
 *
 *   - listShape — used by GET /v3/orders (lightweight, no items
 *     drilldown). Matches the my-orders.page binding shape
 *     (per Phase 0 audit):
 *       order.id, order.date, order.status, order.total,
 *       order.items[] (with price, product_image, product_name,
 *       quantity, status, store)
 *
 *   - detailShape — used by GET /v3/orders/{id} (full order with
 *     embedded items + billing + shipping addresses).
 *
 *   - itemShape — single OrderItem; used internally + reused
 *     across list and detail.
 */
final class OrderSerializer
{
    /**
     * Lightweight list shape — matches mobile's my-orders page
     * binding (order.id / order.date / order.status / order.total /
     * order.items[]). 'date' is the order's created_at (ISO-8601).
     *
     * @return array{
     *     id: int,
     *     order_reference: string,
     *     status: string,
     *     date: string,
     *     subtotal: string,
     *     delivery_fee: string,
     *     discount: string,
     *     total: string,
     *     currency: string,
     *     paid_at: string|null,
     *     items: list<array<string, mixed>>
     * }
     */
    public function listShape(Order $order): array
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = $this->itemShape($item);
        }

        return [
            'id' => $order->getId() ?? 0,
            'order_reference' => $order->getOrderReference(),
            'status' => $order->getStatus(),
            'date' => $order->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'subtotal' => $order->getSubtotal(),
            'delivery_fee' => $order->getDeliveryFee(),
            'discount' => $order->getDiscount(),
            'total' => $order->getTotal(),
            'currency' => $order->getCurrency(),
            'paid_at' => $order->getPaidAt()?->format(\DateTimeInterface::ATOM),
            'items' => $items,
        ];
    }

    /**
     * Detail shape — adds billing + shipping address blocks.
     *
     * @return array<string, mixed>
     */
    public function detailShape(Order $order): array
    {
        $shape = $this->listShape($order);
        $shape['billing_address'] = $this->addressShape($order->getBillingAddress());
        $shape['shipping_address'] = $this->addressShape($order->getShippingAddress());
        return $shape;
    }

    /**
     * @return array{
     *     id: int,
     *     product_id: int,
     *     vendor_id: int,
     *     product_name: string,
     *     product_image: string|null,
     *     quantity: int,
     *     unit_price: string,
     *     subtotal: string,
     *     size: string|null,
     *     color: string|null,
     *     is_custom: bool,
     *     measurement: string|null,
     *     extra_measurement: string|null,
     *     note: string|null,
     *     item_status: string,
     *     store: int
     * }
     */
    public function itemShape(OrderItem $item): array
    {
        $product = $item->getProduct();
        $vendor = $item->getVendor();
        $vendorId = $vendor->getId() ?? 0;

        return [
            'id' => $item->getId() ?? 0,
            'product_id' => $product->getId() ?? 0,
            'vendor_id' => $vendorId,
            'product_name' => $item->getProductNameSnapshot(),
            'product_image' => $item->getProductImageSnapshot(),
            'quantity' => $item->getQuantity(),
            'unit_price' => $item->getUnitPrice(),
            'subtotal' => $item->getSubtotal(),
            'size' => $item->getSize(),
            'color' => $item->getColor(),
            'is_custom' => $item->isCustom(),
            'measurement' => $item->getMeasurement(),
            'extra_measurement' => $item->getExtraMeasurement(),
            'note' => $item->getNote(),
            'item_status' => $item->getItemStatus(),
            // Legacy field name (my-orders.page reads item.store);
            // duplicate of vendor_id but mobile already binds 'store'.
            'store' => $vendorId,
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function addressShape(?OrderAddress $address): ?array
    {
        if ($address === null) {
            return null;
        }
        return [
            'first_name' => $address->getFirstName(),
            'last_name' => $address->getLastName(),
            'phone' => $address->getPhone(),
            'email' => $address->getEmail(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'state_province' => $address->getStateProvince(),
            'country_code' => $address->getCountryCode(),
            'postal_code' => $address->getPostalCode(),
        ];
    }
}
