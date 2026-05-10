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

/**
 * Admin authorization middleware.
 *
 * MUST run AFTER AuthMiddleware in the middleware chain. Reads the
 * authenticated user from the request attribute (set by AuthMiddleware)
 * and rejects the request if the user is not an admin.
 *
 * Wire into Slim per-route group:
 *
 *   $app->group('/v3/admin', function ($group) {
 *       $group->get('/vendors', ListVendorsController::class);
 *       // ...
 *   })
 *       ->add(AdminAuthMiddleware::class)
 *       ->add(AuthMiddleware::class);
 *
 * Slim's add() is LIFO, so the order above means AuthMiddleware
 * runs FIRST (innermost outside the routing layer), then this
 * AdminAuthMiddleware sees the populated user attribute.
 *
 * Two failure modes
 * -----------------
 *
 * 1. ATTR_USER missing from the request → 401 Unauthorized.
 *    This means AuthMiddleware didn't run, or the request fell
 *    through it without setting the attribute. Most likely cause:
 *    middleware chain misconfiguration. Treated as auth failure
 *    rather than 500 because returning 500 would leak internals
 *    to an unauthorized caller.
 *
 * 2. User is authenticated but not admin → 403 Forbidden.
 *    Distinct from 401: the caller IS who they say they are; they
 *    just don't have the role we need. 403 communicates "we know
 *    who you are, you can't do this" — appropriate for a logged-in
 *    customer trying to hit /v3/admin/*.
 *
 * Role check
 * ----------
 * For M2, "admin" means `is_admin === true`. We don't accept
 * `is_sub_admin` here. Sub-admins exist for future fine-grained
 * permissions (e.g., a vendor-support sub-admin who can view but
 * not modify catalog data); their permission model is M4+ work.
 *
 * Audit / logging
 * ---------------
 * Failed admin checks are logged at WARNING level via the standard
 * logger (auto-wired). The audit log itself is NOT written here
 * because the request never reached a controller — there's no
 * "subject entity" to audit. Repeated failures should be visible
 * in log aggregation for ops to spot brute-force admin probing.
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);

        if (!$user instanceof User) {
            // Misconfigured chain: AuthMiddleware should run first.
            $this->logger->warning('AdminAuthMiddleware: no user attribute', [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ]);
            return $this->unauthorized();
        }

        if (!$user->isAdmin()) {
            $this->logger->warning('AdminAuthMiddleware: non-admin tried to access admin endpoint', [
                'user_id' => $user->getId(),
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ]);
            return $this->forbidden();
        }

        // Admin confirmed — proceed.
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
                'code' => 'admin_required',
                'message' => 'Admin access required for this endpoint.',
            ],
        ], JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload !== false ? $payload : '');
        return $response->withHeader('Content-Type', 'application/json');
    }
}
