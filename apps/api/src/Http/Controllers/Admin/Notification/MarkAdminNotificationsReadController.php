<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
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
 * POST /v3/admin/notifications/mark-read
 *
 * Marks the admin's notifications read (body { "ids": [...] } for specific
 * ones, omit to mark all unread). Replaces the legacy
 * /vendors/common/mark_notifications call for admins.
 */
final class MarkAdminNotificationsReadController
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $ids = null;
        if (isset($body['ids']) && is_array($body['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $body['ids']), static fn (int $i): bool => $i > 0));
        }

        /** @var NotificationLogRepository $repo */
        $repo = $this->em->getRepository(NotificationLog::class);
        $marked = $repo->markFeedRead([$user->getEmail()], $ids, null);

        return $this->ok(['data' => ['marked' => $marked]]);
    }
}
