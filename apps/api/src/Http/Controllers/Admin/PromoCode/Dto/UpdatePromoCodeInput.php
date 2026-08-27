<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\PromoCode\Dto;

use Bayti\Api\Domain\Promo\PromoCode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body shape for PUT /v3/admin/promo-codes/{id}.
 *
 * All fields are optional, only those provided as non-null are
 * applied to the entity. This matches the existing UpdateBrandInput /
 * UpdateVendorInput partial-update convention.
 *
 * Tristate limitation
 * --------------------
 * Like other Update DTOs in the codebase, this can't distinguish
 * "field omitted" from "field set to null". The controller treats
 * non-null as "apply this value" and null as "leave unchanged" for
 * most fields. The exception is the nullable money / limit / date
 * fields, for those, an explicit null in the body would normally
 * mean "clear the value", but our PUT semantics here treat null as
 * "unchanged". Admins who want to clear a field should be given an
 * explicit DELETE on it in a future iteration (out of v1 scope).
 *
 * Validation mirrors CreatePromoCodeInput. The controller additionally
 * checks code-uniqueness on the new value when code is being changed.
 */
final class UpdatePromoCodeInput
{
    #[Assert\Length(
        max: PromoCode::CODE_MAX_LENGTH,
        maxMessage: 'code must not exceed {{ limit }} characters.',
    )]
    public readonly ?string $code;

    #[Assert\Length(
        max: 1000,
        maxMessage: 'description must not exceed {{ limit }} characters.',
    )]
    public readonly ?string $description;

    #[Assert\Choice(
        choices: PromoCode::ALL_DISCOUNT_TYPES,
        message: "discount_type must be 'percentage' or 'fixed_amount'.",
    )]
    public readonly ?string $discount_type;

    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1,2})?$/',
        message: 'discount_value must be a non-negative decimal.',
    )]
    public readonly ?string $discount_value;

    #[Assert\Length(
        min: 3, max: 3,
        exactMessage: 'currency must be a 3-letter ISO 4217 code.',
    )]
    public readonly ?string $currency;

    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1,2})?$/',
        message: 'min_subtotal must be a non-negative decimal.',
    )]
    public readonly ?string $min_subtotal;

    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1,2})?$/',
        message: 'max_discount_amount must be a non-negative decimal.',
    )]
    public readonly ?string $max_discount_amount;

    #[Assert\PositiveOrZero(message: 'usage_limit_global must be >= 0.')]
    public readonly ?int $usage_limit_global;

    #[Assert\PositiveOrZero(message: 'usage_limit_per_user must be >= 0.')]
    public readonly ?int $usage_limit_per_user;

    public readonly ?string $valid_from;
    public readonly ?string $valid_until;

    public readonly ?bool $is_active;

    public function __construct(
        ?string $code = null,
        ?string $description = null,
        ?string $discount_type = null,
        ?string $discount_value = null,
        ?string $currency = null,
        ?string $min_subtotal = null,
        ?string $max_discount_amount = null,
        ?int $usage_limit_global = null,
        ?int $usage_limit_per_user = null,
        ?string $valid_from = null,
        ?string $valid_until = null,
        ?bool $is_active = null,
    ) {
        $this->code = $code !== null ? PromoCode::normalizeCode($code) : null;
        $this->description = $description !== null
            ? (trim($description) === '' ? null : trim($description))
            : null;
        $this->discount_type = $discount_type;
        $this->discount_value = $discount_value;
        $this->currency = $currency !== null ? strtoupper($currency) : null;
        $this->min_subtotal = $min_subtotal;
        $this->max_discount_amount = $max_discount_amount;
        $this->usage_limit_global = $usage_limit_global;
        $this->usage_limit_per_user = $usage_limit_per_user;
        $this->valid_from = $valid_from;
        $this->valid_until = $valid_until;
        $this->is_active = $is_active;
    }
}
