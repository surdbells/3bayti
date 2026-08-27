<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Profile;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/me/profile
 *
 * Returns the authenticated user's full profile, same shape that
 * /v3/auth/me returns. The two endpoints exist as separate URIs for
 * clarity:
 *
 *   /v3/auth/me     , "who am I" (used by routing/onboarding logic)
 *   /v3/me/profile  , "show me my profile" (used by the profile page)
 *
 * Both currently delegate to UserSerializer::publicProfile(). If/when
 * we want them to diverge (e.g., /me/profile shows extended fields
 * that /auth/me doesn't) we add a second serializer method.
 *
 * Authentication
 * --------------
 * AuthMiddleware (registered on the route in config/routes.php) loads
 * the User by JWT subject and attaches it to the request before this
 * controller runs. If the User isn't present, AuthMiddleware was
 * bypassed somehow, defensive check rejects with 401.
 */
final class GetProfileController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly UserSerializer $userSerializer,
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

        return $this->ok([
            'user' => $this->userSerializer->publicProfile($user),
        ]);
    }
}
