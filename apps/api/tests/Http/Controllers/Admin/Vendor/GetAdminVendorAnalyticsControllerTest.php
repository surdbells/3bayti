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
use Bayti\Api\Http\Controllers\Admin\Vendor\GetAdminVendorAnalyticsController;
use Bayti\Api\Http\Serializers\VendorAnalyticsSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * HTTP-level tests for GET /v3/admin/vendors/{id}/analytics
 * (M3.2.X.13-E).
 *
 * Uses the real auth + admin middleware stack. JWTs issued via
 * JwtService; user retrieved from mocked UserRepository during
 * AuthMiddleware → roles checked by AdminAuthMiddleware. Audit
 * trail verified via capturing audit_log repo.
 */
#[CoversClass(GetAdminVendorAnalyticsController::class)]
#[CoversClass(VendorAnalyticsSerializer::class)]
final class GetAdminVendorAnalyticsControllerTest extends HttpTestCase
{
    /** @var list<AuditLog> */
    private array $recordedAuditLogs = [];

    /** @var int Captured window_days argument forwarded to the calculator */
    private int $capturedWindowDays = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
        $this->capturedWindowDays = 0;
    }

    // =================================================================
    // Response shape
    // =================================================================

    #[Test]
    public function returnsFullEnvelopeWithAllSevenSections(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas-fashion', 'Almas Fashion');
        $this->bindDeps($admin, $vendor, $this->cannedAnalytics(30));

        $response = $this->makeGet($admin, '/v3/admin/vendors/101/analytics');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);

        // Identity block
        self::assertSame(101, $body['data']['vendor']['id']);
        self::assertSame('almas-fashion', $body['data']['vendor']['slug']);
        self::assertSame('Almas Fashion', $body['data']['vendor']['name']);

        // All 7 envelope sections present
        foreach ([
            'window',
            'totals',
            'revenue_series',
            'top_products_by_units',
            'top_products_by_revenue',
            'customer_mix',
            'status_mix',
        ] as $section) {
            self::assertArrayHasKey($section, $body['data'], "missing: {$section}");
        }

        // Window forwarded
        self::assertSame(30, $body['data']['window']['days']);

        // Meta block
        self::assertArrayHasKey('computed_at', $body['meta']);
        self::assertSame('miss', $body['meta']['cache']);
    }

    #[Test]
    public function totalsBlockShape(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->cannedAnalytics(30));

        $response = $this->makeGet($admin, '/v3/admin/vendors/101/analytics');
        $body = $this->jsonBody($response);

        $t = $body['data']['totals'];
        self::assertSame('12450.75', $t['revenue_aed']);
        self::assertSame(87, $t['orders']);
        self::assertSame(142, $t['items']);
        self::assertSame('143.11', $t['aov_aed']);
        self::assertSame(71, $t['unique_customers']);
    }

    #[Test]
    public function topProductsListsAreBothReturned(): void
    {
        // Q-TopN = C: two separate lists for units + revenue
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->cannedAnalytics(30));

        $response = $this->makeGet($admin, '/v3/admin/vendors/101/analytics');
        $body = $this->jsonBody($response);

        self::assertCount(2, $body['data']['top_products_by_units']);
        self::assertCount(2, $body['data']['top_products_by_revenue']);
        self::assertSame(100, $body['data']['top_products_by_units'][0]['product_id']);
        self::assertSame(200, $body['data']['top_products_by_revenue'][0]['product_id']);
    }

    // =================================================================
    // Window param handling
    // =================================================================

    #[Test]
    public function customDaysParamForwarded(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->cannedAnalytics(60));

        $response = $this->makeGet($admin, '/v3/admin/vendors/101/analytics?days=60');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(60, $this->capturedWindowDays);
    }

    #[Test]
    public function nonNumericDaysFallsBackToDefault(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->cannedAnalytics(30));

        $this->makeGet($admin, '/v3/admin/vendors/101/analytics?days=foo');
        self::assertSame(30, $this->capturedWindowDays);
    }

    #[Test]
    public function daysClampedToRange(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->cannedAnalytics(7));

        $this->makeGet($admin, '/v3/admin/vendors/101/analytics?days=0');
        self::assertSame(7, $this->capturedWindowDays);  // MIN

        $this->bindDeps($admin, $vendor, $this->cannedAnalytics(365));
        $this->makeGet($admin, '/v3/admin/vendors/101/analytics?days=9999');
        self::assertSame(365, $this->capturedWindowDays);  // MAX
    }

    // =================================================================
    // Auth + audit
    // =================================================================

    #[Test]
    public function requiresAdmin(): void
    {
        $regularUser = $this->makeUser(id: 200);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($regularUser, $vendor, $this->cannedAnalytics(30));

        $response = $this->makeGet($regularUser, '/v3/admin/vendors/101/analytics');
        self::assertSame(403, $response->getStatusCode());

        // No audit emitted on rejected calls
        self::assertCount(0, $this->recordedAuditLogs);
    }

    #[Test]
    public function recordsAuditViewOnSuccess(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->cannedAnalytics(45));

        $response = $this->makeGet($admin, '/v3/admin/vendors/101/analytics?days=45');
        self::assertSame(200, $response->getStatusCode());

        // Exactly one audit row
        self::assertCount(1, $this->recordedAuditLogs);
        $audit = $this->recordedAuditLogs[0];
        self::assertSame(AuditLog::ACTION_VIEWED, $audit->getAction());
        self::assertSame('Vendor', $audit->getSubjectType());
        self::assertSame(101, $audit->getSubjectId());

        $changes = $audit->getChanges();
        self::assertSame('admin_vendor_analytics', $changes['context']);
        self::assertSame(45, $changes['window_days']);
    }

    #[Test]
    public function nonExistentVendorReturns404(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindDeps($admin, vendor: null, analytics: $this->cannedAnalytics(30));

        $response = $this->makeGet($admin, '/v3/admin/vendors/999/analytics');
        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $this->recordedAuditLogs);
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
     * @param array<string, mixed> $analytics
     */
    private function bindDeps(User $user, ?Vendor $vendor, array $analytics): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('find')->willReturn($vendor);

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

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));

        $calculator = $this->createMock(VendorAnalyticsCalculator::class);
        $calculator->method('computeForVendor')->willReturnCallback(
            function (int $_vendorId, int $days) use ($analytics): array {
                $this->capturedWindowDays = $days;
                return $analytics;
            },
        );
        $this->bind(VendorAnalyticsCalculator::class, $calculator);
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
     * @return array<string, mixed>
     */
    private function cannedAnalytics(int $windowDays): array
    {
        $until = new \DateTimeImmutable();
        $since = $until->modify("-{$windowDays} days");
        return [
            'window' => [
                'days' => $windowDays,
                'since' => $since->format(\DateTimeInterface::ATOM),
                'until' => $until->format(\DateTimeInterface::ATOM),
            ],
            'totals' => [
                'revenue_aed' => '12450.75',
                'orders' => 87,
                'items' => 142,
                'aov_aed' => '143.11',
                'unique_customers' => 71,
            ],
            'revenue_series' => [
                ['date' => '2026-04-18', 'revenue_aed' => '320.50', 'orders' => 3],
                ['date' => '2026-04-19', 'revenue_aed' => '450.00', 'orders' => 4],
            ],
            'top_products_by_units' => [
                ['product_id' => 100, 'slug' => 'lamp', 'name' => 'Lamp', 'units' => 23, 'revenue_aed' => '3450.00'],
                ['product_id' => 200, 'slug' => 'chair', 'name' => 'Chair', 'units' => 18, 'revenue_aed' => '5400.00'],
            ],
            'top_products_by_revenue' => [
                ['product_id' => 200, 'slug' => 'chair', 'name' => 'Chair', 'units' => 18, 'revenue_aed' => '5400.00'],
                ['product_id' => 100, 'slug' => 'lamp', 'name' => 'Lamp', 'units' => 23, 'revenue_aed' => '3450.00'],
            ],
            'customer_mix' => ['new' => 22, 'returning' => 49, 'total' => 71],
            'status_mix' => ['delivered' => 78, 'cancelled' => 4, 'returned' => 5, 'total' => 87],
        ];
    }
}
