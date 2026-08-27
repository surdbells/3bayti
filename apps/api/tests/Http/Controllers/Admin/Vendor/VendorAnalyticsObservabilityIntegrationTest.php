<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorAnalyticsCalculator;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Serializers\VendorAnalyticsSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * X.13-F integration coverage. End-to-end verification that the
 * full X.13 stack (admin controller → VendorAnalyticsCalculator
 * → 6 SQL queries → serializer → response) wires up correctly
 * with REAL service instances and that observability events
 * propagate end-to-end.
 *
 * Mirrors the X.14-E / X.17-E / X.11-H / X.15-G pattern (fifth
 * instance, pattern is firmly locked). Real
 * VendorAnalyticsCalculator + real VendorAnalyticsSerializer +
 * real AuditEmitter; mocked Connection (returns canned rows for
 * each of the 6 queries) + mocked VendorRepository/UserRepository.
 *
 * Catches the failure mode where:
 *   - Unit tests pass (each query shape is correct in isolation)
 *   - Wiring / DI changes silently break log propagation
 *   - The end-to-end happy path that ops cares about hasn't
 *     been exercised through real composition
 */
final class VendorAnalyticsObservabilityIntegrationTest extends HttpTestCase
{
    /** @var list<AuditLog> */
    private array $recordedAuditLogs = [];

    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
        $this->logger = new InMemoryLogger();
        $this->bind(LoggerInterface::class, $this->logger);
    }

    #[Test]
    public function fullStackComputesEnvelopeAndEmitsObservability(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');

        // Canned results for all 6 queries the calculator runs
        // in order: totals, series, top_units, top_revenue,
        // customer_mix, status_mix.
        $this->bindFullStack($admin, $vendor, queryResults: [
            // totals
            [['revenue' => '12450.75', 'orders' => 87, 'items' => 142, 'unique_customers' => 71]],
            // revenue_series
            [
                ['date' => '2026-04-18', 'revenue' => '320.50', 'orders' => 3],
                ['date' => '2026-04-19', 'revenue' => '450.00', 'orders' => 4],
            ],
            // top_units
            [
                ['product_id' => 100, 'slug' => 'lamp', 'name' => 'Lamp', 'units' => 23, 'revenue' => '3450.00'],
            ],
            // top_revenue
            [
                ['product_id' => 200, 'slug' => 'chair', 'name' => 'Chair', 'units' => 18, 'revenue' => '5400.00'],
            ],
            // customer_mix
            [['new_customers' => 22, 'returning_customers' => 49, 'total_customers' => 71]],
            // status_mix
            [['delivered' => 78, 'cancelled' => 4, 'returned' => 5, 'total' => 87]],
        ]);

        $response = $this->makeGet($admin, '/v3/admin/vendors/101/analytics?days=30');

        // Endpoint succeeded with the full envelope
        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        // Envelope assembled correctly from all 6 queries
        self::assertSame('12450.75', $body['data']['totals']['revenue_aed']);
        self::assertSame(87, $body['data']['totals']['orders']);
        self::assertSame(71, $body['data']['totals']['unique_customers']);
        // AOV = 12450.75 / 87 = 143.10... rounded HALF_UP → 143.11
        self::assertSame('143.11', $body['data']['totals']['aov_aed']);

        // Time-series passed through
        self::assertCount(2, $body['data']['revenue_series']);
        self::assertSame('320.50', $body['data']['revenue_series'][0]['revenue_aed']);

        // Top-N pair
        self::assertSame(100, $body['data']['top_products_by_units'][0]['product_id']);
        self::assertSame(200, $body['data']['top_products_by_revenue'][0]['product_id']);

        // Mixes
        self::assertSame(22, $body['data']['customer_mix']['new']);
        self::assertSame(49, $body['data']['customer_mix']['returning']);
        self::assertSame(78, $body['data']['status_mix']['delivered']);

        // Vendor identity + meta block
        self::assertSame(101, $body['data']['vendor']['id']);
        self::assertSame('almas', $body['data']['vendor']['slug']);
        self::assertSame('miss', $body['meta']['cache']);

        // Observability fired through the full stack
        $computed = $this->logger->findByMessage('vendor_analytics.computed');
        self::assertCount(1, $computed);
        self::assertSame(101, $computed[0]['context']['vendor_id']);
        self::assertSame(30, $computed[0]['context']['window_days']);
        self::assertArrayHasKey('duration_ms', $computed[0]['context']);

        // Audit recorded
        self::assertCount(1, $this->recordedAuditLogs);
        $audit = $this->recordedAuditLogs[0];
        self::assertSame(AuditLog::ACTION_VIEWED, $audit->getAction());
        self::assertSame('Vendor', $audit->getSubjectType());
        self::assertSame(101, $audit->getSubjectId());
        self::assertSame('admin_vendor_analytics', $audit->getChanges()['context']);
        self::assertSame(30, $audit->getChanges()['window_days']);
    }

    #[Test]
    public function emptyVendorReturnsZerosWithoutErrors(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');

        // All 6 queries return empty results, vendor with no orders
        $this->bindFullStack($admin, $vendor, queryResults: [
            // totals
            [['revenue' => '0', 'orders' => 0, 'items' => 0, 'unique_customers' => 0]],
            // series
            [],
            // top_units, top_revenue
            [], [],
            // mixes
            [['new_customers' => 0, 'returning_customers' => 0, 'total_customers' => 0]],
            [['delivered' => 0, 'cancelled' => 0, 'returned' => 0, 'total' => 0]],
        ]);

        $response = $this->makeGet($admin, '/v3/admin/vendors/101/analytics');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        // Q-EmptyHandling = C: totals=0 + empty arrays (friendlier
        // dashboard UX than X.14's null pattern)
        self::assertSame('0.00', $body['data']['totals']['revenue_aed']);
        self::assertSame(0, $body['data']['totals']['orders']);
        self::assertSame('0.00', $body['data']['totals']['aov_aed']);
        self::assertSame([], $body['data']['revenue_series']);
        self::assertSame([], $body['data']['top_products_by_units']);
        self::assertSame([], $body['data']['top_products_by_revenue']);
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
     * Bind a full stack: real calculator + real serializer + real
     * audit emitter; mocked Connection (canned per-query results)
     * + mocked repositories.
     *
     * @param list<list<array<string, mixed>>> $queryResults
     */
    private function bindFullStack(User $user, Vendor $vendor, array $queryResults): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('find')->willReturn($vendor);

        // Capturing audit_log repo
        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            /** @param list<AuditLog> $sink */
            public function __construct(private array &$sink)
            {
            }
            public function save(AuditLog $log): void
            {
                $this->sink[] = $log;
            }
            public function getClassName(): string
            {
                return AuditLog::class;
            }
        };

        // Canned Connection that returns the next preset row set
        // for each executeQuery call.
        $callIdx = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);
        $connection->method('executeQuery')->willReturnCallback(
            function () use (&$callIdx, $queryResults): Result {
                $rows = $queryResults[$callIdx] ?? [];
                $callIdx++;
                $r = $this->createMock(Result::class);
                $r->method('fetchAssociative')->willReturn($rows[0] ?? false);
                $r->method('fetchAllAssociative')->willReturn($rows);
                return $r;
            },
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $auditRepo, $connection) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [AuditLog::class, $auditRepo],
            ]);
            $em->method('getConnection')->willReturn($connection);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));

        // REAL calculator + REAL serializer
        $this->bind(
            VendorAnalyticsCalculator::class,
            new VendorAnalyticsCalculator($em, $this->logger),
        );
        $this->bind(
            VendorAnalyticsSerializer::class,
            new VendorAnalyticsSerializer(),
        );
    }

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
