<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

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
 * GET /v3/auth/me
 *
 * Return the authenticated user's profile. Used by the frontend on
 * page load to populate the user state when an existing access
 * token is found in storage but the page has reloaded.
 *
 * Authentication
 * --------------
 * Wrapped in AuthMiddleware (registered on the route, see
 * config/routes.php). By the time this controller runs, the
 * middleware has already:
 *   - Verified the JWT signature, audience, expiry
 *   - Loaded the User row by ID
 *   - Confirmed is_active and pwd_changed_at staleness
 *   - Attached the User to the request under AuthMiddleware::ATTR_USER
 *
 * So this controller is JUST a serializer — no DB calls, no further
 * checks.
 *
 * If the request reaches this controller without a User attribute,
 * something has bypassed AuthMiddleware (configuration error). We
 * fail safe with a 401 rather than risk a null-deref.
 */
final class MeController
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
            // Defensive: AuthMiddleware should have placed a User
            // here. If it didn't, the route was wired without the
            // middleware — that's a config bug, not user error.
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
