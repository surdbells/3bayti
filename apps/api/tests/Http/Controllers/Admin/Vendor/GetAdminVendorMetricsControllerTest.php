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
use Bayti\Api\Http\Controllers\Admin\Vendor\GetAdminVendorMetricsController;
use Bayti\Api\Http\Serializers\VendorMetricsSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * HTTP-level tests for GET /v3/admin/vendors/{id}/metrics (M3.2.X.14-B).
 *
 * Uses the real auth + admin middleware stack (no shortcuts). JWTs
 * issued via JwtService; user retrieved from mocked UserRepository
 * during AuthMiddleware → user roles checked by AdminAuthMiddleware.
 */
#[CoversClass(GetAdminVendorMetricsController::class)]
#[CoversClass(VendorMetricsSerializer::class)]
final class GetAdminVendorMetricsControllerTest extends HttpTestCase
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
    public function returnsSingleVendorEnvelopeWithAllFourMetrics(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas-fashion', 'Almas Fashion');
        $this->bindDeps($admin, $vendor, $this->cannedMetrics(30));

        $response = $this->makeGet($admin, '/v3/admin/vendors/101/metrics');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);

        // Identity block
        self::assertSame(101, $body['data']['vendor_id']);
        self::assertSame('almas-fashion', $body['data']['vendor_slug']);
        self::assertSame('Almas Fashion', $body['data']['vendor_name']);

        // Window block
        self::assertSame(30, $body['data']['window']['days']);
        self::assertArrayHasKey('since', $body['data']['window']);
        self::assertArrayHasKey('until', $body['data']['window']);

        // All 4 metrics keyed
        foreach (['fulfillment_rate', 'cancellation_rate', 'return_rate', 'dispute_rate'] as $m) {
            self::assertArrayHasKey($m, $body['data']['metrics'], "missing: {$m}");
            self::assertArrayHasKey('value', $body['data']['metrics'][$m]);
        }

        // Numerator/denominator preserved
        self::assertSame(95, $body['data']['metrics']['fulfillment_rate']['fulfilled_items']);
        self::assertSame(100, $body['data']['metrics']['fulfillment_rate']['total_items']);
    }

    #[Test]
    public function metricsForEmptyVendorReturnNullValues(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->emptyMetrics(30));

        $response = $this->makeGet($admin, '/v3/admin/vendors/101/metrics');

        $body = $this->jsonBody($response);
        self::assertNull($body['data']['metrics']['fulfillment_rate']['value']);
        self::assertSame(0, $body['data']['metrics']['fulfillment_rate']['total_items']);
    }

    // =================================================================
    // Window-day parsing
    // =================================================================

    #[Test]
    public function defaultsTo30DayWindowWhenDaysOmitted(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->cannedMetrics(30));

        $this->makeGet($admin, '/v3/admin/vendors/101/metrics');

        self::assertSame(30, $this->capturedWindowDays);
    }

    #[Test]
    public function customDaysParamForwardedToCalculator(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->cannedMetrics(90));

        $this->makeGet($admin, '/v3/admin/vendors/101/metrics?days=90');

        self::assertSame(90, $this->capturedWindowDays);
    }

    #[Test]
    public function daysBelowMinClampedTo7(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->cannedMetrics(7));

        $this->makeGet($admin, '/v3/admin/vendors/101/metrics?days=3');

        self::assertSame(7, $this->capturedWindowDays);
    }

    #[Test]
    public function daysAboveMaxClampedTo365(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->cannedMetrics(365));

        $this->makeGet($admin, '/v3/admin/vendors/101/metrics?days=9999');

        self::assertSame(365, $this->capturedWindowDays);
    }

    #[Test]
    public function nonNumericDaysFallsBackToDefault(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->cannedMetrics(30));

        $this->makeGet($admin, '/v3/admin/vendors/101/metrics?days=abc');

        self::assertSame(30, $this->capturedWindowDays);
    }

    // =================================================================
    // Error paths
    // =================================================================

    #[Test]
    public function missingVendorReturns404(): void
    {
        $admin = $this->makeAdminUser(99);
        // No vendor — repo returns null
        $this->bindDeps($admin, vendor: null, metrics: $this->emptyMetrics(30));

        $response = $this->makeGet($admin, '/v3/admin/vendors/999/metrics');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function unauthenticatedReturns401(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->cannedMetrics(30));

        // No Authorization header
        $response = $this->handle($this->jsonRequest('GET', '/v3/admin/vendors/101/metrics'));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function nonAdminUserReturns403(): void
    {
        $user = $this->makeUser(id: 50);  // not admin
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($user, $vendor, $this->cannedMetrics(30));

        $response = $this->makeGet($user, '/v3/admin/vendors/101/metrics');

        self::assertSame(403, $response->getStatusCode());
    }

    // =================================================================
    // Audit emission
    // =================================================================

    #[Test]
    public function emitsActionViewedAudit(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($admin, $vendor, $this->cannedMetrics(30));

        $this->makeGet($admin, '/v3/admin/vendors/101/metrics?days=60');

        self::assertCount(1, $this->recordedAuditLogs);
        $audit = $this->recordedAuditLogs[0];
        // ActionViewed sets action_type to 'VIEWED' (per AuditEmitter::recordView)
        self::assertSame('viewed', strtolower($audit->getAction()));

        // Context echoes the window
        $changes = $audit->getChanges();
        self::assertSame('admin_vendor_metrics', $changes['context']);
        self::assertSame(60, $changes['window_days']);
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
     * @param array{window: array<string, mixed>, metrics: array<string, array<string, mixed>>} $metrics
     */
    private function bindDeps(User $user, ?Vendor $vendor, array $metrics): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('find')->willReturn($vendor);

        // Capturing audit repo
        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
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

        $emitter = new AuditEmitter($em, new NullLogger());
        $this->bind(AuditEmitter::class, $emitter);

        // Calculator captures the window_days arg for assertion
        $calculator = $this->createMock(VendorMetricsCalculator::class);
        $calculator->method('computeForVendor')->willReturnCallback(
            function (int $_vendorId, int $days) use ($metrics): array {
                $this->capturedWindowDays = $days;
                return $metrics;
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
    private function cannedMetrics(int $windowDays): array
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
                'fulfillment_rate' => ['value' => 0.95, 'fulfilled_items' => 95, 'total_items' => 100],
                'cancellation_rate' => ['value' => 0.03, 'rejected_items' => 3, 'total_items' => 100],
                'return_rate' => ['value' => 0.02, 'approved_returns' => 2, 'total_items' => 100],
                'dispute_rate' => ['value' => 0.0125, 'disputed_orders' => 1, 'total_orders' => 80],
            ],
        ];
    }

    /**
     * @return array{window: array{days: int, since: string, until: string}, metrics: array<string, array<string, mixed>>}
     */
    private function emptyMetrics(int $windowDays): array
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
                'fulfillment_rate' => ['value' => null, 'fulfilled_items' => 0, 'total_items' => 0],
                'cancellation_rate' => ['value' => null, 'rejected_items' => 0, 'total_items' => 0],
                'return_rate' => ['value' => null, 'approved_returns' => 0, 'total_items' => 0],
                'dispute_rate' => ['value' => null, 'disputed_orders' => 0, 'total_orders' => 0],
            ],
        ];
    }
}
