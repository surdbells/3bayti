<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Order\OrderTimelineBuilder;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Order\GetAdminOrderTimelineController;
use Bayti\Api\Http\Serializers\OrderTimelineSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * HTTP-level tests for GET /v3/admin/orders/{id}/timeline (M3.2.X.17-C).
 *
 * Auth posture mirrors the other admin order controllers:
 * AdminAuthMiddleware → AuthMiddleware. Audit ACTION_VIEWED on every
 * successful call with the order as subject.
 *
 * The OrderTimelineBuilder is mocked; integration coverage is in the
 * builder's own unit test (X.17-A) and the X.17-E observability
 * integration test.
 */
#[CoversClass(GetAdminOrderTimelineController::class)]
#[CoversClass(OrderTimelineSerializer::class)]
final class GetAdminOrderTimelineControllerTest extends HttpTestCase
{
    /** @var list<AuditLog> */
    private array $recordedAuditLogs = [];

    /** @var array{order: string, limit: int, offset: int, vendorIdFilter: ?int} */
    private array $capturedArgs = [
        'order' => '',
        'limit' => 0,
        'offset' => 0,
        'vendorIdFilter' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
        $this->capturedArgs = ['order' => '', 'limit' => 0, 'offset' => 0, 'vendorIdFilter' => null];
    }

    // =================================================================
    // Response shape
    // =================================================================

    #[Test]
    public function returnsTimelineEnvelope(): void
    {
        $admin = $this->makeAdminUser(99);
        $order = $this->makeOrder(1234, 'V3-XYZ-001');
        $this->bindDeps($admin, $order, builderResult: [
            'events' => [
                ['id' => 'order:created', 'type' => 'order.created',
                 'occurred_at' => '2026-05-01T08:00:00+00:00',
                 'actor' => ['type' => 'system'], 'summary' => 'Order created',
                 'details' => []],
                ['id' => 'order:paid', 'type' => 'order.paid',
                 'occurred_at' => '2026-05-01T08:05:00+00:00',
                 'actor' => ['type' => 'system'], 'summary' => 'Payment confirmed',
                 'details' => []],
            ],
            'total' => 2,
        ]);

        $response = $this->makeGet($admin, '/v3/admin/orders/1234/timeline');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);

        // 2 events in data
        self::assertCount(2, $body['data']);
        self::assertSame('order.created', $body['data'][0]['type']);
        self::assertSame('order.paid', $body['data'][1]['type']);

