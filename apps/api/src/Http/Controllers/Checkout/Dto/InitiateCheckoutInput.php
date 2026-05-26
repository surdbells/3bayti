<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Checkout\Dto;

use Bayti\Api\Domain\Promo\PromoCode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /v3/checkout/initiate.
 *
 * All fields optional — sensible server defaults apply:
 *   - channel:             MOBILE (mobile is the current sole client)
 *   - delivery_fee:        '0.00' if omitted (caller must compute
 *                          and pass; v3 doesn't ship a delivery-fee
 *                          calculator yet — comes in M3.2.x)
 *   - discount:            '0.00' if omitted (LEGACY — see promo_code
 *                          notes below)
 *   - promo_code:          null if omitted (M3.2.X.8-D)
 *   - billing_address_id:  user's default-billing address
 *   - shipping_address_id: user's default-shipping address
 *
 * Why money fields are strings:
 *   bcmath/decimal precision. Float drift on a 999.99 AED order
 *   is a real risk (0.01 dirham). DECIMAL(10,2) on the entity,
 *   strings end-to-end through the API.
 *
 * Why channel is constrained at this layer:
 *   Noon hard-rejects values other than 'MOBILE' or 'WEB'. We
 *   reject with a clear 422 before paying the round-trip to
 *   Noon (and avoiding the gateway's InvalidArgumentException
 *   which would otherwise surface as a 500).
 *
 * Legacy `discount` field vs new `promo_code` field (M3.2.X.8-D)
 * ---------------------------------------------------------------
 * Before M3.2.X.8, the controller trusted `discount` as a raw money
 * amount supplied by the caller (a security gap flagged in
 * InitiateCheckoutController:88-91). M3.2.X.8 adds the new
 * `promo_code` field which resolves server-side to a server-computed
 * amount.
 *
 * Controller-level precedence (sub-phase D logic):
 *   - If `promo_code` is supplied AND resolves: server-computed
 *     amount wins; any client-supplied `discount` is IGNORED. A
 *     deprecation header is emitted when `discount` was also supplied
 *     non-zero (to tell the client to drop it from their request).
 *   - If `promo_code` is supplied but resolution FAILS: HTTP 422
 *     with the matching PROMO_* error code; no order created.
 *   - If `promo_code` is null/absent AND `discount` > '0.00': legacy
 *     path; discount honored as-is + deprecation header emitted.
 *   - If both are null/absent/zero: discount stays '0.00', current
 *     no-promo behavior unchanged.
 */
final class InitiateCheckoutInput
{
    #[Assert\Choice(
        choices: ['MOBILE', 'WEB'],
        message: "channel must be 'MOBILE' or 'WEB'.",
    )]
    public readonly string $channel;

    /**
     * Decimal string with two fractional digits, e.g. '15.00'.
     */
    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1,2})?$/',
        message: 'delivery_fee must be a non-negative decimal (e.g. "15.00").',
    )]
    public readonly string $delivery_fee;

    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1,2})?$/',
        message: 'discount must be a non-negative decimal (e.g. "10.00").',
    )]
    public readonly string $discount;

    /**
     * Promo code to apply (M3.2.X.8-D). When supplied, the controller
     * resolves it server-side via PromoCodeResolverService and uses
     * the resolved amount in place of any client-supplied `discount`.
     * Normalized at the DTO entrance (trim + upper) per locked pattern #3.
     */
    #[Assert\Length(
        max: PromoCode::CODE_MAX_LENGTH,
        maxMessage: 'promo_code is too long (max {{ limit }} chars).',
    )]
    public readonly ?string $promo_code;

    #[Assert\Positive(message: 'billing_address_id must be a positive integer.')]
    public readonly ?int $billing_address_id;

    #[Assert\Positive(message: 'shipping_address_id must be a positive integer.')]
    public readonly ?int $shipping_address_id;

    /**
     * Gift card code to apply at checkout (M3.5).
     * When supplied the server debits min(card.balance, order.total)
     * from the card inside the checkout EM transaction and charges
     * only the remainder to the payment gateway.
     * When the full order is covered by the card, the Noon gateway
     * call is skipped and the order transitions directly to 'paid'.
     * Format: raw 16 chars or hyphenated XXXX-XXXX-XXXX-XXXX.
     */
    #[Assert\Length(
        max: 19,
        maxMessage: 'gift_card_code is too long (max 19 chars including hyphens).',
    )]
    public readonly ?string $gift_card_code;

    public function __construct(
        ?string $channel = 'MOBILE',
        ?string $delivery_fee = '0.00',
        ?string $discount = '0.00',
        ?string $promo_code = null,
        ?int $billing_address_id = null,
        ?int $shipping_address_id = null,
        ?string $gift_card_code = null,
    ) {
        $this->channel = $channel ?? 'MOBILE';
        $this->delivery_fee = $delivery_fee ?? '0.00';
        $this->discount = $discount ?? '0.00';
        if ($promo_code === null) {
            $this->promo_code = null;
        } else {
            $normalized = PromoCode::normalizeCode($promo_code);
            $this->promo_code = $normalized === '' ? null : $normalized;
        }
        $this->billing_address_id = $billing_address_id;
        $this->shipping_address_id = $shipping_address_id;
        // Normalise gift card code: strip hyphens, uppercase
        $this->gift_card_code = $gift_card_code !== null
            ? (strtoupper(str_replace('-', '', trim($gift_card_code))) ?: null)
            : null;
    }
}
