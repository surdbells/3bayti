<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Customization\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /v3/vendor/customization-requests/{id}/quote (P5).
 *
 * The vendor prices the work:
 *   - amount: positive DECIMAL(10,2)-shaped string (AED by default).
 *   - currency: optional ISO-4217 code, defaults to AED.
 *   - lead_time_days: optional turnaround estimate (0–365).
 *   - vendor_notes: optional message accompanying the quote.
 */
final class QuoteCustomizationInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'amount is required.')]
        #[Assert\Regex(
            pattern: '/^\d+(\.\d{1,2})?$/',
            message: 'amount must be a decimal with up to 2 places.',
        )]
        public readonly string $amount = '',

        #[Assert\Length(exactly: 3, exactMessage: 'currency must be a 3-letter ISO-4217 code.')]
        public readonly string $currency = 'AED',

        #[Assert\Type(type: 'integer', message: 'lead_time_days must be an integer.')]
        #[Assert\Range(
            min: 0,
            max: 365,
            notInRangeMessage: 'lead_time_days must be between {{ min }} and {{ max }}.',
        )]
        public readonly ?int $lead_time_days = null,

        #[Assert\Length(max: 2000, maxMessage: 'vendor_notes must be at most {{ limit }} characters.')]
        public readonly ?string $vendor_notes = null,
    ) {
    }
}
