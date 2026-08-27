<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationTemplate;
use Bayti\Api\Domain\Notification\NotificationTemplateRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\NotificationTemplateSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /v3/admin/notification-templates, paginated list (status + search). */
final class ListTemplatesController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly NotificationTemplateSerializer $serializer,
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
        $limit  = max(1, min(200, (int) ($q['limit'] ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        /** @var NotificationTemplateRepository $repo */
        $repo = $this->em->getRepository(NotificationTemplate::class);
        $result = $repo->findForList([
            'status' => isset($q['status']) && $q['status'] !== '' ? (string) $q['status'] : null,
            'search' => isset($q['search']) ? (string) $q['search'] : null,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $names = $this->resolveNames($result['items']);

        return $this->ok(PaginatedEnvelope::build(
            $this->serializer->shapeMany($result['items'], $names),
            $result['total'],
            $limit,
            $offset,
        ));
    }

    /**
     * @param list<NotificationTemplate> $templates
     * @return array<int, string>
     */
    private function resolveNames(array $templates): array
    {
        $ids = [];
        foreach ($templates as $t) {
            if ($t->getCreatedByUserId() !== null) {
                $ids[$t->getCreatedByUserId()] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach ($this->em->getRepository(User::class)->findBy(['id' => array_keys($ids)]) as $u) {
            $name = trim((string) $u->getFirstName() . ' ' . (string) $u->getLastName());
            $names[(int) $u->getId()] = $name !== '' ? $name : $u->getEmail();
        }
        return $names;
    }
}
