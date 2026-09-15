<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\GiftReminder;

use Bayti\Api\Domain\GiftReminder\GiftReminder;
use Bayti\Api\Domain\GiftReminder\GiftReminderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\GiftReminderSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/me/gift-reminders — the signed-in user's saved gift reminders.
 */
final class ListGiftRemindersController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly GiftReminderSerializer $serializer,
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
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        /** @var GiftReminderRepository $reminders */
        $reminders = $this->em->getRepository(GiftReminder::class);

        return $this->ok([
            'gift_reminders' => $this->serializer->publicShapeMany($reminders->findAllForUser($user)),
        ]);
    }
}
