<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Cashback;

use Bayti\Api\Domain\Cashback\CashbackRule;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CashbackSerializer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/cashback/rules   (cashback.view)
 *
 * Query: status (active|inactive|scheduled|expired), scope
 * (all|stores|products|categories), search (name contains), limit (1-100,
 * default 20), offset. Paginated envelope; each row carries budget_spent,
 * budget_remaining and awards_count (grouped queries per page, no N+1).
 */
final class ListCashbackRulesController
{
    use Responder;
    use AdminCashbackSupport;

    private const STATUSES = ['active', 'inactive', 'scheduled', 'expired'];

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CashbackSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->actor($request);
        $q = $request->getQueryParams();

        $limit = isset($q['limit']) && is_numeric($q['limit']) ? (int) $q['limit'] : 20;
        $filters = [
            'limit'  => max(1, min(100, $limit)),
            'offset' => isset($q['offset']) && is_numeric($q['offset']) ? max(0, (int) $q['offset']) : 0,
        ];
        $status = isset($q['status']) && is_string($q['status']) && $q['status'] !== '' ? $q['status'] : null;
        if ($status !== null) {
            if (!in_array($status, self::STATUSES, true)) {
                throw HttpException::validation(['status' => ['status must be one of: ' . implode(', ', self::STATUSES) . '.']]);
            }
            $filters['status'] = $status;
        }
        $scope = isset($q['scope']) && is_string($q['scope']) && $q['scope'] !== '' ? $q['scope'] : null;
        if ($scope !== null) {
            if (!in_array($scope, CashbackRule::SCOPES, true)) {
                throw HttpException::validation(['scope' => ['scope must be one of: ' . implode(', ', CashbackRule::SCOPES) . '.']]);
            }
            $filters['scope'] = $scope;
        }
        if (isset($q['search']) && is_string($q['search']) && trim($q['search']) !== '') {
            $filters['search'] = trim($q['search']);
        }

        $repo = $this->ruleRepo($this->em);
        $result = $repo->findFilteredPaginated($filters, new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $ids = [];
        foreach ($result['items'] as $r) {
            if ($r->getId() !== null) {
                $ids[] = $r->getId();
            }
        }
        $spent = $ids === [] ? [] : $repo->spentByRuleIds($ids);
        $counts = $ids === [] ? [] : $repo->awardCountsByRuleIds($ids);

        $items = array_map(
            fn (CashbackRule $r): array => $this->serializer->ruleShape($r, [
                'spent'        => $spent[(int) $r->getId()] ?? '0.00',
                'awards_count' => $counts[(int) $r->getId()] ?? 0,
            ]),
            $result['items'],
        );

        return $this->ok(PaginatedEnvelope::build($items, $result['total'], $filters['limit'], $filters['offset']));
    }
}
