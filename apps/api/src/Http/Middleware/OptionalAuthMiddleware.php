<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Middleware;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Optional authentication middleware.
 *
 * Like AuthMiddleware, but never returns 401 — instead, it just
 * doesn't set the `user` and `claims` attributes when no valid
 * token is present. Downstream handlers must check whether the
 * attribute is null.
 *
 * Use cases
 * ---------
 * Endpoints that work for both signed-in and anonymous visitors but
 * personalise when authenticated. Examples (all M2/M3):
 *
 *   GET /v3/products/:slug    — fetch product. Anonymous gets the
 *                                 raw product; authed user gets the
 *                                 same plus their wishlist state for
 *                                 this product, their measurement-
 *                                 size suggestion, etc.
 *
 *   GET /v3/cart              — anonymous returns 401? No — better
 *                                 to return their guest-cart state
 *                                 (server holds nothing; client uses
 *                                 the local-first cart from Phase 2).
 *                                 Authed returns the merged server cart.
 *
 * For endpoints that ALWAYS require auth, use AuthMiddleware instead.
 */
final class OptionalAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly JwtService $jwt,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            // No header at all — proceed anonymous.
            return $handler->handle($request);
        }

        $claims = $this->jwt->verifyAccessToken($token);
        if ($claims === null) {
            // Token was present but invalid — silently drop it and
            // proceed anonymous. Don't 401, don't 400 — anonymous
            // requests are valid for these endpoints.
            return $handler->handle($request);
        }

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $user = $users->findById($claims->userId);
        if ($user === null || !$user->isActive()) {
            return $handler->handle($request);
        }

        // pwd_changed_at staleness — same logic as AuthMiddleware.
        $userPwdChanged = $user->getPasswordChangedAt();
        $tokenPwdChanged = $claims->passwordChangedAt;
        if ($userPwdChanged !== null) {
            if ($tokenPwdChanged === null || $tokenPwdChanged < $userPwdChanged) {
                return $handler->handle($request);
            }
        }

        // Attribute audit-log rows for this request to the resolved user.
        \Bayti\Api\Domain\Audit\AuditContext::setActor($user->getId());

        // All checks passed — decorate request with user + claims.
        $request = $request
            ->withAttribute(AuthMiddleware::ATTR_USER, $user)
            ->withAttribute(AuthMiddleware::ATTR_CLAIMS, $claims);

        return $handler->handle($request);
    }

    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            return null;
        }
        if (!preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $header, $matches)) {
            return null;
        }
        return $matches[1];
    }
}
