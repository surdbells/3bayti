<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationSchedule;
use Bayti\Api\Domain\Notification\NotificationScheduleRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\NotificationScheduleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /v3/admin/notification-schedules, paginated schedule list. */
final class ListSchedulesController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly NotificationScheduleSerializer $serializer,
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

        /** @var NotificationScheduleRepository $repo */
        $repo = $this->em->getRepository(NotificationSchedule::class);
        $result = $repo->findForList([
            'status' => isset($q['status']) && $q['status'] !== '' ? (string) $q['status'] : null,
            'search' => isset($q['search']) ? (string) $q['search'] : null,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $ids = [];
        foreach ($result['items'] as $s) {
            if ($s->getCreatedByUserId() !== null) {
                $ids[$s->getCreatedByUserId()] = true;
            }
        }
        $names = [];
        if ($ids !== []) {
            foreach ($this->em->getRepository(User::class)->findBy(['id' => array_keys($ids)]) as $u) {
                $name = trim((string) $u->getFirstName() . ' ' . (string) $u->getLastName());
                $names[(int) $u->getId()] = $name !== '' ? $name : $u->getEmail();
            }
        }

        return $this->ok(PaginatedEnvelope::build(
            $this->serializer->shapeMany($result['items'], $names),
            $result['total'],
            $limit,
            $offset,
        ));
    }
}
