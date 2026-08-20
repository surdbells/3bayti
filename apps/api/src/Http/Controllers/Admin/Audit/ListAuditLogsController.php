<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Audit;

use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Audit\AuditLogRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\AuditLogSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/audit-logs
 *
 * Paginated forensic view of the append-only audit_log table. Newest first.
 * Gated on `audit.view`.
 *
 * Filters (all optional):
 *   action        one of AuditLog::ALL_ACTIONS
 *   subject_type  entity basename (e.g. 'User', 'Vendor', 'Order')
 *   user_id       actor user id
 *   subject_id    changed entity id
 *   date_from     YYYY-MM-DD (inclusive, UTC)
 *   date_to       YYYY-MM-DD (inclusive, UTC)
 *   limit 1-100 (default 25), offset 0+
 *
 * The actor name/email is denormalised per page (batch user load) so the list
 * is one query for rows + one for actors — no N+1.
 */
final class ListAuditLogsController
{
    use Responder;

    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 100;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogSerializer $serializer,
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
        $limit = $this->clampLimit($query['limit'] ?? null);
        $offset = $this->clampOffset($query['offset'] ?? null);
        $action = $this->parseAction($query['action'] ?? null);
        $subjectType = $this->parseString($query['subject_type'] ?? null, 50);
        $userId = $this->parsePositiveInt($query['user_id'] ?? null);
        $subjectId = $this->parsePositiveInt($query['subject_id'] ?? null);
        $dateFrom = $this->parseDate($query['date_from'] ?? null);
        $dateTo = $this->parseDate($query['date_to'] ?? null);

        /** @var AuditLogRepository $repo */
        $repo = $this->em->getRepository(AuditLog::class);
        [$rows, $total] = $repo->paginated($limit, $offset, $action, $subjectType, $userId, $subjectId, $dateFrom, $dateTo);

        $actors = $this->loadActors($rows);
        $items = array_map(
            fn (AuditLog $log): array => $this->serializer->shape($log, $actors),
            $rows,
        );

        return $this->ok([
            'logs' => $items,
            'actions' => AuditLog::ALL_ACTIONS,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($items),
                'total' => $total,
            ],
        ]);
    }

    /**
     * Batch-load the distinct actor users for the page.
     *
     * @param AuditLog[] $rows
     * @return array<int, array{name: string|null, email: string|null}>
     */
    private function loadActors(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $uid = $row->getUserId();
            if ($uid !== null) {
                $ids[$uid] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $users = $this->em->getRepository(User::class)->findBy(['id' => array_keys($ids)]);
        $actors = [];
        foreach ($users as $u) {
            /** @var User $u */
            $name = trim(($u->getFirstName() ?? '') . ' ' . ($u->getLastName() ?? ''));
            $actors[(int) $u->getId()] = [
                'name' => $name !== '' ? $name : null,
                'email' => $u->getEmail(),
            ];
        }
        return $actors;
    }

    private function clampLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        $n = (int) $raw;
        if ($n < 1) {
            return self::DEFAULT_LIMIT;
        }
        return min($n, self::MAX_LIMIT);
    }

    private function clampOffset(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        return max(0, (int) $raw);
    }

    private function parseAction(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return in_array($raw, AuditLog::ALL_ACTIONS, true) ? $raw : null;
    }

    private function parseString(mixed $raw, int $max): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $t = trim($raw);
        return $t === '' ? null : mb_substr($t, 0, $max);
    }

    private function parsePositiveInt(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $n = (int) $raw;
        return $n > 0 ? $n : null;
    }

    private function parseDate(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $d !== false ? $d->format('Y-m-d') : null;
    }
}
