<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

/**
 * Outcome of CancelOrderService::cancel.
 */
final class CancelOrderResult
{
    public function __construct(
        public readonly Order $order,
        public readonly bool $wasAlreadyCancelled,
        public readonly bool $refundIssued,
        public readonly ?string $refundAmount,
    ) {
    }
}
