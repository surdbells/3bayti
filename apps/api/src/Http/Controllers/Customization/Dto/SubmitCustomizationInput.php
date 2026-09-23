<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Customization\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /v3/me/customization-requests (P5).
 *
 * JSON body:
 *   - product_slug: the slug of the product to customize (v3 slug, never a
 *     legacy id). The controller resolves it via ProductRepository::findBySlug
 *     and gates on isOrderable().
 *   - description: the customer's free-text description of the work wanted.
 *   - measurement_snapshot: optional map of the customer's measurements at
 *     request time, so the vendor sees sizing regardless of later edits.
 */
final class SubmitCustomizationInput
{
    /** Cap on the measurement snapshot to keep the JSON bounded. */
    public const MAX_MEASUREMENT_KEYS = 40;

    /**
     * @param array<string, mixed>|null $measurement_snapshot
     */
    public function __construct(
        #[Assert\NotBlank(message: 'product_slug is required.')]
        #[Assert\Length(max: 200, maxMessage: 'product_slug must be at most {{ limit }} characters.')]
        public readonly string $product_slug = '',

        #[Assert\NotBlank(message: 'description is required.')]
        #[Assert\Length(
            min: 3,
            max: 2000,
            minMessage: 'description must be at least {{ limit }} characters.',
            maxMessage: 'description must be at most {{ limit }} characters.',
        )]
        public readonly string $description = '',

        #[Assert\Type(type: 'array', message: 'measurement_snapshot must be an object.')]
        #[Assert\Count(
            max: self::MAX_MEASUREMENT_KEYS,
            maxMessage: 'measurement_snapshot must have at most {{ limit }} entries.',
        )]
        public readonly ?array $measurement_snapshot = null,
    ) {
    }
}
