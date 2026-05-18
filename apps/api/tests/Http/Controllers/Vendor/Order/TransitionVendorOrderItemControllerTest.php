<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\Order\TransitionVendorOrderItemController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

#[CoversClass(TransitionVendorOrderItemController::class)]
final class TransitionVendorOrderItemControllerTest extends HttpTestCase
{
    /** @var list<AuditLog> */
    private array $recordedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
    }

    #[Test]
    public function legalTransitionAdvancesItemAndRollupOrder(): void
    {
        $user = $this->makeVendorUser(7);
        $myVendor = $this->makeVendor(id: 5);
        $product = $this->makeProduct(id: 200);

        // Order in 'paid' state with a single 'pending' item.
        $order = $this->makeOrder($user, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PAID);

        $item = $this->makeItem($myVendor, $product, id: 501, status: OrderItem::ITEM_STATUS_PENDING);
        $order->addItem($item);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForVendorIds')->willReturn($order);

        $em = $this->bindEm($user, $orderRepo, $vendorRepo);
        // Expect a flush
        $em->expects(self::once())->method('flush');

        $response = $this->makePatch(
            $user,
            '/v3/vendor/orders/100/items/501/status',
            ['status' => OrderItem::ITEM_STATUS_ACCEPTED, 'note' => 'will ship Fri'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(OrderItem::ITEM_STATUS_ACCEPTED, $item->getItemStatus());

        // Order rollup: one active (accepted) item → order goes 'fulfilling'
        self::assertSame(Order::STATUS_FULFILLING, $order->getStatus());

        $body = $this->jsonBody($response);
        self::assertSame(OrderItem::ITEM_STATUS_ACCEPTED, $body['order']['items'][0]['item_status']);
    }

    #[Test]
    public function illegalTransitionReturns422WithAllowedList(): void
    {
        $user = $this->makeVendorUser(7);
        $myVendor = $this->makeVendor(id: 5);
        $product = $this->makeProduct(id: 200);
        $order = $this->makeOrder($user, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PAID);

        // Item is 'pending' — can't jump straight to 'delivered'
        $item = $this->makeItem($myVendor, $product, id: 501, status: OrderItem::ITEM_STATUS_PENDING);
        $order->addItem($item);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);
        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForVendorIds')->willReturn($order);

        $em = $this->bindEm($user, $orderRepo, $vendorRepo);
        // No flush — transaction rejected
        $em->expects(self::never())->method('flush');

        $response = $this->makePatch(
            $user,
            '/v3/vendor/orders/100/items/501/status',
            ['status' => OrderItem::ITEM_STATUS_DELIVERED],
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('illegal_status_transition', $body['error']['code']);
        self::assertContains(
            OrderItem::ITEM_STATUS_ACCEPTED,
            $body['error']['details']['allowed_transitions'],
            'allowed_transitions should include legal next steps from pending',
        );

        // Item status unchanged
        self::assertSame(OrderItem::ITEM_STATUS_PENDING, $item->getItemStatus());
    }

    #[Test]
    public function crossVendorItemReturns404(): void
    {
        // Vendor A authenticates, requests transition on an item owned
        // by Vendor B that happens to share an order with one of A's
        // items. Must return 404 (not 403) to avoid leaking item
        // existence to cross-vendor probes.
        $user = $this->makeVendorUser(7);
        $myVendor = $this->makeVendor(id: 5);
        $otherVendor = $this->makeVendor(id: 6);
        $product = $this->makeProduct(id: 200);

        $order = $this->makeOrder($user, id: 100, reference: 'V3-001', subtotal: '99.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PAID);

        // My item — gives me access to the order
        $myItem = $this->makeItem($myVendor, $product, id: 501, status: OrderItem::ITEM_STATUS_PENDING);
        $order->addItem($myItem);

        // Other vendor's item in the same order
        $otherItem = $this->makeItem($otherVendor, $product, id: 502, status: OrderItem::ITEM_STATUS_PENDING);
        $order->addItem($otherItem);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);
        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForVendorIds')->willReturn($order);

        $em = $this->bindEm($user, $orderRepo, $vendorRepo);
        $em->expects(self::never())->method('flush');

        // Try to transition Vendor B's item id 502
        $response = $this->makePatch(
            $user,
            '/v3/vendor/orders/100/items/502/status',
            ['status' => OrderItem::ITEM_STATUS_ACCEPTED],
        );

        self::assertSame(404, $response->getStatusCode(), 'Must be 404 not 403');
        // Other vendor's item still unchanged
        self::assertSame(OrderItem::ITEM_STATUS_PENDING, $otherItem->getItemStatus());
    }

    #[Test]
    public function nonExistentItemReturns404(): void
    {
        $user = $this->makeVendorUser(7);
        $myVendor = $this->makeVendor(id: 5);
        $product = $this->makeProduct(id: 200);
        $order = $this->makeOrder($user, id: 100, reference: 'V3-001', subtotal: '99.00');
        $item = $this->makeItem($myVendor, $product, id: 501, status: OrderItem::ITEM_STATUS_PENDING);
        $order->addItem($item);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);
        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForVendorIds')->willReturn($order);

        $em = $this->bindEm($user, $orderRepo, $vendorRepo);

        $response = $this->makePatch(
            $user,
            '/v3/vendor/orders/100/items/9999/status',
            ['status' => OrderItem::ITEM_STATUS_ACCEPTED],
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function invalidBodyStatusReturns422(): void
    {
        $user = $this->makeVendorUser(7);
        $myVendor = $this->makeVendor(id: 5);
        $product = $this->makeProduct(id: 200);
        $order = $this->makeOrder($user, id: 100, reference: 'V3-001', subtotal: '99.00');
        $item = $this->makeItem($myVendor, $product, id: 501, status: OrderItem::ITEM_STATUS_PENDING);
        $order->addItem($item);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);
        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForVendorIds')->willReturn($order);
        $this->bindEm($user, $orderRepo, $vendorRepo);

        $response = $this->makePatch(
            $user,
            '/v3/vendor/orders/100/items/501/status',
            ['status' => 'nonsense_status'],
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function allItemsDeliveredRollsOrderToDelivered(): void
    {
        $user = $this->makeVendorUser(7);
        $myVendor = $this->makeVendor(id: 5);
        $product = $this->makeProduct(id: 200);
        $order = $this->makeOrder($user, id: 100, reference: 'V3-001', subtotal: '299.00');
        // Order is in shipped state (one item already shipped)
        $this->setEntityProp($order, 'status', Order::STATUS_SHIPPED);

        $item = $this->makeItem($myVendor, $product, id: 501, status: OrderItem::ITEM_STATUS_SHIPPED);
        $order->addItem($item);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);
        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForVendorIds')->willReturn($order);
        $this->bindEm($user, $orderRepo, $vendorRepo);

        $response = $this->makePatch(
            $user,
            '/v3/vendor/orders/100/items/501/status',
            ['status' => OrderItem::ITEM_STATUS_DELIVERED],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(OrderItem::ITEM_STATUS_DELIVERED, $item->getItemStatus());
        // Single item, all delivered → order delivered
        self::assertSame(Order::STATUS_DELIVERED, $order->getStatus());
    }

    // =================================================================
    // M3.2.X.17-B — Audit emission on item transitions
    // =================================================================

    #[Test]
    public function legalTransitionEmitsItemLevelAudit(): void
    {
        $user = $this->makeVendorUser(7);
        $myVendor = $this->makeVendor(id: 5);
        $product = $this->makeProduct(id: 200);
        $order = $this->makeOrder($user, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PAID);

        $item = $this->makeItem($myVendor, $product, id: 501, status: OrderItem::ITEM_STATUS_PENDING);
        $order->addItem($item);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);
        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForVendorIds')->willReturn($order);
        $this->bindEmWithAudit($user, $orderRepo, $vendorRepo);

        $response = $this->makePatch(
            $user,
            '/v3/vendor/orders/100/items/501/status',
            ['status' => OrderItem::ITEM_STATUS_ACCEPTED, 'note' => 'will ship Fri'],
        );

        self::assertSame(200, $response->getStatusCode());
        // 2 audit rows: item-level (pending → accepted) AND order-level
        // (paid → fulfilling, since accepting an item rolls the order
        // from paid to fulfilling)
        self::assertCount(2, $this->recordedAuditLogs);

        // Find the item audit
        $itemAudit = null;
        $orderAudit = null;
        foreach ($this->recordedAuditLogs as $log) {
            if ($log->getSubjectType() === 'OrderItem') {
                $itemAudit = $log;
            } elseif ($log->getSubjectType() === 'Order') {
                $orderAudit = $log;
            }
        }
        self::assertNotNull($itemAudit, 'expected an OrderItem audit row');
        self::assertNotNull($orderAudit, 'expected an Order audit row');

        // Item audit shape: action=updated, subject_id=501,
        // changes carries before/after item_status
        self::assertSame('updated', strtolower($itemAudit->getAction()));
        self::assertSame(501, $itemAudit->getSubjectId());
        $itemChanges = $itemAudit->getChanges();
        self::assertSame('pending', $itemChanges['before']['item_status']);
        self::assertSame('accepted', $itemChanges['after']['item_status']);
        self::assertSame('will ship Fri', $itemChanges['after']['note']);

        // Order audit shape: action=updated, subject_id=100,
        // before/after status reflects the rollup
        self::assertSame('updated', strtolower($orderAudit->getAction()));
        self::assertSame(100, $orderAudit->getSubjectId());
        self::assertSame('paid', $orderAudit->getChanges()['before']['status']);
        self::assertSame('fulfilling', $orderAudit->getChanges()['after']['status']);
    }

    #[Test]
    public function transitionWithoutOrderStatusChangeEmitsOnlyItemAudit(): void
    {
        // Transition from 'accepted' to 'preparing' on a single-item
        // order that's already in 'fulfilling' — the order rollup
        // doesn't move (still fulfilling). So we should see ONE
        // audit row (item-level), not two.
        $user = $this->makeVendorUser(7);
        $myVendor = $this->makeVendor(id: 5);
        $product = $this->makeProduct(id: 200);
        $order = $this->makeOrder($user, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_FULFILLING);

        $item = $this->makeItem($myVendor, $product, id: 501, status: OrderItem::ITEM_STATUS_ACCEPTED);
        $order->addItem($item);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);
        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForVendorIds')->willReturn($order);
        $this->bindEmWithAudit($user, $orderRepo, $vendorRepo);

        $response = $this->makePatch(
            $user,
            '/v3/vendor/orders/100/items/501/status',
            ['status' => OrderItem::ITEM_STATUS_PREPARING],
        );

        self::assertSame(200, $response->getStatusCode());
        // accepted → preparing keeps order at 'fulfilling' (no rollup)
        self::assertSame(Order::STATUS_FULFILLING, $order->getStatus());
        // Only the item audit fires
        self::assertCount(1, $this->recordedAuditLogs);
        self::assertSame('OrderItem', $this->recordedAuditLogs[0]->getSubjectType());
    }

    #[Test]
    public function illegalTransitionEmitsNoAudit(): void
    {
        // setItemStatus throws BEFORE flush, so we never reach the
        // audit emission. No audit rows should be written.
        $user = $this->makeVendorUser(7);
        $myVendor = $this->makeVendor(id: 5);
        $product = $this->makeProduct(id: 200);
        $order = $this->makeOrder($user, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PAID);

        // Item is pending — can't jump straight to delivered
        $item = $this->makeItem($myVendor, $product, id: 501, status: OrderItem::ITEM_STATUS_PENDING);
        $order->addItem($item);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);
        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForVendorIds')->willReturn($order);
        $this->bindEmWithAudit($user, $orderRepo, $vendorRepo);

        $response = $this->makePatch(
            $user,
            '/v3/vendor/orders/100/items/501/status',
            ['status' => OrderItem::ITEM_STATUS_DELIVERED],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $this->recordedAuditLogs);
    }

    // ===== Helpers =====

    private function makeVendorUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(vendor: true);
        return $user;
    }

    private function bindEm(User $user, OrderRepository $orderRepo, VendorRepository $vendorRepo): EntityManagerInterface
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo, $vendorRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
                [Vendor::class, $vendorRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        return $em;
    }

    /**
     * Like bindEm but also wires a capturing AuditLog repo + a real
     * AuditEmitter, so X.17-B audit-emission can be observed.
     */
    private function bindEmWithAudit(
        User $user,
        OrderRepository $orderRepo,
        VendorRepository $vendorRepo,
    ): EntityManagerInterface {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

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

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo, $vendorRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
                [Vendor::class, $vendorRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));
        return $em;
    }

    private function makePatch(User $user, string $uri, array $body): \Psr\Http\Message\ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('PATCH', $uri, $body, [
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
        // Set status via reflection so we can start in any state (the
        // setter has transition validation; we want to bypass for setup).
        $this->setEntityProp($item, 'itemStatus', $status);
        return $item;
    }
}
