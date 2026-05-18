<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Serializers;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Serializers\OrderSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the M3.2.X.18-H 'returns' embedding in
 * OrderSerializer::listShape and ::detailShape.
 *
 * Contract: when the caller passes a non-null $returns list, the
 * shape includes a 'returns' key with compact summaries. When null,
 * the key is absent (back-compat for existing list endpoints that
 * don't want N+1 queries).
 */
#[CoversClass(OrderSerializer::class)]
final class OrderSerializerReturnsEmbeddingTest extends TestCase
{
    private OrderSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new OrderSerializer();
    }

    #[Test]
    public function listShapeOmitsReturnsKeyByDefault(): void
    {
        // Back-compat: existing callers that don't pass $returns get
        // exactly the same shape they did before.
        $order = $this->makeOrder();
        $shape = $this->serializer->listShape($order);
        self::assertArrayNotHasKey('returns', $shape);
    }

    #[Test]
    public function detailShapeOmitsReturnsKeyByDefault(): void
    {
        $order = $this->makeOrder();
        $shape = $this->serializer->detailShape($order);
        self::assertArrayNotHasKey('returns', $shape);
    }

    #[Test]
    public function listShapeIncludesEmptyReturnsArrayWhenExplicitlyPassed(): void
    {
        // Passing [] (vs null) is the caller's signal that "I did
        // look this up and the answer is zero" — the UI should
        // show the section header (with "no returns") rather than
        // hiding it entirely.
        $order = $this->makeOrder();
        $shape = $this->serializer->listShape($order, []);
        self::assertArrayHasKey('returns', $shape);
        self::assertSame([], $shape['returns']);
    }

    #[Test]
    public function detailShapeIncludesSummaryForEachReturn(): void
    {
        $order = $this->makeOrder();
        $vendor = $this->makeVendor(id: 101);
        $item = $this->addItem($order, $vendor, 'Gadget');
        $user = $order->getUser();

        $rr = $this->makeReturn(
            order: $order,
            customer: $user,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            items: [$item],
            returnId: 42,
        );

        $shape = $this->serializer->detailShape($order, [$rr]);

        self::assertArrayHasKey('returns', $shape);
        self::assertCount(1, $shape['returns']);

        $summary = $shape['returns'][0];
        self::assertSame(42, $summary['id']);
        self::assertSame('RET-42', $summary['reference']);
        self::assertSame(OrderReturnRequest::STATUS_PENDING, $summary['status']);
        self::assertSame('defective', $summary['reason']);
        self::assertSame(1, $summary['item_count']);
        self::assertFalse($summary['is_terminal']);
        self::assertNotEmpty($summary['requested_at']);
    }

    #[Test]
    public function summariesUseOrderTaxonomyAndExposeTerminalFlag(): void
    {
        // A denied return should surface is_terminal=true so the UI
        // can dim its badge.
        $order = $this->makeOrder();
        $vendor = $this->makeVendor(id: 101);
        $item = $this->addItem($order, $vendor, 'Gadget');
        $user = $order->getUser();

        $rr = $this->makeReturn(
            order: $order,
            customer: $user,
            reason: OrderReturnRequest::REASON_OTHER,
            items: [$item],
            returnId: 7,
        );
        $admin = $this->makeUser('admin@3bayti.ae');
        $admin->setRoles(admin: true);
        $rr->deny($admin, 'Items unused per policy');

        $shape = $this->serializer->detailShape($order, [$rr]);

        self::assertSame(OrderReturnRequest::STATUS_DENIED, $shape['returns'][0]['status']);
        self::assertTrue($shape['returns'][0]['is_terminal']);
    }

    #[Test]
    public function summaryItemCountReflectsAllReturnItems(): void
    {
        $order = $this->makeOrder();
        $vendor = $this->makeVendor(id: 101);
        $itemA = $this->addItem($order, $vendor, 'A');
        $itemB = $this->addItem($order, $vendor, 'B');
        $user = $order->getUser();

        $rr = $this->makeReturn(
            order: $order,
            customer: $user,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            items: [$itemA, $itemB],
            returnId: 11,
        );

        $shape = $this->serializer->detailShape($order, [$rr]);
        self::assertSame(2, $shape['returns'][0]['item_count']);
    }

    #[Test]
    public function multipleReturnsAreAllIncluded(): void
    {
        $order = $this->makeOrder();
        $vendor = $this->makeVendor(id: 101);
        $itemA = $this->addItem($order, $vendor, 'A');
        $itemB = $this->addItem($order, $vendor, 'B');
        $user = $order->getUser();

        $rr1 = $this->makeReturn(
            order: $order, customer: $user,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            items: [$itemA], returnId: 1,
        );
        $rr2 = $this->makeReturn(
            order: $order, customer: $user,
            reason: OrderReturnRequest::REASON_SIZE_ISSUE,
            items: [$itemB], returnId: 2,
        );

        $shape = $this->serializer->detailShape($order, [$rr1, $rr2]);
        self::assertCount(2, $shape['returns']);
        self::assertSame(1, $shape['returns'][0]['id']);
        self::assertSame(2, $shape['returns'][1]['id']);
    }

    #[Test]
    public function detailShapeStillIncludesAddresses(): void
    {
        // Sanity: adding returns support didn't break the existing
        // detailShape contract (billing_address + shipping_address).
        $order = $this->makeOrder();
        $shape = $this->serializer->detailShape($order, []);
        self::assertArrayHasKey('billing_address', $shape);
        self::assertArrayHasKey('shipping_address', $shape);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeOrder(): Order
    {
        $user = $this->makeUser('customer@example.com');
        $order = new Order(
            user: $user,
            orderReference: 'V3-RET-EMBED-001',
            subtotal: '100.00',
        );
        $this->setEntityId($order, 100);
        $this->setProp($order, 'paidAt', new \DateTimeImmutable('-2 days'));
        $this->setProp($order, 'status', Order::STATUS_DELIVERED);
        return $order;
    }

    private function makeUser(string $email): User
    {
        $u = new User($email, '+971501234567', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $this->setEntityId($u, random_int(1, 1000));
        return $u;
    }

    private function makeVendor(int $id): Vendor
    {
        $v = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setProp($v, 'id', $id);
        $this->setProp($v, 'name', "Vendor {$id}");
        $this->setProp($v, 'contactEmail', "vendor{$id}@example.com");
        return $v;
    }

    private function addItem(Order $order, Vendor $vendor, string $name): OrderItem
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($product, 'id', random_int(200, 999));
        $this->setProp($product, 'name', $name);
        $this->setProp($product, 'vendor', $vendor);

        $item = new OrderItem(
            product: $product, vendor: $vendor,
            quantity: 1, unitPrice: '100.00',
            productNameSnapshot: $name, productImageSnapshot: 'x.jpg',
        );
        $this->setEntityId($item, random_int(500, 999));
        $this->setProp($item, 'itemStatus', OrderItem::ITEM_STATUS_DELIVERED);
        $order->addItem($item);
        return $item;
    }

    /**
     * @param list<OrderItem> $items
     */
    private function makeReturn(
        Order $order,
        User $customer,
        string $reason,
        array $items,
        int $returnId,
    ): OrderReturnRequest {
        $rr = new OrderReturnRequest(
            order: $order,
            customer: $customer,
            reason: $reason,
            customerNotes: 'note',
        );
        foreach ($items as $item) {
            $rr->addItem(new OrderReturnRequestItem($item, $item->getQuantity()));
        }
        $this->setEntityId($rr, $returnId);
        return $rr;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $this->setProp($entity, 'id', $id);
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
