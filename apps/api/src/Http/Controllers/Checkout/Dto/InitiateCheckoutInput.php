<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Checkout\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /v3/checkout/initiate.
 *
 * All fields optional — sensible server defaults apply:
 *   - channel:             MOBILE (mobile is the current sole client)
 *   - delivery_fee:        '0.00' if omitted (caller must compute
 *                          and pass; v3 doesn't ship a delivery-fee
 *                          calculator yet — comes in M3.2.x)
 *   - discount:            '0.00' if omitted
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

    #[Assert\Positive(message: 'billing_address_id must be a positive integer.')]
    public readonly ?int $billing_address_id;

    #[Assert\Positive(message: 'shipping_address_id must be a positive integer.')]
    public readonly ?int $shipping_address_id;

    public function __construct(
        ?string $channel = 'MOBILE',
        ?string $delivery_fee = '0.00',
        ?string $discount = '0.00',
        ?int $billing_address_id = null,
        ?int $shipping_address_id = null,
    ) {
        $this->channel = $channel ?? 'MOBILE';
        $this->delivery_fee = $delivery_fee ?? '0.00';
        $this->discount = $discount ?? '0.00';
        $this->billing_address_id = $billing_address_id;
        $this->shipping_address_id = $shipping_address_id;
    }
}
