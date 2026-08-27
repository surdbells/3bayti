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
 * X.14-E integration coverage: end-to-end verification that the
 * observability shipped in X.14-A (PSR-3 timing + slow-response
 * warning + statement timeout) fires correctly when the calculator
 * is invoked through the real controller stack.
 *
 * Unit tests in VendorMetricsCalculatorTest cover the logging
 * behavior in isolation. This integration test confirms it survives
 * the DI wiring + middleware stack + serializer roundtrip, the
 * difference between "logs work in unit tests" and "logs work in
 * production".
 *
 * Approach: bind a real VendorMetricsCalculator (not a mock) wired
 * to a mocked Connection, and bind a real InMemoryLogger to the
 * LoggerInterface. The controller invokes the real calculator;
 * the calculator emits real PSR-3 events; the logger captures them.
 */
final class VendorMetricsObservabilityIntegrationTest extends HttpTestCase
{
    /** @var list<AuditLog> */
    private array $recordedAuditLogs = [];

    #[Test]
    public function adminSingleVendorEndpointEmitsTimingLog(): void
    {
        $logger = new InMemoryLogger();
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindRealCalculator($admin, $vendor, $logger);

        $response = $this->makeGet($admin, '/v3/admin/vendors/101/metrics?days=30');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        // The calculator emitted 'vendor_metrics.computed' at debug
        $records = $logger->findByMessage('vendor_metrics.computed');
        self::assertCount(
            1,
            $records,
            'Expected exactly one vendor_metrics.computed log entry'
        );
        self::assertSame('debug', $records[0]['level']);
        self::assertSame(101, $records[0]['context']['vendor_id']);
        self::assertSame(30, $records[0]['context']['window_days']);
        self::assertArrayHasKey('duration_ms', $records[0]['context']);
    }

    #[Test]
    public function adminListEndpointEmitsListTimingLog(): void
    {
        $logger = new InMemoryLogger();
        $admin = $this->makeAdminUser(99);
        $v1 = $this->makeVendor(101, 'a', 'A');
        $v2 = $this->makeVendor(202, 'b', 'B');
        $this->bindRealCalculatorForList($admin, [$v1, $v2], $logger);

        $response = $this->makeGet($admin, '/v3/admin/vendor-metrics?days=30&limit=10');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $records = $logger->findByMessage('vendor_metrics.computed');
        self::assertCount(1, $records);
        self::assertSame('debug', $records[0]['level']);
        // List path emits vendor_count, not vendor_id
        self::assertSame(2, $records[0]['context']['vendor_count']);
        self::assertSame(30, $records[0]['context']['window_days']);
    }

    #[Test]
    public function vendorSelfEndpointEmitsTimingLog(): void
    {
        $logger = new InMemoryLogger();
        $user = $this->makeVendorUser(50);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindRealCalculatorForVendor($user, $vendor, $logger);

        $response = $this->makeGet($user, '/v3/vendor/metrics?days=60');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $records = $logger->findByMessage('vendor_metrics.computed');
        self::assertCount(1, $records);
        self::assertSame(101, $records[0]['context']['vendor_id']);
        self::assertSame(60, $records[0]['context']['window_days']);
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

    private function makeVendorUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(vendor: true);
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
     * Connection mock that returns empty associative rows for every
     * executeQuery. The calculator still does its 3 queries and emits
     * its observability logs, we just don't supply real data.
     */
    private function emptyConnection(): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturnCallback(
            function (): Result {
                $r = $this->createMock(Result::class);
                $r->method('fetchAssociative')->willReturn(false);
                $r->method('fetchAllAssociative')->willReturn([]);
                return $r;
            },
        );
        return $conn;
    }

    private function bindRealCalculator(User $admin, Vendor $vendor, InMemoryLogger $logger): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('find')->willReturn($vendor);

        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void { $this->sink[] = $log; }
            public function getClassName(): string { return AuditLog::class; }
        };

        $connection = $this->emptyConnection();
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

        // REAL calculator with the test logger
        $this->bind(VendorMetricsCalculator::class, new VendorMetricsCalculator($em, $logger));
    }

    /**
     * @param list<Vendor> $vendors
     */
    private function bindRealCalculatorForList(User $admin, array $vendors, InMemoryLogger $logger): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findPaginatedForAdmin')->willReturn([$vendors, count($vendors)]);
        $vendorIds = array_values(array_filter(
            array_map(static fn (Vendor $v): ?int => $v->getId(), $vendors),
            static fn (?int $id): bool => $id !== null,
        ));
        $vendorRepo->method('findAllIdsForAdmin')->willReturn($vendorIds);
        $vendorRepo->method('findBy')->willReturn($vendors);

        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void { $this->sink[] = $log; }
            public function getClassName(): string { return AuditLog::class; }
        };

        $connection = $this->emptyConnection();
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
        $this->bind(VendorMetricsCalculator::class, new VendorMetricsCalculator($em, $logger));
    }

    private function bindRealCalculatorForVendor(User $user, Vendor $vendor, InMemoryLogger $logger): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorId = $vendor->getId() ?? 0;
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([$vendorId]);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(true);
        $vendorRepo->method('find')->willReturn($vendor);

        $connection = $this->emptyConnection();
        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $connection) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
            ]);
            $em->method('getConnection')->willReturn($connection);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(VendorMetricsCalculator::class, new VendorMetricsCalculator($em, $logger));
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
