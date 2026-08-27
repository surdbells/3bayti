<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/auth/logout-all
 *
 * Authenticated endpoint. Revokes every refresh token belonging to
 * the calling user.
 *
 * Use cases
 * ---------
 *   - "Logout from all devices" UI option after a security scare
 *   - Account-recovery flow when the user suspects their device was
 *     compromised but they still have access to ONE device
 *
 * No body required; the access token in Authorization is the
 * identifier. Returns 204 on success.
 *
 * Response (204), no body.
 *
 * Side effects
 * ------------
 *   - revokeAllForUser(user, 'logout_all') sets revoked_at on every
 *     row where user matches AND revoked_at IS NULL. After this,
 *     every refresh token the user holds (including the one the
 *     calling client is using) is invalid; the next /refresh call
 *     from any device returns 401.
 *
 *   - Access tokens are NOT revoked here (same reasoning as /logout):
 *     they're stateless. The user remains authenticated for the
 *     rest of their access token's TTL (~15 minutes max). If
 *     immediate access-token revocation matters, the user should
 *     also change their password (which bumps password_changed_at
 *     and invalidates access tokens via AuthMiddleware staleness
 *     check).
 *
 * Why no separate /logout-all-and-change-password
 * -----------------------------------------------
 * That's just /reset/confirm, already does both. /logout-all is
 * for the "I trust my password but want to reset all sessions" case.
 */
final class LogoutAllController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        /** @var RefreshTokenRepository $refreshRepo */
        $refreshRepo = $this->em->getRepository(RefreshToken::class);
        $refreshRepo->revokeAllForUser($user, 'logout_all');

        return $this->noContent();
    }
}
