<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Promo;

/**
 * Immutable value object returned by PromoCodeResolverService::resolveForCart
 * on a successful match. Bundles the resolved PromoCode entity, the
 * server-computed discount amount, and the currency it's denominated in.
 *
 * Why a dedicated VO rather than returning a tuple
 * -------------------------------------------------
 * The serializer in M3.2.X.8-C builds the `applied_promo` block on
 * the quote response from these three pieces. A typed VO makes the
 * shape explicit at the call site and survives phpstan level 6
 * without `@var` gymnastics.
 *
 * Why we don't carry the User or Cart
 * ------------------------------------
 * The resolution is "this code applies to this cart for this user
 * with THIS resulting amount". The caller already has the User and
 * Cart references; round-tripping them through the VO would only
 * serve to confuse the contract. The VO is data-out only, the
 * caller decides what to do with it.
 *
 * Why discountAmount is a string
 * -------------------------------
 * bcmath-safe DECIMAL(10,2) representation, matching the convention
 * across Cart::computeSubtotal, Order::$discount, and the
 * notification email money formatters. Conversion to float for
 * display is the consumer surface's problem; backend never lets
 * float drift into money math.
 */
final class PromoResolution
{
    public function __construct(
        public readonly PromoCode $promoCode,
        public readonly string $discountAmount,
        public readonly string $currency,
    ) {
    }
}
