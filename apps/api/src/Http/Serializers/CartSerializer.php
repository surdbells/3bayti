<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;

/**
 * Convert Cart entities into mobile-friendly response shapes.
 *
 * Two response shapes:
 *
 *   - listShape (the full cart with embedded items), returned by
 *     GET /v3/cart and after most mutations
 *   - itemShape (single line item), used internally + in error
 *     paths
 *
 * Mobile mapping (the legacy customer/read-cart response shape,
 * which mobile's cart.page.ts consumes):
 *
 *   Legacy             ←→  v3 cart shape
 *   ─────────────────────────────────────
 *   cart.id            ←→  id
 *   cart.cart_code     ←→  cart_code (= legacyCartCode if migrated,
 *                                      else 'PND' for new carts -
 *                                      preserves mobile's hard-coded
 *                                      'PND' default expectation)
 *   cart.items[]       ←→  items[]
 *   cart.subtotal      ←→  subtotal
 *   cart.item_count    ←→  item_count
 *
 * Cart items use camelCase keys at the v3 layer; the mobile
 * transform in M3.1.6i maps them to legacy snake_case (e.g.
 * unit_price_snapshot → unit_price).
 *
 * Price-change fields (callers run CartPriceRefresher first, so
 * unit_price is always the current price):
 *   cart.price_signature       echo back at checkout as
 *                              expected_price_signature
 *   cart.has_price_changes     any line shows a price-updated badge
 *   item.price_changed         this line's price moved since it was added
 *   item.previous_unit_price   the price the customer saw before (or null)
 */
final class CartSerializer
{
    /**
     * @return array{
     *     id: int,
     *     status: string,
     *     currency: string,
     *     cart_code: string,
     *     subtotal: string,
     *     item_count: int,
     *     price_signature: string,
     *     has_price_changes: bool,
     *     items: list<array<string, mixed>>
     * }
     */
    public function listShape(Cart $cart): array
    {
        $items = [];
        foreach ($cart->getItems() as $item) {
            $items[] = $this->itemShape($item);
        }

        return [
            'id' => $cart->getId() ?? 0,
            'status' => $cart->getStatus(),
            'currency' => $cart->getCurrency(),
            'cart_code' => $cart->getLegacyCartCode() ?? 'PND',
            'subtotal' => $cart->computeSubtotal(),
            'item_count' => $cart->itemCount(),
            'price_signature' => $cart->priceSignature(),
            'has_price_changes' => $cart->hasPriceChanges(),
            'items' => $items,
        ];
    }

    /**
     * Shape for a user who has no active cart yet: same keys as listShape.
     *
     * @return array<string, mixed>
     */
    public function emptyShape(): array
    {
        return [
            'id' => 0,
            'status' => 'active',
            'currency' => 'AED',
            'cart_code' => 'PND',
            'subtotal' => '0.00',
            'item_count' => 0,
            'price_signature' => '',
            'has_price_changes' => false,
            'items' => [],
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     product_id: int,
     *     product_name: string,
     *     product_image: string,
     *     quantity: int,
     *     unit_price: string,
     *     previous_unit_price: string|null,
     *     price_changed: bool,
     *     line_subtotal: string,
     *     size: string|null,
     *     color: string|null,
     *     is_custom: bool,
     *     measurement: string|null,
     *     extra_measurement: string|null,
     *     note: string|null
     * }
     */
    public function itemShape(CartItem $item): array
    {
        $product = $item->getProduct();
        $unitPrice = $item->getUnitPriceSnapshot();
        $qty = $item->getQuantity();
        $lineSubtotal = bcmul($unitPrice, (string) $qty, 2);

        return [
            'id' => $item->getId() ?? 0,
            'product_id' => $product->getId() ?? 0,
            'product_name' => $product->getName(),
            'product_image' => $product->getPrimaryImageUrl() ?? '',
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'previous_unit_price' => $item->getPreviousUnitPrice(),
            'price_changed' => $item->hasPriceChanged(),
            'line_subtotal' => $lineSubtotal,
            'size' => $item->getSize(),
            'color' => $item->getColor(),
            'is_custom' => $item->isCustom(),
            'measurement' => $item->getMeasurement(),
            'extra_measurement' => $item->getExtraMeasurement(),
            'note' => $item->getNote(),
        ];
    }
}
