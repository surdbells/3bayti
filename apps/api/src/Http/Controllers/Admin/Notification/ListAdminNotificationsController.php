<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/notifications
 *
 * The admin's in-app notification feed (admin top-bar bell): successfully-
 * sent notifications addressed to the admin's own email, from the
 * notification_logs audit, with read-state. Replaces the legacy
 * /vendors/common/notifications call for admins.
 */
final class ListAdminNotificationsController
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

        $query = $request->getQueryParams();
        $limit = max(1, min(100, (int) ($query['limit'] ?? 30)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        /** @var NotificationLogRepository $repo */
        $repo = $this->em->getRepository(NotificationLog::class);
        $result = $repo->findFeed([$user->getEmail()], $limit, $offset, null);

        $envelope = PaginatedEnvelope::build(
            array_map([$this, 'shape'], $result['items']),
            $result['total'],
            $limit,
            $offset,
        );
        $envelope['meta']['unread'] = $result['unread'];

        return $this->ok($envelope);
    }

    /** @return array<string, mixed> */
    private function shape(NotificationLog $log): array
    {
        $orderId = $log->getOrderId();
        $suffix = $orderId !== null ? " (Order #{$orderId})" : '';

        return [
            'id'       => $log->getId(),
            'message'  => ucfirst(str_replace(['.', '_'], ' ', $log->getTemplate())) . $suffix . '.',
            'is_read'  => $log->isRead(),
            'order_id' => $orderId,
            'template' => $log->getTemplate(),
            'sent_at'  => $log->getSentAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
