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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v3/me/gift-reminders/{id} — hard-delete. IDOR-safe (404 when not the
 * caller's). Returns 204.
 */
final class DeleteGiftReminderController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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

        $reminders->remove($reminder);

        return $this->noContent();
    }
}
