<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorMetricsCalculator;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Vendor\ListAdminVendorMetricsController;
use Bayti\Api\Http\Serializers\VendorMetricsSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * HTTP-level tests for GET /v3/admin/vendor-metrics (M3.2.X.14-D).
 *
 * Two sort code paths to cover separately:
 *   - Vendor-field sort (default name_asc): DB-level pagination,
 *     metrics computed only for the page.
 *   - Metric-field sort (?sort=fulfillment_rate_desc): compute
 *     metrics for ALL matching vendors, sort in PHP, slice for page.
 *
 * Both paths verify the response envelope and the auth/audit posture.
 */
#[CoversClass(ListAdminVendorMetricsController::class)]
#[CoversClass(VendorMetricsSerializer::class)]
final class ListAdminVendorMetricsControllerTest extends HttpTestCase
{
    /** @var list<AuditLog> */
    private array $recordedAuditLogs = [];

    /** @var list<int> Captured vendor_ids forwarded to computeForVendorList */
    private array $capturedListVendorIds = [];

    /** @var int Captured window_days forwarded to the calculator */
    private int $capturedWindowDays = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
        $this->capturedListVendorIds = [];
        $this->capturedWindowDays = 0;
    }

    // =================================================================
    // Vendor-field sort path
    // =================================================================

    #[Test]
    public function defaultNameAscSortReturnsPagedVendorsWithMetrics(): void
    {
        $admin = $this->makeAdminUser(99);
        $v1 = $this->makeVendor(101, 'almas', 'Almas');
        $v2 = $this->makeVendor(202, 'noor', 'Noor');
        $this->bindDeps($admin, paginatedVendors: [$v1, $v2], total: 2, allIds: [101, 202]);

        $response = $this->makeGet($admin, '/v3/admin/vendor-metrics');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);

        // Both vendors in data, sort-order preserved
        self::assertCount(2, $body['data']);
        self::assertSame(101, $body['data'][0]['vendor_id']);
        self::assertSame('Almas', $body['data'][0]['vendor_name']);
        self::assertSame(202, $body['data'][1]['vendor_id']);

        // Metrics attached
        self::assertSame(0.95, $body['data'][0]['metrics']['fulfillment_rate']['value']);

        // Meta block
        self::assertSame(2, $body['meta']['total']);
        self::assertSame(24, $body['meta']['limit']);
        self::assertSame(0, $body['meta']['offset']);
        self::assertSame(30, $body['meta']['window']['days']);

        // Calculator received the page's vendor ids
        self::assertSame([101, 202], $this->capturedListVendorIds);
    }

    #[Test]
    public function emptyVendorPageReturnsEmptyDataArray(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindDeps($admin, paginatedVendors: [], total: 0, allIds: []);

        $response = $this->makeGet($admin, '/v3/admin/vendor-metrics');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
    }

    #[Test]
    public function paginationParamsForwarded(): void
    {
        $admin = $this->makeAdminUser(99);
        $v1 = $this->makeVendor(101, 'a', 'A');
        $this->bindDeps($admin, paginatedVendors: [$v1], total: 47, allIds: [101]);

        $response = $this->makeGet($admin, '/v3/admin/vendor-metrics?limit=10&offset=20');

        $body = $this->jsonBody($response);
        self::assertSame(10, $body['meta']['limit']);
        self::assertSame(20, $body['meta']['offset']);
        self::assertSame(47, $body['meta']['total']);
    }

    #[Test]
    public function limitClampedToMax100(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindDeps($admin, paginatedVendors: [], total: 0, allIds: []);

        $response = $this->makeGet($admin, '/v3/admin/vendor-metrics?limit=9999');
        self::assertSame(100, $this->jsonBody($response)['meta']['limit']);
    }

    #[Test]
    public function negativeOffsetClampedToZero(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindDeps($admin, paginatedVendors: [], total: 0, allIds: []);

        $response = $this->makeGet($admin, '/v3/admin/vendor-metrics?offset=-5');
        self::assertSame(0, $this->jsonBody($response)['meta']['offset']);
    }

    #[Test]
    public function windowDaysForwarded(): void
    {
        $admin = $this->makeAdminUser(99);
        $v1 = $this->makeVendor(101, 'a', 'A');
        $this->bindDeps($admin, paginatedVendors: [$v1], total: 1, allIds: [101]);

        $this->makeGet($admin, '/v3/admin/vendor-metrics?days=90');
        self::assertSame(90, $this->capturedWindowDays);
    }

    // =================================================================
    // Metric-field sort path
    // =================================================================

    #[Test]
    public function metricSortComputesAllVendorsAndSortsInPhp(): void
    {
        $admin = $this->makeAdminUser(99);
        // 3 vendors: A=high fulfillment, B=low, C=null
        $vA = $this->makeVendor(101, 'a', 'A');
        $vB = $this->makeVendor(202, 'b', 'B');
        $vC = $this->makeVendor(303, 'c', 'C');
        $allMetrics = [
            101 => $this->cannedMetrics(30, fulfillment: 0.95),
            202 => $this->cannedMetrics(30, fulfillment: 0.50),
            303 => $this->cannedMetrics(30, fulfillment: null),
        ];

        $this->bindDeps(
            $admin,
            paginatedVendors: [],   // unused on metric-sort path
            total: 0,
            allIds: [101, 202, 303],
            vendorsById: [101 => $vA, 202 => $vB, 303 => $vC],
            allMetricsOverride: $allMetrics,
        );

        $response = $this->makeGet($admin, '/v3/admin/vendor-metrics?sort=fulfillment_rate_desc');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);

        // Order: 101 (0.95) > 202 (0.50) > 303 (null sorts LAST)
        self::assertCount(3, $body['data']);
        self::assertSame(101, $body['data'][0]['vendor_id']);
        self::assertSame(202, $body['data'][1]['vendor_id']);
        self::assertSame(303, $body['data'][2]['vendor_id']);

        // Calculator was called with ALL ids (not just the page)
        self::assertSame([101, 202, 303], $this->capturedListVendorIds);
        // Total reflects the full population
        self::assertSame(3, $body['meta']['total']);
    }

    #[Test]
    public function metricSortAscReversesOrder(): void
    {
        $admin = $this->makeAdminUser(99);
        $vA = $this->makeVendor(101, 'a', 'A');
        $vB = $this->makeVendor(202, 'b', 'B');
        $allMetrics = [
            101 => $this->cannedMetrics(30, fulfillment: 0.95),
            202 => $this->cannedMetrics(30, fulfillment: 0.50),
        ];

        $this->bindDeps(
            $admin,
            paginatedVendors: [],
            total: 0,
            allIds: [101, 202],
            vendorsById: [101 => $vA, 202 => $vB],
            allMetricsOverride: $allMetrics,
        );

        $response = $this->makeGet($admin, '/v3/admin/vendor-metrics?sort=fulfillment_rate_asc');
        $body = $this->jsonBody($response);

        // 202 (0.50) < 101 (0.95)
        self::assertSame(202, $body['data'][0]['vendor_id']);
        self::assertSame(101, $body['data'][1]['vendor_id']);
    }

    #[Test]
    public function nullMetricsAlwaysSortLastRegardlessOfDirection(): void
    {
        // Q-EmptyHandling = A: nulls go to bottom both asc + desc.
        $admin = $this->makeAdminUser(99);
        $vA = $this->makeVendor(101, 'a', 'A');
        $vNull = $this->makeVendor(202, 'b', 'B');
        $vC = $this->makeVendor(303, 'c', 'C');
        $allMetrics = [
            101 => $this->cannedMetrics(30, fulfillment: 0.10),
            202 => $this->cannedMetrics(30, fulfillment: null),
            303 => $this->cannedMetrics(30, fulfillment: 0.90),
        ];

        $this->bindDeps(
            $admin, paginatedVendors: [], total: 0,
            allIds: [101, 202, 303],
            vendorsById: [101 => $vA, 202 => $vNull, 303 => $vC],
            allMetricsOverride: $allMetrics,
        );

        // ASC: 0.10, 0.90, null
        $bodyAsc = $this->jsonBody($this->makeGet(
            $admin, '/v3/admin/vendor-metrics?sort=fulfillment_rate_asc'
        ));
        self::assertSame(101, $bodyAsc['data'][0]['vendor_id']);
        self::assertSame(303, $bodyAsc['data'][1]['vendor_id']);
        self::assertSame(202, $bodyAsc['data'][2]['vendor_id']);

        // DESC: 0.90, 0.10, null
        $bodyDesc = $this->jsonBody($this->makeGet(
            $admin, '/v3/admin/vendor-metrics?sort=fulfillment_rate_desc'
        ));
        self::assertSame(303, $bodyDesc['data'][0]['vendor_id']);
        self::assertSame(101, $bodyDesc['data'][1]['vendor_id']);
        self::assertSame(202, $bodyDesc['data'][2]['vendor_id']);
    }

    #[Test]
    public function metricSortAppliesPaginationAfterSort(): void
    {
        $admin = $this->makeAdminUser(99);
        $vA = $this->makeVendor(101, 'a', 'A');
        $vB = $this->makeVendor(202, 'b', 'B');
        $vC = $this->makeVendor(303, 'c', 'C');
        $allMetrics = [
            101 => $this->cannedMetrics(30, fulfillment: 0.95),
            202 => $this->cannedMetrics(30, fulfillment: 0.80),
            303 => $this->cannedMetrics(30, fulfillment: 0.50),
        ];

        $this->bindDeps(
            $admin, paginatedVendors: [], total: 0,
            allIds: [101, 202, 303],
            vendorsById: [101 => $vA, 202 => $vB, 303 => $vC],
            allMetricsOverride: $allMetrics,
        );

        // ?limit=1&offset=1, should get the SECOND vendor in sorted order (202)
        $body = $this->jsonBody($this->makeGet(
            $admin, '/v3/admin/vendor-metrics?sort=fulfillment_rate_desc&limit=1&offset=1'
        ));
        self::assertCount(1, $body['data']);
        self::assertSame(202, $body['data'][0]['vendor_id']);
        self::assertSame(3, $body['meta']['total']);  // total = full population
    }

    // =================================================================
    // Auth + audit
    // =================================================================

    #[Test]
    public function unauthenticatedReturns401(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindDeps($admin, paginatedVendors: [], total: 0, allIds: []);

        $response = $this->handle($this->jsonRequest('GET', '/v3/admin/vendor-metrics'));
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function nonAdminUserReturns403(): void
    {
        $user = $this->makeUser(id: 50);
        $this->bindDeps($user, paginatedVendors: [], total: 0, allIds: []);

        $response = $this->makeGet($user, '/v3/admin/vendor-metrics');
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function emitsActionViewedAuditWithFilterContext(): void
    {
        $admin = $this->makeAdminUser(99);
        $v1 = $this->makeVendor(101, 'a', 'A');
        $this->bindDeps($admin, paginatedVendors: [$v1], total: 1, allIds: [101]);

        $this->makeGet($admin, '/v3/admin/vendor-metrics?days=60&status=approved&limit=10');

        self::assertCount(1, $this->recordedAuditLogs);
        $audit = $this->recordedAuditLogs[0];
        self::assertSame('viewed', strtolower($audit->getAction()));

        $changes = $audit->getChanges();
        self::assertSame('admin_vendor_metrics_list', $changes['context']);
        self::assertSame(60, $changes['filters']['days']);
        self::assertSame('approved', $changes['filters']['status']);
        self::assertSame(10, $changes['filters']['limit']);
        self::assertSame(1, $changes['result_count']);
        self::assertSame(1, $changes['total']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    private function makeVendor(int $id, string $slug, string $name): Vendor
    {
        $vendor = new Vendor($slug, $name, "{$slug}@example.com");
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, $id);
        return $vendor;
    }

    /**
     * @param list<Vendor> $paginatedVendors Result for findPaginatedForAdmin
     * @param list<int> $allIds Result for findAllIdsForAdmin (metric-sort path)
     * @param array<int, Vendor> $vendorsById Result for findBy(['id' => $ids])
     * @param array<int, array<string, mixed>>|null $allMetricsOverride
     *        When set, used as the canned computeForVendorList return.
     *        When null, an empty result is returned.
     */
    private function bindDeps(
        User $user,
        array $paginatedVendors,
        int $total,
        array $allIds,
        array $vendorsById = [],
        ?array $allMetricsOverride = null,
    ): void {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findPaginatedForAdmin')->willReturn([$paginatedVendors, $total]);
        $vendorRepo->method('findAllIdsForAdmin')->willReturn($allIds);
        $vendorRepo->method('findBy')->willReturnCallback(
            function (array $criteria) use ($vendorsById, $paginatedVendors): array {
                if (!isset($criteria['id']) || !is_array($criteria['id'])) {
                    return [];
                }
                $out = [];
                foreach ($criteria['id'] as $id) {
                    if (isset($vendorsById[$id])) {
                        $out[] = $vendorsById[$id];
                    } else {
                        // Fall back to paginated set for the
                        // vendor-field sort path
                        foreach ($paginatedVendors as $v) {
                            if ($v->getId() === $id) {
                                $out[] = $v;
                                break;
                            }
                        }
                    }
                }
                return $out;
            },
        );

        // Capturing audit repo
        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void { $this->sink[] = $log; }
            public function getClassName(): string { return AuditLog::class; }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $emitter = new AuditEmitter($em, new NullLogger());
        $this->bind(AuditEmitter::class, $emitter);

        $calculator = $this->createMock(VendorMetricsCalculator::class);
        $calculator->method('computeForVendorList')->willReturnCallback(
            function (array $vendorIds, int $days) use ($paginatedVendors, $allMetricsOverride): array {
                $this->capturedListVendorIds = $vendorIds;
                $this->capturedWindowDays = $days;

                if ($allMetricsOverride !== null) {
                    // Filter the override to just the requested ids
                    $out = [];
                    foreach ($vendorIds as $vid) {
                        if (isset($allMetricsOverride[$vid])) {
                            $out[$vid] = $allMetricsOverride[$vid];
                        }
                    }
                    return $out;
                }

                // Default: canned metrics for every requested vendor
                $out = [];
                foreach ($vendorIds as $vid) {
                    $out[$vid] = $this->cannedMetrics($days);
                }
                return $out;
            },
        );
        $this->bind(VendorMetricsCalculator::class, $calculator);
    }

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    /**
     * @return array{window: array{days: int, since: string, until: string}, metrics: array<string, array<string, mixed>>}
     */
    private function cannedMetrics(int $windowDays, float|null $fulfillment = 0.95): array
    {
        $until = new \DateTimeImmutable();
        $since = $until->modify("-{$windowDays} days");
        return [
            'window' => [
                'days' => $windowDays,
                'since' => $since->format(\DateTimeInterface::ATOM),
                'until' => $until->format(\DateTimeInterface::ATOM),
            ],
            'metrics' => [
                'fulfillment_rate' => [
                    'value' => $fulfillment,
                    'fulfilled_items' => $fulfillment === null ? 0 : 95,
                    'total_items' => $fulfillment === null ? 0 : 100,
                ],
                'cancellation_rate' => ['value' => 0.03, 'rejected_items' => 3, 'total_items' => 100],
                'return_rate' => ['value' => 0.02, 'approved_returns' => 2, 'total_items' => 100],
                'dispute_rate' => ['value' => 0.0125, 'disputed_orders' => 1, 'total_orders' => 80],
            ],
        ];
    }
}
