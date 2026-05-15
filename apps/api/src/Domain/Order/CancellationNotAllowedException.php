<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

/**
 * Thrown by CancelOrderService when an order's current status doesn't
 * permit cancellation. Caller (controller) translates to 422.
 */
final class CancellationNotAllowedException extends \DomainException
{
    public function __construct(
        string $message,
        public readonly string $currentStatus,
    ) {
        parent::__construct($message);
    }
}
