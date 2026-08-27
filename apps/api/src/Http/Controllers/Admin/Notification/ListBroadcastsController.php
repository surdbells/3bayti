<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationBroadcast;
use Bayti\Api\Domain\Notification\NotificationBroadcastRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\NotificationBroadcastSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/notification-broadcasts
 *
 * Paginated broadcast history (Compose "Send Now" results + future
 * scheduled occurrences). Summary rows only, the detail + recipient
 * drill-down live on separate endpoints. Filters: status, search (title).
 */
final class ListBroadcastsController
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
        /** @var array<string, mixed> $q */
        $q = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($q['limit'] ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        /** @var NotificationBroadcastRepository $repo */
        $repo = $this->em->getRepository(NotificationBroadcast::class);
        $result = $repo->findForHistory([
            'status' => isset($q['status']) && $q['status'] !== '' ? (string) $q['status'] : null,
            'search' => isset($q['search']) ? (string) $q['search'] : null,
            'schedule_id' => isset($q['schedule_id']) && $q['schedule_id'] !== '' ? (int) $q['schedule_id'] : null,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $names = $this->resolveSenderNames($result['items']);

        $envelope = PaginatedEnvelope::build(
            $this->serializer->historyShapeMany($result['items'], $names),
            $result['total'],
            $limit,
            $offset,
        );

        return $this->ok($envelope);
    }

    /**
     * Batch-resolve sent_by ids → display name (single query, no N+1).
     *
     * @param list<NotificationBroadcast> $broadcasts
     * @return array<int, string>
     */
    private function resolveSenderNames(array $broadcasts): array
    {
        $ids = [];
        foreach ($broadcasts as $b) {
            if ($b->getSentByUserId() !== null) {
                $ids[$b->getSentByUserId()] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        /** @var list<User> $users */
        $users = $this->em->getRepository(User::class)->findBy(['id' => array_keys($ids)]);
        $names = [];
        foreach ($users as $u) {
            $name = trim((string) $u->getFirstName() . ' ' . (string) $u->getLastName());
            $names[(int) $u->getId()] = $name !== '' ? $name : $u->getEmail();
        }
        return $names;
    }
}
