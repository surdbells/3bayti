<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Cart\Dto;

use Bayti\Api\Domain\Promo\PromoCode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /v3/cart/quote.
 *
 * Single optional field: `promo_code`. When supplied, the controller
 * passes it through PromoCodeResolverService::resolveForCart and
 * surfaces the resulting price breakdown. When omitted (or empty),
 * the endpoint returns the breakdown with no promo applied, the
 * "preview your cart total" call.
 *
 * Normalization happens at the entrance per locked pattern #3:
 * trim + upper-case before storing on the readonly property. The
 * downstream resolver also normalizes (defense in depth), but doing
 * it here gives the validator a consistent value to length-check
 * and means controllers / tests see the same canonical form.
 *
 * Empty + missing both collapse to null, the controller branches
 * on null to short-circuit the resolver call, so we don't need a
 * Sentinel value distinguishing "user typed nothing" from "user
 * omitted the field".
 *
 * Length validation
 * -----------------
 * Bounded at PromoCode::CODE_MAX_LENGTH (64) so a malicious caller
 * can't pass a megabyte string and run the validator over it.
 */
final class QuoteCartInput
{
    /**
     * The normalized promo code, or null if none / empty supplied.
     * Length is bounded by PromoCode::CODE_MAX_LENGTH (currently 64).
     */
    #[Assert\Length(
        max: PromoCode::CODE_MAX_LENGTH,
        maxMessage: 'promo_code is too long (max {{ limit }} chars).',
    )]
    public readonly ?string $promo_code;

    public function __construct(
        ?string $promo_code = null,
    ) {
        if ($promo_code === null) {
            $this->promo_code = null;
            return;
        }
        $normalized = PromoCode::normalizeCode($promo_code);
        $this->promo_code = $normalized === '' ? null : $normalized;
    }
}
