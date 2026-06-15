<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Middleware;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Per-endpoint permission gate (granular RBAC).
 *
 * MUST run after AuthMiddleware (the user attribute must be populated) and,
 * within the admin group, after AdminAuthMiddleware. Rejects the request unless
 * the authenticated user holds the required permission. `is_admin` users hold
 * every permission (see User::hasPermission), so this is a pass-through for
 * super admins.
 *
 * One instance is created per route via PermissionGuard::for('orders.refund'),
 * since the required permission is route-specific and cannot be auto-wired.
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly LoggerInterface $logger,
        private readonly string $permission,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);

        if (!$user instanceof User) {
            // Misconfigured chain: AuthMiddleware should run first.
            $this->logger->warning('PermissionMiddleware: no user attribute', [
                'permission' => $this->permission,
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ]);
            return $this->unauthorized();
        }

        if (!$user->hasPermission($this->permission)) {
            $this->logger->warning('PermissionMiddleware: missing permission', [
                'user_id' => $user->getId(),
                'permission' => $this->permission,
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ]);
            return $this->forbidden();
        }

        return $handler->handle($request);
    }

    private function unauthorized(): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(401);
        $payload = json_encode([
            'error' => [
                'code' => ErrorCodes::AUTH_INVALID_TOKEN,
                'message' => 'Authentication required.',
            ],
        ], JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload !== false ? $payload : '');
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', 'Bearer realm="3bayti API"');
    }

    private function forbidden(): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(403);
        $payload = json_encode([
            'error' => [
                'code' => 'insufficient_permissions',
                'message' => 'You do not have permission to perform this action.',
                'required_permission' => $this->permission,
            ],
        ], JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload !== false ? $payload : '');
        return $response->withHeader('Content-Type', 'application/json');
    }
}
