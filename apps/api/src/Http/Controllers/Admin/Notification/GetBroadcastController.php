<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationBroadcast;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\NotificationBroadcastSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/notification-broadcasts/{id}
 *
 * Full broadcast detail: message, audience, delivery + failure stats, and
 * the per-platform device breakdown. Recipient-level rows are a separate
 * paginated endpoint.
 */
final class GetBroadcastController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly NotificationBroadcastSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $_r, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $broadcast = $id > 0 ? $this->em->getRepository(NotificationBroadcast::class)->find($id) : null;
        if (!$broadcast instanceof NotificationBroadcast) {
            throw HttpException::notFound('Broadcast not found.');
        }

        $names = [];
        if ($broadcast->getSentByUserId() !== null) {
            $u = $this->em->getRepository(User::class)->find($broadcast->getSentByUserId());
            if ($u instanceof User) {
                $name = trim((string) $u->getFirstName() . ' ' . (string) $u->getLastName());
                $names[(int) $u->getId()] = $name !== '' ? $name : $u->getEmail();
            }
        }

        return $this->ok(['data' => $this->serializer->detailShape($broadcast, $names)]);
    }
}
