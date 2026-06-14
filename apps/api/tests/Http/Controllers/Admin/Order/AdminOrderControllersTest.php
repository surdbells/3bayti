<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Order\GetAdminOrderController;
use Bayti\Api\Http\Controllers\Admin\Order\ListAdminOrdersController;
use Bayti\Api\Http\Controllers\Admin\Order\OverrideOrderItemStatusController;
use Bayti\Api\Http\Controllers\Admin\Order\OverrideOrderStatusController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

#[CoversClass(ListAdminOrdersController::class)]
#[CoversClass(GetAdminOrderController::class)]
#[CoversClass(OverrideOrderStatusController::class)]
#[CoversClass(OverrideOrderItemStatusController::class)]
final class AdminOrderControllersTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
    }

    #[Test]
    public function listForwardsFiltersAndEmitsAuditView(): void
    {
        $admin = $this->makeAdminUser(99);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->expects(self::once())
            ->method('paginatedForAdmin')
            ->with(10, 0, Order::STATUS_PAID, 42, 5, null, null)
            ->willReturn([[], 0]);

        $this->bindEm($admin, $orderRepo);
        $response = $this->makeGet($admin, '/v3/admin/orders?status=paid&user_id=42&vendor_id=5');

        self::assertSame(200, $response->getStatusCode());
        // ACTION_VIEWED audit emitted
        self::assertGreaterThan(0, count($this->recordedAuditLogs));
        $lastAudit = end($this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_VIEWED, $lastAudit->getAction());
        self::assertSame('User', $lastAudit->getSubjectType()); // list view uses actor as subject
    }

    #[Test]
    public function listForwardsDateRangeFilter(): void
    {
        $admin = $this->makeAdminUser(99);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->expects(self::once())
            ->method('paginatedForAdmin')
            ->with(
                self::anything(),
                self::anything(),
                null,
                null,
                null,
                self::isInstanceOf(\DateTimeImmutable::class),
                self::isInstanceOf(\DateTimeImmutable::class),
            )
            ->willReturn([[], 0]);

        $this->bindEm($admin, $orderRepo);
        $response = $this->makeGet($admin, '/v3/admin/orders?since=2026-01-01&until=2026-01-31');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function getReturnsFullOrderAndEmitsAuditView(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByIdForAdmin')->with(100)->willReturn($order);

        $this->bindEm($admin, $orderRepo);
        $response = $this->makeGet($admin, '/v3/admin/orders/100');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertSame(100, $body['order']['id']);
        // admin detail includes the customer (account holder) block
        self::assertSame(42, $body['order']['customer']['id']);
        self::assertArrayHasKey('email', $body['order']['customer']);

        // Audit row should be on the Order subject
        self::assertGreaterThan(0, count($this->recordedAuditLogs));
        $lastAudit = end($this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_VIEWED, $lastAudit->getAction());
        self::assertSame('Order', $lastAudit->getSubjectType());
        self::assertSame(100, $lastAudit->getSubjectId());
    }

    #[Test]
    public function getReturns404WhenOrderNotFound(): void
    {
        $admin = $this->makeAdminUser(99);
        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByIdForAdmin')->willReturn(null);

        $this->bindEm($admin, $orderRepo);
        $response = $this->makeGet($admin, '/v3/admin/orders/9999');
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function overrideStatusBypassesTransitionValidation(): void
    {
        // Admin forces an order from pending_payment directly to
        // refunded — a transition the normal state machine would reject.
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        // status defaults to pending_payment
        self::assertSame(Order::STATUS_PENDING_PAYMENT, $order->getStatus());

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByIdForAdmin')->willReturn($order);

        $em = $this->bindEm($admin, $orderRepo);
        $em->expects(self::once())->method('flush');

        $response = $this->makePatch(
            $admin,
            '/v3/admin/orders/100/status',
            ['status' => Order::STATUS_REFUNDED, 'reason' => 'manual override per ticket #123'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Order::STATUS_REFUNDED, $order->getStatus());

        // ACTION_OVERRIDDEN audit with before/after + reason
        $lastAudit = end($this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_OVERRIDDEN, $lastAudit->getAction());
        $changes = $lastAudit->getChanges();
        self::assertSame(Order::STATUS_PENDING_PAYMENT, $changes['before']['status']);
        self::assertSame(Order::STATUS_REFUNDED, $changes['after']['status']);
        self::assertSame('manual override per ticket #123', $changes['reason']);
    }

    #[Test]
    public function overrideStatusRequiresReason(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByIdForAdmin')->willReturn($order);
        $this->bindEm($admin, $orderRepo);

        // Empty reason → 422 from DTO validation
        $response = $this->makePatch(
            $admin,
            '/v3/admin/orders/100/status',
            ['status' => Order::STATUS_REFUNDED, 'reason' => ''],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(Order::STATUS_PENDING_PAYMENT, $order->getStatus());
    }

    #[Test]
    public function overrideItemStatusBypassesItemTransitionRules(): void
    {
        // Admin forces an item from pending directly to delivered —
        // would be illegal via the normal vendor endpoint.
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(id: 5);
        $product = $this->makeProduct(id: 200);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '99.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PAID);

        $item = $this->makeItem($vendor, $product, id: 501, status: OrderItem::ITEM_STATUS_PENDING);
        $order->addItem($item);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByIdForAdmin')->willReturn($order);

        $em = $this->bindEm($admin, $orderRepo);
        $em->expects(self::once())->method('flush');

        $response = $this->makePatch(
            $admin,
            '/v3/admin/orders/100/items/501/status',
            ['status' => OrderItem::ITEM_STATUS_DELIVERED, 'reason' => 'lost package, reimbursing'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(OrderItem::ITEM_STATUS_DELIVERED, $item->getItemStatus());

        // Order rollup: single item delivered → order delivered
        self::assertSame(Order::STATUS_DELIVERED, $order->getStatus());

        // Audit captured the override
        $lastAudit = end($this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_OVERRIDDEN, $lastAudit->getAction());
        self::assertSame('OrderItem', $lastAudit->getSubjectType());

        $changes = $lastAudit->getChanges();
        self::assertSame(OrderItem::ITEM_STATUS_PENDING, $changes['before']['item_status']);
        self::assertSame(OrderItem::ITEM_STATUS_DELIVERED, $changes['after']['item_status']);
        self::assertSame(Order::STATUS_PAID, $changes['before']['order_status']);
        self::assertSame(Order::STATUS_DELIVERED, $changes['after']['order_status']);
        self::assertSame('lost package, reimbursing', $changes['reason']);
    }

    #[Test]
    public function nonAdminGets403OnAdminEndpoints(): void
    {
        $user = $this->makeUser(id: 42);
        // user is plain customer

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle($this->jsonRequest('GET', '/v3/admin/orders', [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));

        self::assertSame(403, $response->getStatusCode());
    }

    // ===== Helpers =====

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    private function bindEm(User $user, OrderRepository $orderRepo): EntityManagerInterface
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        // Capturing audit repository — collects logs in $this->recordedAuditLogs
        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            public function __construct(private array &$sink) {}
            public function save(\Bayti\Api\Domain\Audit\AuditLog $log): void
            {
                $this->sink[] = $log;
            }
            public function getClassName(): string { return AuditLog::class; }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        // Bind a real AuditEmitter that uses the capturing repo
        $emitter = new AuditEmitter($em, new NullLogger());
        $this->bind(AuditEmitter::class, $emitter);

        return $em;
    }

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        return $this->sendRequest($user, 'GET', $uri, []);
    }

    private function makePatch(User $user, string $uri, array $body): ResponseInterface
    {
        return $this->sendRequest($user, 'PATCH', $uri, $body);
    }

    private function sendRequest(User $user, string $method, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest($method, $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function setEntityProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }

    private function makeOrder(User $user, int $id, string $reference, string $subtotal): Order
    {
        $order = new Order(user: $user, orderReference: $reference, subtotal: $subtotal);
        $this->setEntityId($order, $id);
        return $order;
    }

    private function makeProduct(int $id): Product
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($product, 'id', $id);
        $this->setEntityProp($product, 'name', 'Test Product');
        return $product;
    }

    private function makeVendor(int $id): Vendor
    {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($vendor, 'id', $id);
        return $vendor;
    }

    private function makeItem(Vendor $vendor, Product $product, int $id, string $status): OrderItem
    {
        $item = new OrderItem(
            product: $product, vendor: $vendor,
            quantity: 1, unitPrice: '99.00',
            productNameSnapshot: 'Test',
            productImageSnapshot: 'cdn/test.jpg',
        );
        $this->setEntityId($item, $id);
        $this->setEntityProp($item, 'itemStatus', $status);
        return $item;
    }
}
