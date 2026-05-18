<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\PromoCode\Dto;

use Bayti\Api\Domain\Promo\PromoCode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body shape for POST /v3/admin/promo-codes.
 *
 * Required fields: code, discount_type, discount_value.
 * Everything else is optional with sensible nullable defaults.
 *
 * Field-level validation mirrors the entity-level guards in
 * PromoCode (which the controller will rely on as defense in
 * depth, but Assert errors here give friendlier 422 responses
 * than a 500 from a thrown InvalidArgumentException).
 *
 * The code field is normalized in the constructor per locked
 * pattern #3 (trim + upper) so admin UIs that type lowercase
 * still produce canonical stored codes. The DB-level UNIQUE
 * functional index on UPPER(code) catches any case-insensitive
 * collision the controller's pre-flight slug-style check might
 * miss in a race.
 *
 * Date fields accept ISO-8601 / RFC 3339 strings; the controller
 * parses them into DateTimeImmutable.
 */
final class CreatePromoCodeInput
{
    #[Assert\NotBlank(message: 'code is required.')]
    #[Assert\Length(
        max: PromoCode::CODE_MAX_LENGTH,
        maxMessage: 'code must not exceed {{ limit }} characters.',
    )]
    public readonly string $code;

    #[Assert\Length(
        max: 1000,
        maxMessage: 'description must not exceed {{ limit }} characters.',
    )]
    public readonly ?string $description;

    #[Assert\NotBlank(message: 'discount_type is required.')]
    #[Assert\Choice(
        choices: PromoCode::ALL_DISCOUNT_TYPES,
        message: "discount_type must be 'percentage' or 'fixed_amount'.",
    )]
    public readonly string $discount_type;

    /**
     * Money string (DECIMAL(10,2)). For percentage types the
     * controller additionally enforces 0 < value <= 100 via the
     * entity setter.
     */
    #[Assert\NotBlank(message: 'discount_value is required.')]
    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1,2})?$/',
        message: 'discount_value must be a non-negative decimal (e.g. "10.00").',
    )]
    public readonly string $discount_value;

    /**
     * ISO 4217 currency code. Optional — defaults to AED. Always
     * upper-cased in the constructor for canonical storage.
     */
    #[Assert\Length(
        min: 3, max: 3,
        exactMessage: 'currency must be a 3-letter ISO 4217 code.',
    )]
    public readonly string $currency;

    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1,2})?$/',
        message: 'min_subtotal must be a non-negative decimal (e.g. "100.00").',
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

    /**
     * ISO 8601 / RFC 3339 datetime string. Validated as a parseable
     * datetime in the controller (Symfony's DateTime constraint
     * accepts many formats; we keep the regex check loose here).
     */
    public readonly ?string $valid_from;

    public readonly ?string $valid_until;

    /**
     * Defaults to true. Pass false to create a code that's defined
     * but not yet available for redemption (rare; useful for
     * pre-scheduling a campaign).
     */
    public readonly bool $is_active;

    public function __construct(
        string $code = '',
        ?string $description = null,
        string $discount_type = '',
        string $discount_value = '',
        ?string $currency = 'AED',
        ?string $min_subtotal = null,
        ?string $max_discount_amount = null,
        ?int $usage_limit_global = null,
        ?int $usage_limit_per_user = null,
        ?string $valid_from = null,
        ?string $valid_until = null,
        ?bool $is_active = true,
    ) {
        // Normalize code to canonical UPPER form.
        $normalizedCode = PromoCode::normalizeCode($code);
        $this->code = $normalizedCode;

        $this->description = $description !== null
            ? (trim($description) === '' ? null : trim($description))
            : null;
        $this->discount_type = $discount_type;
        $this->discount_value = $discount_value;
        $this->currency = strtoupper($currency ?? 'AED');
        $this->min_subtotal = $min_subtotal;
        $this->max_discount_amount = $max_discount_amount;
        $this->usage_limit_global = $usage_limit_global;
        $this->usage_limit_per_user = $usage_limit_per_user;
        $this->valid_from = $valid_from;
        $this->valid_until = $valid_until;
        $this->is_active = $is_active ?? true;
    }
}
