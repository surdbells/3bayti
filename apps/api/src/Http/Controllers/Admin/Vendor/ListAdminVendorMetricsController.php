<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorMetricsCalculator;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorMetricsSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/vendor-metrics?days=30&status=approved&sort=...&limit=24&offset=0
 *
 * Admin-wide vendor performance dashboard (M3.2.X.14-D). Returns
 * per-vendor metrics for a page of vendors with optional status
 * filter + sort.
 *
 * Query parameters:
 *   days     , 7-365, default 30 (clamp)
 *   status   , 'pending' | 'approved' | 'suspended' | null (no filter)
 *   sort     , 'name_asc' (default) / 'name_desc' / 'created_at_desc' /
 *               'created_at_asc' / 'fulfillment_rate_desc' /
 *               'fulfillment_rate_asc' / 'cancellation_rate_desc' /
 *               'return_rate_desc' / 'dispute_rate_desc'
 *   limit    , 1-100, default 24
 *   offset   , >=0, default 0
 *
 * Sort strategy:
 *   - Vendor-field sorts (name_*, created_at_*) push down into the
 *     DB query and paginate before computing metrics. O(page_size)
 *     metric computations.
 *   - Metric-field sorts (e.g. fulfillment_rate_desc) require
 *     computing metrics for EVERY vendor matching the status filter,
 *     then sorting in PHP, then slicing for pagination. O(N) metric
 *     computations where N = total matching vendors.
 *
 *     For 3bayti's expected scale (≤200 vendors) this is fine -
 *     computeForVendorList runs 3 queries regardless of vendor count.
 *     Flagged as operator follow-up #18 (cache-warming on demand
 *     when scale crosses ~500 vendors).
 *
 * Authorization: admin-only (group middleware). Audit ACTION_VIEWED
 * emitted on every successful call with the admin as subject (list
 * views have no single subject entity).
 */
final class ListAdminVendorMetricsController
{
    use Responder;

    private const DEFAULT_LIMIT = 24;
    private const MAX_LIMIT = 100;

    private const VENDOR_FIELD_SORTS = [
        'name_asc',
        'name_desc',
        'created_at_asc',
        'created_at_desc',
    ];

