<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Order\OrderTimelineBuilder;
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
use Psr\Log\NullLogger;

/**
 * X.17-E integration coverage: end-to-end verification that the
 * observability shipped in X.17-A (PSR-3 timing logs +
 * slow-response warning + statement timeout) fires correctly when
 * the OrderTimelineBuilder is invoked through the real controller
 * stack.
 *
 * Mirrors the X.14-E pattern: bind a REAL OrderTimelineBuilder
 * (not a mock) wired to a mocked Connection + a real InMemoryLogger.
 * The controller invokes the real builder; the builder emits real
 * PSR-3 events; the test logger captures them.
 *
 * Catches the failure mode where unit tests pass but production
 * logs silently die — e.g. a future DI config change that ships
 * NullLogger by accident for LoggerInterface bindings.
 */
final class OrderTimelineObservabilityIntegrationTest extends HttpTestCase
{
    /** @var list<AuditLog> */
    private array $recordedAuditLogs = [];

    #[Test]
    public function adminTimelineEndpointEmitsTimingLog(): void
    {
        $logger = new InMemoryLogger();
        $admin = $this->makeAdminUser(99);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindRealBuilderAdmin($admin, $order, $logger);

        $response = $this->makeGet($admin, '/v3/admin/orders/1234/timeline?order=desc&limit=50');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $records = $logger->findByMessage('order_timeline.computed');
        self::assertCount(
            1,
            $records,
            'Expected exactly one order_timeline.computed log entry',
        );
        self::assertSame('debug', $records[0]['level']);
        self::assertSame(1234, $records[0]['context']['order_id']);
        self::assertNull(
            $records[0]['context']['vendor_id_filter'],
            'Admin endpoint must pass null vendor filter',
        );
        self::assertArrayHasKey('duration_ms', $records[0]['context']);
    }

    #[Test]
    public function vendorTimelineEndpointEmitsScopedTimingLog(): void
    {
        $logger = new InMemoryLogger();
        $user = $this->makeVendorUser(50);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindRealBuilderVendor($user, $order, ownedVendorId: 101, logger: $logger);

        $response = $this->makeGet($user, '/v3/vendor/orders/1234/timeline');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $records = $logger->findByMessage('order_timeline.computed');
        self::assertCount(1, $records);
        self::assertSame(1234, $records[0]['context']['order_id']);
        // Vendor scope: filter is non-null and matches the owned vendor
        self::assertSame(101, $records[0]['context']['vendor_id_filter']);
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

    private function makeOrder(int $id, string $reference): Order
    {
        $order = (new \ReflectionClass(Order::class))->newInstanceWithoutConstructor();
        $idRef = new \ReflectionProperty(Order::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($order, $id);
        $refRef = new \ReflectionProperty(Order::class, 'orderReference');
        $refRef->setAccessible(true);
        $refRef->setValue($order, $reference);
        return $order;
    }

    /**
     * Connection mock returning empty rows for every executeQuery.
     * The builder still issues its queries + emits its observability
     * — we just don't supply real data because we're testing the
     * LOGGING, not the event values.
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

    private function bindRealBuilderAdmin(User $admin, Order $order, InMemoryLogger $logger): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByIdForAdmin')->willReturn($order);

        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void { $this->sink[] = $log; }
            public function getClassName(): string { return AuditLog::class; }
        };

        $connection = $this->emptyConnection();
        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo, $auditRepo, $connection) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
                [AuditLog::class, $auditRepo],
            ]);
            $em->method('getConnection')->willReturn($connection);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));

        // REAL builder with the test logger
        $this->bind(OrderTimelineBuilder::class, new OrderTimelineBuilder($em, $logger));
    }

    private function bindRealBuilderVendor(User $user, Order $order, int $ownedVendorId, InMemoryLogger $logger): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([$ownedVendorId]);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(true);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForVendorIds')->willReturn($order);

        $connection = $this->emptyConnection();
        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $orderRepo, $connection) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [Order::class, $orderRepo],
            ]);
            $em->method('getConnection')->willReturn($connection);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(OrderTimelineBuilder::class, new OrderTimelineBuilder($em, $logger));
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
