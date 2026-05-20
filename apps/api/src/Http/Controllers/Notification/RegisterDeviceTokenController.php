<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Notification;

use Bayti\Api\Domain\Notification\DeviceToken;
use Bayti\Api\Domain\Notification\DeviceTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Notification\Dto\RegisterDeviceTokenInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\DeviceTokenSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/device-tokens  body { token, platform }
 *
 * Register (upsert) the calling user's device push token so the
 * backend can target this device with push notifications.
 *
 * Idempotent by token: re-registering an existing token refreshes it
 * (reactivate + re-own to the current user + re-stamp last_seen_at)
 * rather than creating duplicates. The mobile app calls this on every
 * launch / token refresh, so it must be cheap and idempotent.
 *
 * Returns the token's metadata (NOT the token string itself) in the
 * standard { data: ... } envelope. A brand-new registration returns
 * 201; refreshing an existing one returns 200.
 */
final class RegisterDeviceTokenController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly DeviceTokenSerializer $serializer,
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

        $input = $this->validator->parse($request, RegisterDeviceTokenInput::class);
        /** @var string $token (validator guarantees non-blank) */
        $token = $input->token;
        /** @var string $platform (validator guarantees a valid choice) */
        $platform = $input->platform;

        /** @var DeviceTokenRepository $repo */
        $repo = $this->em->getRepository(DeviceToken::class);

        // Was this token already known? Drives 200-vs-201 (the upsert
        // itself is idempotent either way).
        $isNew = $repo->findOneByToken($token) === null;

        $entity = $repo->register($user, $token, $platform);

        $body = PaginatedEnvelope::single($this->serializer->publicShape($entity));

        return $isNew ? $this->created($body) : $this->ok($body);
    }
}
