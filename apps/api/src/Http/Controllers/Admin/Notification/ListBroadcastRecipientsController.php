<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationBroadcast;
use Bayti\Api\Domain\Notification\NotificationBroadcastRecipient;
use Bayti\Api\Domain\Notification\NotificationBroadcastRecipientRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\NotificationBroadcastSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/notification-broadcasts/{id}/recipients
 *
 * Paginated recipient-level delivery drill-down for one broadcast. Filter
 * by status (pending|sent|failed), platform (ios|android), and a
 * token-suffix / user-id search.
 */
final class ListBroadcastRecipientsController
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

        /** @var array<string, mixed> $q */
        $q = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($q['limit'] ?? 25)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        /** @var NotificationBroadcastRecipientRepository $repo */
        $repo = $this->em->getRepository(NotificationBroadcastRecipient::class);
        $result = $repo->findForBroadcastPaginated($id, [
            'status' => isset($q['status']) && $q['status'] !== '' ? (string) $q['status'] : null,
            'platform' => isset($q['platform']) && $q['platform'] !== '' ? (string) $q['platform'] : null,
            'search' => isset($q['search']) ? (string) $q['search'] : null,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $envelope = PaginatedEnvelope::build(
            $this->serializer->recipientShapeMany($result['items']),
            $result['total'],
            $limit,
            $offset,
        );

        return $this->ok($envelope);
    }
}
