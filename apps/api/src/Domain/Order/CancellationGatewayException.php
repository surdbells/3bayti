<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

/**
 * Thrown by CancelOrderService when the auto-refund to Noon fails.
 * The order is left in its original state. Caller translates to 502.
 */
final class CancellationGatewayException extends \RuntimeException
{
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
