<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\GiftReminder;

use Bayti\Api\Domain\GiftReminder\GiftReminder;
use Bayti\Api\Domain\GiftReminder\GiftReminderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\GiftReminder\Dto\UpdateGiftReminderInput;
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
 * PUT /v3/me/gift-reminders/{id} — full-replace update. IDOR-safe (404 when the
 * reminder isn't the caller's). Changing the date re-arms the nudge stages.
 */
final class UpdateGiftReminderController
{
    use Responder;

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

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $idRaw = (string) ($args['id'] ?? '');
        if (!ctype_digit($idRaw)) {
            throw HttpException::notFound('Gift reminder not found.');
        }

        /** @var GiftReminderRepository $reminders */
        $reminders = $this->em->getRepository(GiftReminder::class);
        $reminder = $reminders->find((int) $idRaw);
        if ($reminder === null || $reminder->getUser()->getId() !== $user->getId()) {
            throw HttpException::notFound('Gift reminder not found.');
        }

        $input = $this->validator->parse($request, UpdateGiftReminderInput::class);

        $reminder->update(
            recipientName: $input->recipient_name,
            occasion: $input->occasion,
            remindDate: new \DateTimeImmutable($input->remind_date),
            note: $input->note,
            budgetMax: $input->budget_max,
            categorySlug: $input->category_slug,
        );
        $reminders->save($reminder);

        return $this->ok(['gift_reminder' => $this->serializer->publicShape($reminder)]);
    }
}
