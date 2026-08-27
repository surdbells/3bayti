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
 *    you can't do this", appropriate for a logged-in customer
 *    trying to hit /v3/vendor/*.
 *
 * Role check
 * ----------
 * For M3.1.7, "vendor" means `is_vendor === true`. Multi-role users
 * (e.g. someone who's both customer and vendor) work naturally -
 * the same User can use customer endpoints elsewhere and vendor
 * endpoints here. Admin role doesn't grant vendor access, admins
 * use /v3/admin/orders for cross-vendor oversight.
 *
 * Vendor lifecycle gate (M3.2.X.6)
 * --------------------------------
 * The middleware enforces TWO gates in sequence:
 *
 *   1. Role check: is_vendor=true
 *   2. Lifecycle check: at least one vendor with status='approved'
 *
 * The lifecycle check uses VendorRepository::existsApprovedForOwnerUser
 * resolved LAZILY from the injected EntityManagerInterface (per the
 * M3.2.X.4-B pattern, avoids eager Doctrine metadata loading at
 * service construction time, which breaks test mocks).
 *
 * Multi-vendor case: if the user owns mixed approved + suspended
 * stores, the middleware lets them through (they're a legitimate
 * vendor for at least one store). Per-controller logic then filters
 * to approved-only stores via VendorRepository::findApprovedByOwnerUser.
 */
final class VendorAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly ?\Doctrine\ORM\EntityManagerInterface $em = null,
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

        // Lifecycle gate (M3.2.X.6-B): user must own at least one
        // approved vendor. Resolved lazily, if no EM was injected
        // (legacy DI / test setup) we skip the gate to preserve
        // backwards-compatible behavior.
        if ($this->em !== null && !$this->hasApprovedVendor($user)) {
            $this->logger->warning('VendorAuthMiddleware: vendor user has no approved stores', [
                'user_id' => $user->getId(),
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ]);
            return $this->forbidden(
                ErrorCodes::VENDOR_NOT_APPROVED,
                'Your vendor account is not approved. Please wait for admin review or contact support.',
            );
        }

        return $handler->handle($request);
    }

    /**
     * Lazy resolution of VendorRepository::existsApprovedForOwnerUser.
     *
     * Defensive: catches any unexpected Doctrine/DB exceptions so the
     * middleware never returns 500 from an audit-style lookup. The
     * worst-case behavior on a DB error is denying access (returning
     * false), which is correct, better safe than letting a non-
     * approved vendor through.
     */
    private function hasApprovedVendor(User $user): bool
    {
        try {
            $repo = $this->em?->getRepository(\Bayti\Api\Domain\Catalog\Vendor::class);
            if (!$repo instanceof \Bayti\Api\Domain\Catalog\VendorRepository) {
                // Test mock returned something we can't use; preserve
                // backwards-compatible (pre-gate) behavior of letting
                // the request through. Real production wiring always
                // provides VendorRepository via Doctrine's metadata.
                return true;
            }
            return $repo->existsApprovedForOwnerUser($user);
        } catch (\Throwable $e) {
            $this->logger->error('VendorAuthMiddleware: approved-vendor lookup failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            return false;
        }
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
