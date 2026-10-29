<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Auth\Dto\LogoutInput;
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
 * Ends ONE device's session: the whole session family of the refresh
 * token in the body. Other devices are never affected.
 *
 * Auth model
 * ----------
 *   - Refresh token in the body (REQUIRED). It identifies WHICH session
 *     to end, and possessing it is the proof of ownership: whoever holds
 *     a valid refresh token can already act as that session.
 *   - Access token in `Authorization: Bearer ...` (OPTIONAL, the route
 *     runs OptionalAuthMiddleware). Clients call logout fire-and-forget
 *     right after clearing their local session, often with an expired
 *     or missing access token; requiring one would leave the session
 *     alive server-side whenever the access token had lapsed.
 *
 * Cross-user guard: when a VALID bearer is present and the refresh
 * token belongs to a different user, 403. A signed-in caller can't end
 * someone else's session with a token they somehow obtained.
 *
 * Why the whole family
 * --------------------
 * A token revoked by rotation stays re-issuable (RefreshController), so
 * revoking only the presented token would let an older token of the
 * same device bring the session back. revokeFamily() ends every token
 * the device has ever held, and nothing else.
 *
 * Response: 204 No Content.
 *
 * Idempotency
 * -----------
 * Unknown, malformed, expired or already-revoked refresh tokens still
 * return 204: a signed-out user never sees "you were already logged
 * out". The only non-204 paths are the cross-user 403 and a 422 for a
 * missing body field.
 *
 * Note: this controller does NOT revoke the access token. Access tokens
 * are stateless (no denylist, it would add a DB lookup to every request)
 * and expire on their own within ~15 minutes.
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
        // Set by OptionalAuthMiddleware only for a valid bearer; absent
        // (null) when there is no header or the access token is invalid
        // or expired, which is fine, see class docblock.
        $authedUser = $request->getAttribute(AuthMiddleware::ATTR_USER);

        $input = $this->validator->parse($request, LogoutInput::class);

        // Any failed check below just falls through to 204, logout is
        // idempotent (see class docblock).
        $claims = $this->jwt->verifyRefreshToken($input->refresh_token);
        if ($claims === null) {
            return $this->noContent();
        }

        /** @var RefreshTokenRepository $refreshRepo */
        $refreshRepo = $this->em->getRepository(RefreshToken::class);
        $row = $refreshRepo->findByJti($claims->jti);
        if ($row === null || !$row->matchesToken($input->refresh_token)) {
            return $this->noContent();
        }

        // Cross-user guard: a signed-in caller presenting someone else's
        // refresh token MUST be rejected even though logout is otherwise
        // idempotent.
        if ($authedUser instanceof User && $row->getUser()->getId() !== $authedUser->getId()) {
            throw HttpException::forbidden(
                'Cannot revoke another user\'s session.',
            );
        }

        // End this device's session family. Already-ended tokens are left
        // as they are, so repeating the call is harmless.
        $refreshRepo->revokeFamily($row->getFamilyId(), 'logout');

        return $this->noContent();
    }
}
