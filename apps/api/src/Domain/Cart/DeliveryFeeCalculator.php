<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cart;

/**
 * Computes the order delivery fee from the number of distinct vendors
 * (stores) represented in a cart.
 *
 * Policy (fixed for now, change the two constants, or inject config here,
 * to make it flexible later):
 *
 *   fee = FIRST_STORE_FEE + ADDITIONAL_STORE_FEE × (distinctStores − 1)
 *
 * Examples at 20 / 15: 1 store = 20.00, 2 = 35.00, 3 = 50.00, 4 = 65.00.
 * An empty cart (no stores) is free (0.00).
 *
 * Money is handled as bcmath decimal strings, consistent with the rest of
 * the pricing pipeline (subtotal, discount, total).
 */
final class DeliveryFeeCalculator
{
    /** Flat fee for the first store in the cart. */
    public const FIRST_STORE_FEE = '20.00';

    /** Added for each store beyond the first. */
    public const ADDITIONAL_STORE_FEE = '15.00';

    /**
     * Delivery fee for the given cart, derived from its distinct vendors.
     */
    public function forCart(Cart $cart): string
    {
        return $this->forStoreCount($this->distinctStoreCount($cart));
    }

    /**
     * Delivery fee for an explicit number of distinct stores. Exposed so the
     * policy can be unit-tested directly without building a full Cart graph.
     */
    public function forStoreCount(int $stores): string
    {
        if ($stores <= 0) {
            return '0.00';
        }

        $additionalStores = (string) ($stores - 1);
        $additional = bcmul(self::ADDITIONAL_STORE_FEE, $additionalStores, 2);

        return bcadd(self::FIRST_STORE_FEE, $additional, 2);
    }

    /**
     * Count of distinct vendors (stores) across the cart's items.
     */
    private function distinctStoreCount(Cart $cart): int
    {
        $vendorIds = [];
        foreach ($cart->getItems() as $item) {
            $vendorId = $item->getProduct()->getVendor()->getId();
            if ($vendorId !== null) {
                $vendorIds[$vendorId] = true;
            }
        }

        return count($vendorIds);
    }
}
