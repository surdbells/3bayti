<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Factory for per-route {@see PermissionMiddleware} instances.
 *
 * Auto-wired (it holds the shared ResponseFactory + Logger). Routes resolve it
 * once from the container and call `for()` to gate each endpoint with a specific
 * permission, e.g.:
 *
 *   $perm = $container->get(PermissionGuard::class);
 *   $group->post('/orders/{id}/refund', RefundController::class)
 *         ->add($perm->for('orders.refund'));
 */
final class PermissionGuard
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function for(string $permission): PermissionMiddleware
    {
        return new PermissionMiddleware($this->responseFactory, $this->logger, $permission);
    }
}
