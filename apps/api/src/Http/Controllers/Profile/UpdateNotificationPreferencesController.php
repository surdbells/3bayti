<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Profile;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Profile\Dto\UpdateNotificationPreferencesInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /v3/me/notification-preferences
 *
 * Toggle the authenticated user's marketing PUSH opt-out flag.
 *
 * Request body
 * ------------
 *   { "marketing_opt_out": true }   // opt OUT of marketing push
 *   { "marketing_opt_out": false }  // opt back IN
 *
 * marketing_opt_out is REQUIRED (422 if missing). This endpoint exists
 * only to set the flag, so an empty body has no meaningful behaviour.
 *
 * Scope
 * -----
 * The flag suppresses the THREE marketing pushes (cart.abandoned,
 * order.review_prompt, re_engagement.nudge). Order-lifecycle pushes are
 * transactional and IGNORE this flag.
 *
 * Response
 * --------
 * 200 OK with the full refreshed profile (same shape as GET
 * /v3/me/profile), so the client can reflect the new flag without a
 * second round-trip:
 *
 *   { "user": { ... , "marketing_opt_out": true } }
 *
 * Authentication
 * --------------
 * AuthMiddleware (on the /v3/me group) attaches the User. Defensive 401
 * if absent.
 */
final class UpdateNotificationPreferencesController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
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

        $input = $this->validator->parse($request, UpdateNotificationPreferencesInput::class);

        // Validated non-null by the DTO's #[Assert\NotNull]; cast for
        // the strict bool setter signature.
        $user->setMarketingPushOptOut((bool) $input->marketing_opt_out);
        $this->em->flush();

        return $this->ok([
            'user' => $this->userSerializer->publicProfile($user),
        ]);
    }
}
