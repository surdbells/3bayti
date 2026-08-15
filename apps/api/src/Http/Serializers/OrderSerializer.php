<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderAddress;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\User\User;

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
     * Returns block (M3.2.X.18-H)
     * ===========================
     * When the caller passes a non-null $returns list, the shape
     * includes a 'returns' key with a compact summary of every
     * return request on this order. When null, the 'returns' key
     * is omitted entirely (back-compat with existing callers and
     * to avoid forcing N+1 queries on list endpoints).
     *
     * Each summary is intentionally lightweight — id, status,
     * reason, requested_at, item_count — enough for a "Returns"
     * badge on the order card; clients drill down via
     * GET /v3/returns/{id} when needed.
     *
     * Gift-card purchase orders (M-giftcard)
     * ======================================
     * Gift-card PURCHASE orders are created synthetically with NO
     * order_items (InitiateCheckoutController::initiateGiftCardPurchase);
     * the funded card is linked back via
     * gift_cards.purchase_order_reference = orders.order_reference.
     *
     * When such an order (zero real items) is accompanied by its linked
     * GiftCard (passed in as $giftCard), we synthesize a SINGLE "Gift
     * Card" line item so the mobile my-orders list + detail always show
     * an item. Normal orders (>=1 real item) are unaffected and ignore
     * $giftCard entirely.
     *
     * The list path prefetches a reference→card map (see
     * ListOrdersController) to avoid an N+1; the detail path does one
     * lookup.
     *
     * @param list<OrderReturnRequest>|null $returns
     * @return array<string, mixed>
     */
    public function listShape(Order $order, ?array $returns = null, ?GiftCard $giftCard = null): array
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = $this->itemShape($item);
        }

        // Synthesize a Gift Card line ONLY when the order has zero real
        // items AND a linked gift card was provided. Never touches
        // normal orders.
        if ($items === [] && $giftCard !== null) {
            $items[] = $this->giftCardItemShape($order, $giftCard);
        }

        $shape = [
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
            // The customer who placed the order (order.user). Vendors use this
            // to coordinate delivery, so name + contact are exposed on the
            // vendor order list/detail. (detailShape reuses listShape, so this
            // covers both.)
            'customer' => [
                'first_name' => $order->getUser()->getFirstName(),
                'last_name' => $order->getUser()->getLastName(),
                'email' => $order->getUser()->getEmail(),
                'phone' => $order->getUser()->getPhone(),
            ],
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

        if ($returns !== null) {
            $shape['returns'] = $this->returnSummaries($returns);
        }

        return $shape;
    }

    /**
     * Detail shape — adds billing + shipping address blocks. Also
     * supports the optional 'returns' summary list (M3.2.X.18-H);
     * see listShape() docblock for details.
     *
     * @param list<OrderReturnRequest>|null $returns
     * @return array<string, mixed>
     */
    public function detailShape(Order $order, ?array $returns = null, ?GiftCard $giftCard = null): array
    {
        $shape = $this->listShape($order, $returns, $giftCard);
        $shape['billing_address'] = $this->addressShape($order->getBillingAddress());
        $shape['shipping_address'] = $this->addressShape($order->getShippingAddress());
        return $shape;
    }

    /**
     * Admin list row — the standard list shape plus the customer
     * (account holder) block. Admin-only: vendors deliberately do not
     * receive customer contact in their order list. The caller eagerly
     * loads o.user (OrderRepository::paginatedForAdmin) so this does not
     * trigger an N+1.
     */
    public function adminListShape(Order $order, ?array $returns = null): array
    {
        $shape = $this->listShape($order, $returns);
        $shape['customer'] = $this->customerShape($order->getUser());
        $shape['delivery'] = $this->deliverySummary($order->getShippingAddress());
        return $shape;
    }

    /**
     * Lightweight delivery destination for list/logistics rows (the full
     * address lives on the detail shape). @return array{name:string|null, city:string|null, area:string|null, phone:string|null}|null
     */
    private function deliverySummary(?OrderAddress $a): ?array
    {
        if ($a === null) {
            return null;
        }
        $name = trim(($a->getFirstName() ?? '') . ' ' . ($a->getLastName() ?? ''));
        return [
            'name' => $name !== '' ? $name : null,
            'city' => $a->getCity(),
            'area' => $a->getStateProvince(),
            'phone' => $a->getPhone(),
        ];
    }

    /**
     * Admin order detail — detail shape plus the customer block, so the
     * order-management screen can show the account holder alongside the
     * shipping recipient.
     */
    public function adminDetailShape(Order $order, ?array $returns = null): array
    {
        $shape = $this->detailShape($order, $returns);
        $shape['customer'] = $this->customerShape($order->getUser());
        return $shape;
    }

    /** @return array{id:int, first_name:string|null, last_name:string|null, email:string|null, phone:string|null} */
    private function customerShape(User $user): array
    {
        return [
            'id' => $user->getId() ?? 0,
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone(),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     product_id: int,
     *     vendor_id: int,
     *     vendor_name: string,
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

        // Legacy-migrated line items snapshot the OLD image URL on the now-
        // decommissioned host (api.3bayti.ae, original filename). The product
        // image itself was localized during migration, so fall back to the
        // product's current (localized) image for legacy or empty snapshots.
        $image = $item->getProductImageSnapshot();
        if ($image === null || $image === '' || str_contains($image, 'api.3bayti.ae')) {
            $image = $product->getPrimaryImageUrl() ?? $image;
        }

        return [
            'id' => $item->getId() ?? 0,
            'product_id' => $product->getId() ?? 0,
            'vendor_id' => $vendorId,
            'vendor_name' => $vendor->getName(),
            'product_name' => $item->getProductNameSnapshot(),
            'product_image' => $image,
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
     * Synthesize the SINGLE "Gift Card" line item for a gift-card
     * purchase order (which carries no real OrderItem rows).
     *
     * The returned array uses the EXACT same keys and value types as
     * itemShape() so the mobile list/detail bindings treat it
     * identically to a normal line. There is no OrderItem / Product /
     * Vendor entity behind it, so the entity-derived ids are 0:
     *
     *   id            0                (no OrderItem row)
     *   product_id    0                (no Product;   mobile: product_id)
     *   vendor_id     0                (no Vendor)
     *   store         0                (legacy alias of vendor_id; mobile: store)
     *   product_name  "Gift Card"      (or the themed label, e.g.
     *                                   "Luxury Gift Card"; mobile: product_name)
     *   product_image theme photo URL if resolvable else ""
     *                                   (mobile: product_image)
     *   quantity      1
     *   unit_price    order subtotal (the card denomination; decimal string)
     *   subtotal      order subtotal (same; decimal string)
     *   size / color / measurement / extra_measurement / note  null
     *   is_custom     false
     *   item_status   order status     (mobile reads this as the line status)
     *
     * unit_price / subtotal use the order SUBTOTAL — for a gift-card
     * purchase the subtotal equals the denomination (delivery_fee and
     * discount are both '0.00' on these synthetic orders).
     *
     * @return array{
     *     id: int,
     *     product_id: int,
     *     vendor_id: int,
     *     product_name: string,
     *     product_image: string,
     *     quantity: int,
     *     unit_price: string,
     *     subtotal: string,
     *     size: null,
     *     color: null,
     *     is_custom: bool,
     *     measurement: null,
     *     extra_measurement: null,
     *     note: null,
     *     item_status: string,
     *     store: int
     * }
     */
    private function giftCardItemShape(Order $order, GiftCard $giftCard): array
    {
        $theme = $giftCard->getTheme();
        $name = ucfirst($theme) . ' Gift Card';

        // No server-side theme→image catalog exists; the luxury theme is
        // the only one that carries an uploaded photo. Fall back to "".
        $image = $giftCard->getRecipientPhotoUrl() ?? '';

        $price = $order->getSubtotal();

        return [
            'id' => 0,
            'product_id' => 0,
            'vendor_id' => 0,
            'vendor_name' => null,
            'product_name' => $name,
            'product_image' => $image,
            'quantity' => 1,
            'unit_price' => $price,
            'subtotal' => $price,
            'size' => null,
            'color' => null,
            'is_custom' => false,
            'measurement' => null,
            'extra_measurement' => null,
            'note' => null,
            'item_status' => $order->getStatus(),
            // Legacy field name (my-orders.page reads item.store).
            'store' => 0,
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

    /**
     * Compact summaries for the embedded 'returns' block (M3.2.X.18-H).
     *
     * Each summary intentionally exposes only the fields a "Returns"
     * badge on the order card needs:
     *
     *   id              — for the drilldown URL
     *   reference       — "RET-{id}" for human display
     *   status          — drives the badge color in the UI
     *   reason          — short reason code (so the card can show e.g.
     *                     "Defective" without a separate fetch)
     *   requested_at    — for sort + relative-time display
     *   item_count      — how many items are being returned
     *   is_terminal     — UI hint to dim the badge for resolved returns
     *
     * Full detail is fetched via GET /v3/returns/{id} when the user
     * taps the badge.
     *
     * @param list<OrderReturnRequest> $returns
     * @return list<array<string, mixed>>
     */
    private function returnSummaries(array $returns): array
    {
        $out = [];
        foreach ($returns as $rr) {
            $rrId = $rr->getId() ?? 0;
            $out[] = [
                'id' => $rrId,
                'reference' => 'RET-' . $rrId,
                'status' => $rr->getStatus(),
                'reason' => $rr->getReason(),
                'requested_at' => $rr->getRequestedAt()->format(\DateTimeInterface::ATOM),
                'item_count' => $this->countReturnItems($rr),
                'is_terminal' => $rr->isTerminal(),
            ];
        }
        return $out;
    }

    private function countReturnItems(OrderReturnRequest $rr): int
    {
        $count = 0;
        foreach ($rr->getItems() as $_item) {
            $count++;
        }
        return $count;
    }
}
