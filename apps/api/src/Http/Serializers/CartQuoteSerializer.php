<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\DeliveryFeeCalculator;
use Bayti\Api\Domain\Promo\PromoResolution;

/**
 * Convert a Cart + optional PromoResolution into the
 * POST /v3/cart/quote response shape.
 *
 * Mobile/web consumer surfaces use this to render the checkout
 * summary BEFORE the user commits to /v3/checkout/initiate. The
 * server is authoritative on every number, no client math.
 *
 * Why a separate serializer instead of extending CartSerializer
 * --------------------------------------------------------------
 * CartSerializer::listShape exposes the cart contents (items,
 * subtotal). The quote endpoint's job is the price-breakdown
 * derivative: subtotal + delivery_fee + discount + total. Different
 * semantic concern, different response shape, kept as its own class
 * for clarity + so consumer surfaces never accidentally render the
 * full cart contents on a "price preview" endpoint.
 *
 * delivery_fee is server-authoritative
 * -------------------------------------
 * Computed by the injected DeliveryFeeCalculator from the cart's
 * distinct vendors (20 + 15×(stores−1)); the client never supplies it.
 * Same calculator the checkout-initiate path uses, so the quote and the
 * eventual placed order agree on the fee.
 *
 * Total computation mirrors Order::computeTotal
 * ----------------------------------------------
 * gross = subtotal + delivery_fee
 * net   = gross - discount
 * total = max(net, 0.00)  , discount can't make total negative
 *
 * Same bcmath ops + same floor-at-zero rule as Order::computeTotal
 *, keeps the quote and the eventual placed order in lock-step.
 */
final class CartQuoteSerializer
{
    // NOTE: $delivery is a REQUIRED dependency (no `= new DeliveryFeeCalculator()`
    // default). A `new` object default cannot be compiled by PHP-DI in prod
    // (enableCompilation), it throws "An object was found but objects cannot
    // be compiled". The class is only ever resolved via the container
    // (QuoteCartController dep), and DeliveryFeeCalculator is a no-arg concrete
    // class, so autowiring constructs and injects it cleanly. This mirrors
    // InitiateCheckoutController, which already takes it as a required arg.
    public function __construct(
        private readonly DeliveryFeeCalculator $delivery,
    ) {
    }

    /**
     * @return array{
     *     currency: string,
     *     subtotal: string,
     *     delivery_fee: string,
     *     discount: string,
     *     total: string,
     *     applied_promo: array{
     *         code: string,
     *         discount_type: string,
     *         discount_value: string,
     *         discount_amount: string
     *     }|null
     * }
     */
    public function shape(Cart $cart, ?PromoResolution $resolution): array
    {
        $subtotal = $cart->computeSubtotal();
        $deliveryFee = $this->delivery->forCart($cart);
        $discount = $resolution !== null ? $resolution->discountAmount : '0.00';

        $gross = bcadd($subtotal, $deliveryFee, 2);
        $net = bcsub($gross, $discount, 2);
        $total = bccomp($net, '0.00', 2) < 0 ? '0.00' : $net;

        return [
            'currency' => $cart->getCurrency(),
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'discount' => $discount,
            'total' => $total,
            'applied_promo' => $resolution !== null ? $this->promoShape($resolution) : null,
        ];
    }

    /**
     * @return array{
     *     code: string,
     *     discount_type: string,
     *     discount_value: string,
     *     discount_amount: string
     * }
     */
    private function promoShape(PromoResolution $resolution): array
    {
        $code = $resolution->promoCode;
        return [
            'code' => $code->getCode(),
            'discount_type' => $code->getDiscountType(),
            'discount_value' => $code->getDiscountValue(),
            'discount_amount' => $resolution->discountAmount,
        ];
    }
}
