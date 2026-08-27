<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Auth\Dto\LogoutInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/auth/logout
 *
 * Authenticated endpoint. Revokes a single refresh token, the
 * one tied to the device/session being logged out.
 *
 * Auth model
 * ----------
 *   - Access token in `Authorization: Bearer ...` (AuthMiddleware
 *     verifies + loads User into request attribute).
 *   - Refresh token in body (identifies WHICH session to kill).
 *
 * Why both
 * --------
 * We need the user identity (from access token) to ensure callers
 * can't revoke other users' refresh tokens. We need the refresh
 * token to know WHICH session to kill, a user can be logged in
 * on multiple devices, each with its own refresh token. The body
 * carries the specific one.
 *
 * Cross-user attack guard: we verify the refresh token's user
 * matches the access token's user. Without this, a logged-in
 * attacker who somehow knew another user's refresh token could
 * log them out at will.
 *
 * Response: 204 No Content on success.
 *
 * Idempotency
 * -----------
 * Calling /logout with an already-revoked, expired, unknown, or
 * malformed refresh token still returns 204. A logged-out user
 * shouldn't see "you were already logged out" errors. The only
 * paths that 401 are:
 *   - No access token / invalid access token (AuthMiddleware,
 *     before this controller runs)
 *   - Refresh token belongs to a DIFFERENT user (cross-user attack)
 *
 * Note: this controller does NOT also revoke the access token.
 * Access tokens are stateless; we'd need a denylist to invalidate
 * them, which we deliberately don't have (would add ~10ms per
 * request for the lookup). Instead, the access token expires on
 * its own ~15 minutes later. The access token's pwd_changed_at
 * mechanism handles the password-change case.
 *
 * If immediate access-token revocation matters (rare in practice),
 * the user can call /logout-all which doesn't fix the access-token
 * problem either, but does kill all refresh tokens so when the
 * access token expires there's no way to refresh.
 */
final class LogoutController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly JwtService $jwt,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // AuthMiddleware has already verified the access token + loaded
        // the user. Defensive null-check: if the route was wired
        // without middleware (config error), 401 rather than null-deref.
        $authedUser = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$authedUser instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $input = $this->validator->parse($request, LogoutInput::class);

        // Try to find the refresh token in DB. We don't fail-loud if
        // any of these checks fail, logout is idempotent (see class
        // docblock).
        $claims = $this->jwt->verifyRefreshToken($input->refresh_token);
        if ($claims !== null) {
            /** @var RefreshTokenRepository $refreshRepo */
            $refreshRepo = $this->em->getRepository(RefreshToken::class);
            $row = $refreshRepo->findByJti($claims->jti);

            if ($row !== null) {
                // Cross-user guard: a logged-in attacker presenting
                // someone else's refresh token MUST be rejected even
                // though logout is otherwise idempotent. The user
                // identity comes from the access token (AuthMiddleware);
                // the refresh row's user must match.
                if ($row->getUser()->getId() !== $authedUser->getId()) {
                    throw HttpException::forbidden(
                        'Cannot revoke another user\'s session.',
                    );
                }

                $row->revoke('logout');
                $this->em->flush();
            }
        }

        return $this->noContent();
    }
}
