<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\GiftReminder;

use Bayti\Api\Domain\GiftReminder\GiftReminder;
use Bayti\Api\Domain\GiftReminder\GiftReminderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\GiftReminder\Dto\CreateGiftReminderInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\GiftReminderSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/gift-reminders — create a gift reminder for the signed-in user.
 */
final class CreateGiftReminderController
{
    use Responder;

    private const MAX_PER_USER = 100;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
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

        $input = $this->validator->parse($request, CreateGiftReminderInput::class);

        /** @var GiftReminderRepository $reminders */
        $reminders = $this->em->getRepository(GiftReminder::class);
        if ($reminders->countForUser($user) >= self::MAX_PER_USER) {
            throw HttpException::validation([
                '_root' => [sprintf('Cannot save more than %d gift reminders.', self::MAX_PER_USER)],
            ]);
        }

        $reminder = new GiftReminder(
            user: $user,
            recipientName: $input->recipient_name,
            occasion: $input->occasion,
            remindDate: new \DateTimeImmutable($input->remind_date),
            note: $input->note,
            budgetMax: $input->budget_max,
            categorySlug: $input->category_slug,
        );
        $reminders->save($reminder);

        return $this->created(['gift_reminder' => $this->serializer->publicShape($reminder)]);
    }
}
