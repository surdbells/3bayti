<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Order\Dto;

use Bayti\Api\Domain\Order\OrderReturnRequest;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /v3/orders/{id}/returns (M3.2.X.18-D).
 *
 * Submitted as multipart/form-data because of the attached photo
 * uploads. Slim's body-parsing middleware places the form fields
 * in getParsedBody() exactly like JSON; the photos arrive via
 * getUploadedFiles() and are validated + stored by the controller
 * separately (using ReturnPhotoStorageService).
 *
 * Form fields:
 *   - reason: one of the 7 OrderReturnRequest::ALL_REASONS
 *   - customer_notes: optional free text. Required when reason='other'
 *     (enforced both here and in the entity constructor).
 *   - order_item_ids: list of OrderItem IDs to return. At least one.
 *
 * Items + quantities
 * ==================
 * For v1, the request submits an order_item_ids list and the
 * controller derives a return quantity = order item quantity for
 * each (full-item returns). Partial-quantity returns are a future
 * enhancement; the entity layer already supports them (X.18-A).
 * Adding partial-qty support is a DTO + UI change only.
 *
 * Photos
 * ======
 * Photos are NOT part of this DTO, they ride in via PSR-7
 * uploaded files and are handled directly by the controller. The
 * MAX_PHOTOS_PER_REQUEST = 5 cap is enforced there.
 */
final class SubmitReturnInput
{
    /**
     * @param list<int> $order_item_ids
     */
    public function __construct(
        #[Assert\NotBlank(message: 'reason is required.')]
        #[Assert\Choice(
            choices: OrderReturnRequest::ALL_REASONS,
            message: 'reason must be one of: {{ choices }}',
        )]
        public readonly string $reason = '',

        #[Assert\Length(
            max: 2000,
            maxMessage: 'customer_notes must be at most {{ limit }} characters.',
        )]
        public readonly ?string $customer_notes = null,

        #[Assert\NotBlank(message: 'order_item_ids must be a non-empty list of integers.')]
        #[Assert\Type(type: 'array', message: 'order_item_ids must be an array.')]
        #[Assert\Count(
            min: 1,
            max: 100,
            minMessage: 'order_item_ids must contain at least one item.',
            maxMessage: 'order_item_ids must contain at most {{ limit }} items.',
        )]
        #[Assert\All(constraints: [
            new Assert\Type(type: 'integer', message: 'each order_item_ids entry must be an integer.'),
            new Assert\Positive(message: 'each order_item_ids entry must be a positive integer.'),
        ])]
        public readonly array $order_item_ids = [],
    ) {
    }

    /**
     * Cross-field invariant: reason='other' requires non-empty
     * customer_notes. Symfony Validator's @Callback or a dedicated
     * constraint class would do this idiomatically; the controller
     * also calls this for a fast-path 422.
     */
    public function requiresNotes(): bool
    {
        if ($this->reason !== OrderReturnRequest::REASON_OTHER) {
            return false;
        }
        return $this->customer_notes === null
            || trim($this->customer_notes) === '';
    }
}
