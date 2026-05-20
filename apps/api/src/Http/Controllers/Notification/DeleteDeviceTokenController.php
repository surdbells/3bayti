<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Notification;

use Bayti\Api\Domain\Notification\DeviceToken;
use Bayti\Api\Domain\Notification\DeviceTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Notification\Dto\DeleteDeviceTokenInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v3/me/device-tokens  body { token }
 *
 * Deactivate the calling user's device push token (logout / opt-out).
 * After this, the device stops receiving the user's push
 * notifications.
 *
 * Owner-scoped: only deactivates a token that belongs to the current
 * user. Idempotent — an unknown or not-owned token is a no-op success
 * (204), because the caller's goal ("this token shouldn't receive my
 * pushes") is already satisfied. We never reveal whether a token
 * exists for another user.
 *
 * Soft-deactivate (is_active=false), not a row delete: preserves the
 * device history and lets a later re-registration reactivate the same
 * row.
 */
final class DeleteDeviceTokenController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
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

        $input = $this->validator->parse($request, DeleteDeviceTokenInput::class);
        /** @var string $token (validator guarantees non-blank) */
        $token = $input->token;

        /** @var DeviceTokenRepository $repo */
        $repo = $this->em->getRepository(DeviceToken::class);

        // Owner-scoped deactivate. Return value is intentionally ignored:
        // whether or not a row was deactivated, the caller's intent is
        // satisfied and we respond 204 to avoid leaking token existence.
        $repo->deactivateForUser($user, $token);

        return $this->noContent();
    }
}
