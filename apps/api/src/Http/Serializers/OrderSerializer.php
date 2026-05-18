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
     *     items: list<array<string, mixed>>,
     *     applied_promo: array{
     *         code: string,
     *         discount_type: string,
     *         discount_value: string,
     *         discount_amount: string,
     *         redeemed_at: string
     *     }|null
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
            // M3.2.X.8-F — applied_promo block reads from the persisted
            // PromoRedemption row. Null when no promo was applied. The
            // snapshot fields (code, type, value) are intentionally
            // taken from PromoRedemption's *_snapshot columns rather
            // than the live PromoCode entity, so an admin renaming /
            // re-pricing a code post-hoc doesn't mutate historical
            // orders' display. The same is true of discount_amount
            // (server-computed at checkout, frozen at redemption time).
            'applied_promo' => $this->promoShape($order),
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

    /**
     * M3.2.X.8-F — promo block built from the persisted PromoRedemption.
     *
     * Reads from the snapshot columns (codeSnapshot,
     * discountTypeSnapshot, discountValueSnapshot) so that a later
     * admin edit of the underlying PromoCode (renaming, re-pricing,
     * or even deletion) leaves historical order displays untouched.
     * This is the standard "captured at redemption time" model:
     * historical orders show what the customer saw at checkout.
     *
     * discount_amount is the server-computed amount that was actually
     * applied to this specific order (NOT discount_value, which is
     * the promo's policy figure — 10% or 50 AED — that becomes a
     * concrete dirham amount only when multiplied by THIS cart's
     * subtotal).
     *
     * redeemed_at lets ops correlate the promo apply against payment
     * timing in support tickets.
     *
     * @return array{
     *     code: string,
     *     discount_type: string,
     *     discount_value: string,
     *     discount_amount: string,
     *     redeemed_at: string
     * }|null
     */
    private function promoShape(Order $order): ?array
    {
        $redemption = $order->getPromoRedemption();
        if ($redemption === null) {
            return null;
        }
        return [
            'code' => $redemption->getCodeSnapshot(),
            'discount_type' => $redemption->getDiscountTypeSnapshot(),
            'discount_value' => $redemption->getDiscountValueSnapshot(),
            'discount_amount' => $redemption->getDiscountAmount(),
            'redeemed_at' => $redemption->getRedeemedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
