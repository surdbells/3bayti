<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order\Dto;

use Bayti\Api\Domain\Order\OrderReturnRefund;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /v3/admin/returns/{id}/record-refund (M3.2.X.18-F).
 *
 * Per Q-Refund locked: returns are refunded manually off the Noon API
 * (admin processes via bank transfer / store credit / cash / other and
 * records the event here). The controller pre-computes a suggested
 * amount via ReturnRefundCalculator; admin may override.
 *
 * Method must be one of OrderReturnRefund::ALL_METHODS.
 *
 * Amount must be a DECIMAL(10,2) string > 0. The entity layer
 * re-validates via assertPositiveMoney.
 *
 * Reference + notes are optional free text (bank txn id, store-credit
 * ledger entry, cash receipt number, etc.). Whitespace-only values
 * normalized to null at the entity layer.
 */
final class RecordReturnRefundInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'method is required.')]
        #[Assert\Choice(
            choices: OrderReturnRefund::ALL_METHODS,
            message: 'method must be one of: {{ choices }}',
        )]
        public readonly string $method = '',

        #[Assert\NotBlank(message: 'amount is required.')]
        #[Assert\Regex(
            pattern: '/^\d+(\.\d{1,2})?$/',
            message: 'amount must be a DECIMAL(10,2) string (e.g. "99.50").',
        )]
        public readonly string $amount = '',

        #[Assert\Length(max: 128, maxMessage: 'reference must be at most {{ limit }} characters.')]
        public readonly ?string $reference = null,

        #[Assert\Length(max: 2000, maxMessage: 'notes must be at most {{ limit }} characters.')]
        public readonly ?string $notes = null,

        #[Assert\Length(min: 3, max: 3, exactMessage: 'currency must be a 3-letter ISO 4217 code.')]
        public readonly string $currency = OrderReturnRefund::DEFAULT_CURRENCY,
    ) {
    }
}