        // Meta block carries order identity + pagination
        self::assertSame(2, $body['meta']['total']);
        self::assertSame(50, $body['meta']['limit']);   // DEFAULT_LIMIT
        self::assertSame(0, $body['meta']['offset']);
        self::assertSame(1234, $body['meta']['order_id']);
        self::assertSame('V3-XYZ-001', $body['meta']['order_reference']);
    }

    #[Test]
    public function emptyTimelineReturnsEmptyDataArray(): void
    {
        // Per Q-EmptyHandling = A: a found order with no events
        // returns 200 + empty data, NOT 404. (In practice the
        // builder always emits at least order.created for a real
        // order; this defensive test covers the case where the
        // builder returns zero events anyway.)
        $admin = $this->makeAdminUser(99);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($admin, $order, builderResult: ['events' => [], 'total' => 0]);

        $response = $this->makeGet($admin, '/v3/admin/orders/1234/timeline');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
    }

    // =================================================================
    // Query-param parsing
    // =================================================================

    #[Test]
    public function defaultsToDescOrderAndLimit50(): void
    {
        $admin = $this->makeAdminUser(99);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($admin, $order, builderResult: ['events' => [], 'total' => 0]);

        $this->makeGet($admin, '/v3/admin/orders/1234/timeline');

        self::assertSame('desc', $this->capturedArgs['order']);
        self::assertSame(50, $this->capturedArgs['limit']);
        self::assertSame(0, $this->capturedArgs['offset']);
        self::assertNull($this->capturedArgs['vendorIdFilter']);
    }

    #[Test]
    public function ascOrderForwarded(): void
    {
        $admin = $this->makeAdminUser(99);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($admin, $order, builderResult: ['events' => [], 'total' => 0]);

        $this->makeGet($admin, '/v3/admin/orders/1234/timeline?order=asc');

        self::assertSame('asc', $this->capturedArgs['order']);
    }

    #[Test]
    public function invalidOrderFallsBackToDesc(): void
    {
        $admin = $this->makeAdminUser(99);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($admin, $order, builderResult: ['events' => [], 'total' => 0]);

        $this->makeGet($admin, '/v3/admin/orders/1234/timeline?order=sideways');

        self::assertSame('desc', $this->capturedArgs['order']);
    }

    #[Test]
    public function paginationParamsForwarded(): void
    {
        $admin = $this->makeAdminUser(99);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($admin, $order, builderResult: ['events' => [], 'total' => 0]);

        $this->makeGet($admin, '/v3/admin/orders/1234/timeline?limit=25&offset=10');

        self::assertSame(25, $this->capturedArgs['limit']);
        self::assertSame(10, $this->capturedArgs['offset']);
    }

    #[Test]
    public function limitClampedToMax200(): void
    {
        $admin = $this->makeAdminUser(99);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($admin, $order, builderResult: ['events' => [], 'total' => 0]);

        $this->makeGet($admin, '/v3/admin/orders/1234/timeline?limit=9999');

        self::assertSame(200, $this->capturedArgs['limit']);  // MAX_LIMIT
    }

    #[Test]
    public function zeroOrNegativeLimitFallsBackToDefault(): void
    {
        $admin = $this->makeAdminUser(99);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($admin, $order, builderResult: ['events' => [], 'total' => 0]);

        $this->makeGet($admin, '/v3/admin/orders/1234/timeline?limit=0');

        self::assertSame(50, $this->capturedArgs['limit']);
    }

    #[Test]
    public function negativeOffsetClampedToZero(): void
    {
        $admin = $this->makeAdminUser(99);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($admin, $order, builderResult: ['events' => [], 'total' => 0]);

        $this->makeGet($admin, '/v3/admin/orders/1234/timeline?offset=-5');

        self::assertSame(0, $this->capturedArgs['offset']);
    }

    #[Test]
    public function adminCallPassesNullVendorFilter(): void
    {
        // Admin endpoint should NEVER pass a vendor filter, the
        // builder applies vendor scoping only when vendorIdFilter is
        // non-null. This test guards against a future regression
        // that might add accidental vendor filtering.
        $admin = $this->makeAdminUser(99);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($admin, $order, builderResult: ['events' => [], 'total' => 0]);

        $this->makeGet($admin, '/v3/admin/orders/1234/timeline');

        self::assertNull($this->capturedArgs['vendorIdFilter']);
    }

    // =================================================================
    // Error paths
    // =================================================================

    #[Test]
    public function missingOrderReturns404(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindDeps($admin, order: null, builderResult: ['events' => [], 'total' => 0]);

        $response = $this->makeGet($admin, '/v3/admin/orders/999999/timeline');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function unauthenticatedReturns401(): void
    {
        $admin = $this->makeAdminUser(99);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($admin, $order, builderResult: ['events' => [], 'total' => 0]);

        $response = $this->handle($this->jsonRequest('GET', '/v3/admin/orders/1234/timeline'));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function nonAdminUserReturns403(): void
    {
        $user = $this->makeUser(id: 50);  // not admin
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($user, $order, builderResult: ['events' => [], 'total' => 0]);

        $response = $this->makeGet($user, '/v3/admin/orders/1234/timeline');

        self::assertSame(403, $response->getStatusCode());
    }

    // =================================================================
    // Audit emission
    // =================================================================

    #[Test]
    public function emitsActionViewedAuditWithFilterContext(): void
    {
        $admin = $this->makeAdminUser(99);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($admin, $order, builderResult: [
            'events' => [
                ['id' => 'order:created', 'type' => 'order.created',
                 'occurred_at' => '2026-05-01T08:00:00+00:00',
                 'actor' => ['type' => 'system'], 'summary' => '', 'details' => []],
            ],
            'total' => 1,
        ]);

        $this->makeGet($admin, '/v3/admin/orders/1234/timeline?order=asc&limit=25');

        self::assertCount(1, $this->recordedAuditLogs);
        $audit = $this->recordedAuditLogs[0];
        self::assertSame('viewed', strtolower($audit->getAction()));
        self::assertSame('Order', $audit->getSubjectType());
        self::assertSame(1234, $audit->getSubjectId());

        $changes = $audit->getChanges();
        self::assertSame('admin_order_timeline', $changes['context']);
        self::assertSame('asc', $changes['filters']['order']);
        self::assertSame(25, $changes['filters']['limit']);
        self::assertSame(0, $changes['filters']['offset']);
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

    private function makeOrder(int $id, string $reference): Order
    {
        // Build a minimal Order via reflection, we don't need a full
        // entity graph here; the controller calls findByIdForAdmin
        // (mocked) and feeds the result straight to the serializer.
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
     * @param array{events: list<array<string, mixed>>, total: int} $builderResult
     */
    private function bindDeps(User $user, ?Order $order, array $builderResult): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByIdForAdmin')->willReturn($order);

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

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));

        $builder = $this->createMock(OrderTimelineBuilder::class);
        $builder->method('build')->willReturnCallback(
            function (int $_orderId, ?int $vendorIdFilter, string $order, int $limit, int $offset) use ($builderResult): array {
                $this->capturedArgs = [
                    'order' => $order,
                    'limit' => $limit,
                    'offset' => $offset,
                    'vendorIdFilter' => $vendorIdFilter,
                ];
                return $builderResult;
            },
        );
        $this->bind(OrderTimelineBuilder::class, $builder);
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
