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
 * Vendor authorization middleware.
 *
 * MUST run AFTER AuthMiddleware in the middleware chain. Reads the
 * authenticated user from the request attribute (set by AuthMiddleware)
 * and rejects the request if the user is not a vendor.
 *
 * Wire into Slim per-route group:
 *
 *   $app->group('/v3/vendor', function ($group) {
 *       $group->get('/orders', ListVendorOrdersController::class);
 *       // ...
 *   })
 *       ->add(VendorAuthMiddleware::class)
 *       ->add(AuthMiddleware::class);
 *
 * Slim's add() is LIFO, so the order above means AuthMiddleware runs
 * FIRST (innermost outside the routing layer), then VendorAuthMiddleware
 * sees the populated user attribute.
 *
 * Two failure modes (mirrors AdminAuthMiddleware exactly)
 * --------------------------------------------------------
 *
 * 1. ATTR_USER missing → 401 Unauthorized.
 *    Misconfigured chain. Treated as auth failure rather than 500
 *    because returning 500 would leak internals to an unauthorized
 *    caller.
 *
 * 2. User authenticated but not vendor → 403 Forbidden.
 *    Distinct from 401: caller IS who they say they are; they just
 *    don't have the role. 403 communicates "we know who you are,
 *    you can't do this" — appropriate for a logged-in customer
 *    trying to hit /v3/vendor/*.
 *
 * Role check
 * ----------
 * For M3.1.7, "vendor" means `is_vendor === true`. Multi-role users
 * (e.g. someone who's both customer and vendor) work naturally —
 * the same User can use customer endpoints elsewhere and vendor
 * endpoints here. Admin role doesn't grant vendor access — admins
 * use /v3/admin/orders for cross-vendor oversight.
 *
 * Vendor lifecycle gate (deferred)
 * --------------------------------
 * A full vendor lifecycle (pending → approved → suspended) would
 * gate access here based on a `vendor_status` column. That column
 * doesn't exist on User yet (M2 shipped is_vendor flag without
 * lifecycle states). Future enhancement: add vendor_status + gate
 * here. For M3.1.7 the boolean flag is enough — operators manually
 * gate via flipping is_vendor.
 */
final class VendorAuthMiddleware implements MiddlewareInterface
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
            $this->logger->warning('VendorAuthMiddleware: no user attribute', [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ]);
            return $this->unauthorized();
        }

        if (!$user->isVendor()) {
            $this->logger->warning('VendorAuthMiddleware: non-vendor tried to access vendor endpoint', [
                'user_id' => $user->getId(),
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ]);
            return $this->forbidden('vendor_required', 'Vendor access required for this endpoint.');
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

    private function forbidden(string $code, string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(403);
        $payload = json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload !== false ? $payload : '');
        return $response->withHeader('Content-Type', 'application/json');
    }
}