    private const METRIC_SORTS = [
        'fulfillment_rate_desc',
        'fulfillment_rate_asc',
        'cancellation_rate_desc',
        'cancellation_rate_asc',
        'return_rate_desc',
        'return_rate_asc',
        'dispute_rate_desc',
        'dispute_rate_asc',
    ];

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorMetricsCalculator $calculator,
        private readonly VendorMetricsSerializer $serializer,
        private readonly AuditEmitter $audit,
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
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $windowDays = $this->parseWindowDays($query['days'] ?? null);
        $status = $this->parseStatus($query['status'] ?? null);
        $sort = $this->parseSort($query['sort'] ?? null);
        $limit = $this->clampLimit($query['limit'] ?? null);
        $offset = $this->clampOffset($query['offset'] ?? null);

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);

        // Route by sort strategy
        if (in_array($sort, self::METRIC_SORTS, true)) {
            [$pagedVendors, $metricsByVendorId, $total, $window] =
                $this->computeWithMetricSort($vendorRepo, $status, $sort, $windowDays, $limit, $offset);
        } else {
            [$pagedVendors, $metricsByVendorId, $total, $window] =
                $this->computeWithVendorFieldSort($vendorRepo, $status, $sort, $windowDays, $limit, $offset);
        }

        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $user,
            context: [
                'context' => 'admin_vendor_metrics_list',
                'filters' => array_filter([
                    'days' => $windowDays,
                    'status' => $status,
                    'sort' => $sort,
                    'limit' => $limit,
                    'offset' => $offset,
                ], static fn ($v) => $v !== null),
                'result_count' => count($pagedVendors),
                'total' => $total,
            ],
        );

        return $this->ok($this->serializer->listShape(
            $pagedVendors,
            $metricsByVendorId,
            $total,
            $limit,
            $offset,
            $window,
        ));
    }

    /**
     * Vendor-field sort path: DB-level pagination, metrics computed
     * only for the page.
     *
     * @return array{0: list<Vendor>, 1: array<int, array<string, mixed>>, 2: int, 3: array{days: int, since: string, until: string}}
     */
    private function computeWithVendorFieldSort(
        VendorRepository $vendorRepo,
        ?string $status,
        string $sort,
        int $windowDays,
        int $limit,
        int $offset,
    ): array {
        [$vendors, $total] = $vendorRepo->findPaginatedForAdmin($limit, $offset, $status, $sort);
        $vendorIds = array_values(array_filter(
            array_map(static fn (Vendor $v): ?int => $v->getId(), $vendors),
            static fn (?int $id): bool => $id !== null,
        ));

        $metricsByVendorId = $this->calculator->computeForVendorList($vendorIds, $windowDays);
        $window = $this->extractWindow($metricsByVendorId, $windowDays);

        return [$vendors, $metricsByVendorId, $total, $window];
    }

    /**
     * Metric-field sort path: fetch ALL matching vendor ids, compute
     * metrics for all of them, sort by the chosen metric in PHP,
     * then slice for pagination. Scales linearly with vendor count;
     * acceptable for ≤200 vendors (current 3bayti scale).
     *
     * @return array{0: list<Vendor>, 1: array<int, array<string, mixed>>, 2: int, 3: array{days: int, since: string, until: string}}
     */
    private function computeWithMetricSort(
        VendorRepository $vendorRepo,
        ?string $status,
        string $sort,
        int $windowDays,
        int $limit,
        int $offset,
    ): array {
        $allIds = $vendorRepo->findAllIdsForAdmin($status);
        $total = count($allIds);

        if ($allIds === []) {
            $emptyWindow = $this->emptyWindow($windowDays);
            return [[], [], 0, $emptyWindow];
        }

        $allMetrics = $this->calculator->computeForVendorList($allIds, $windowDays);
        $window = $this->extractWindow($allMetrics, $windowDays);

        // Sort the ids by the chosen metric value. null values sort
        // LAST (vendors with no data should appear at the bottom
        // regardless of asc/desc, they're not actually "the worst"
        // or "the best"; they're "unknown").
        $sortedIds = $this->sortIdsByMetric($allIds, $allMetrics, $sort);

        // Slice for pagination
        $pagedIds = array_slice($sortedIds, $offset, $limit);

        // Hydrate Vendor entities for the page (preserving sorted order)
        $vendors = $this->hydrateVendorsPreservingOrder($vendorRepo, $pagedIds);

        // Filter $allMetrics down to just the page (so the serializer
        // doesn't carry unused entries)
        $pagedMetrics = [];
        foreach ($pagedIds as $vid) {
            if (isset($allMetrics[$vid])) {
                $pagedMetrics[$vid] = $allMetrics[$vid];
            }
        }

        return [$vendors, $pagedMetrics, $total, $window];
    }

    /**
     * @param list<int> $allIds
     * @param array<int, array{metrics: array<string, array<string, mixed>>}> $allMetrics
     * @return list<int>
     */
    private function sortIdsByMetric(array $allIds, array $allMetrics, string $sort): array
    {
        [$metricKey, $direction] = $this->parseMetricSort($sort);

        usort($allIds, function (int $a, int $b) use ($allMetrics, $metricKey, $direction): int {
            $aVal = $allMetrics[$a]['metrics'][$metricKey]['value'] ?? null;
            $bVal = $allMetrics[$b]['metrics'][$metricKey]['value'] ?? null;

            // null sorts to the END regardless of direction
            if ($aVal === null && $bVal === null) {
                return 0;
            }
            if ($aVal === null) {
                return 1;   // a after b
            }
            if ($bVal === null) {
                return -1;  // a before b
            }

            $cmp = $aVal <=> $bVal;
            return $direction === 'desc' ? -$cmp : $cmp;
        });

        return $allIds;
    }

    /**
     * Parse 'fulfillment_rate_desc' → ['fulfillment_rate', 'desc'].
     *
     * @return array{0: string, 1: string}
     */
    private function parseMetricSort(string $sort): array
    {
        // Suffix is always _asc or _desc; metric key is everything before.
        if (str_ends_with($sort, '_desc')) {
            return [substr($sort, 0, -5), 'desc'];
        }
        if (str_ends_with($sort, '_asc')) {
            return [substr($sort, 0, -4), 'asc'];
        }
        // Unreachable given parseSort validation, but defensive.
        return [$sort, 'asc'];
    }

    /**
     * Re-fetch Vendor entities and reorder them to match the supplied
     * id sequence. A naive findBy(['id' => $ids]) returns rows in
     * arbitrary order; we need them in sort-key order so the response
     * preserves the chosen sort.
     *
     * @param list<int> $orderedIds
     * @return list<Vendor>
     */
    private function hydrateVendorsPreservingOrder(VendorRepository $vendorRepo, array $orderedIds): array
    {
        if ($orderedIds === []) {
            return [];
        }
        /** @var list<Vendor> $rows */
        $rows = $vendorRepo->findBy(['id' => $orderedIds]);

        // Reorder
        $byId = [];
        foreach ($rows as $v) {
            $vid = $v->getId();
            if ($vid !== null) {
                $byId[$vid] = $v;
            }
        }
        $ordered = [];
        foreach ($orderedIds as $vid) {
            if (isset($byId[$vid])) {
                $ordered[] = $byId[$vid];
            }
        }
        return $ordered;
    }

    /**
     * Extract the window block from any vendor entry (they all share
     * the same window for a given compute call).
     *
     * @param array<int, array{window?: array{days: int, since: string, until: string}}> $metrics
     * @return array{days: int, since: string, until: string}
     */
    private function extractWindow(array $metrics, int $windowDays): array
    {
        foreach ($metrics as $entry) {
            if (isset($entry['window'])) {
                return $entry['window'];
            }
        }
        return $this->emptyWindow($windowDays);
    }

    /**
     * @return array{days: int, since: string, until: string}
     */
    private function emptyWindow(int $windowDays): array
    {
        $until = new \DateTimeImmutable();
        $since = $until->modify("-{$windowDays} days");
        return [
            'days' => $windowDays,
            'since' => $since->format(\DateTimeInterface::ATOM),
            'until' => $until->format(\DateTimeInterface::ATOM),
        ];
    }

    private function parseWindowDays(mixed $raw): int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return VendorMetricsCalculator::DEFAULT_WINDOW_DAYS;
        }
        $rawStr = (string) $raw;
        if (!is_numeric($rawStr)) {
            return VendorMetricsCalculator::DEFAULT_WINDOW_DAYS;
        }
        $days = (int) $rawStr;
        if ($days < VendorMetricsCalculator::MIN_WINDOW_DAYS) {
            return VendorMetricsCalculator::MIN_WINDOW_DAYS;
        }
        if ($days > VendorMetricsCalculator::MAX_WINDOW_DAYS) {
            return VendorMetricsCalculator::MAX_WINDOW_DAYS;
        }
        return $days;
    }

    private function parseStatus(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $allowed = [Vendor::STATUS_PENDING, Vendor::STATUS_APPROVED, Vendor::STATUS_SUSPENDED];
        return in_array($raw, $allowed, true) ? $raw : null;
    }

    private function parseSort(mixed $raw): string
    {
        if (!is_string($raw) || $raw === '') {
            return 'name_asc';
        }
        $valid = array_merge(self::VENDOR_FIELD_SORTS, self::METRIC_SORTS);
        return in_array($raw, $valid, true) ? $raw : 'name_asc';
    }

    private function clampLimit(mixed $raw): int
    {
        if (!is_string($raw) && !is_int($raw)) {
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
        if (!is_string($raw) && !is_int($raw)) {
            return 0;
        }
        return max(0, (int) $raw);
    }
}
