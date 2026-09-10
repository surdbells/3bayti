<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Order;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\DeliveryReadinessCalculator;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeliveryReadinessCalculator::class)]
final class DeliveryReadinessCalculatorTest extends TestCase
{
    private DeliveryReadinessCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new DeliveryReadinessCalculator();
    }

    // ===== lead-time parsing =====

    /**
     * @param array<string, mixed>|null $info
     */
    #[Test]
    #[DataProvider('deliveryInfoCases')]
    public function parsesTheUpperBoundLeadDaysFromDeliveryInfo(?array $info, ?int $expected): void
    {
        self::assertSame($expected, DeliveryReadinessCalculator::leadDaysFromDeliveryInfo($info));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>|null, 1: int|null}>
     */
    public static function deliveryInfoCases(): iterable
    {
        yield 'null → null' => [null, null];
        yield 'range takes the upper bound' => [['time' => '4-7'], 7];
        yield 'single-day range' => [['time' => '1-3'], 3];
        yield 'bare number' => [['time' => '5'], 5];
        yield 'custom → largest number in free text' => [['time' => 'custom', 'custom_time' => '10-14 days'], 14];
        yield 'custom with one number' => [['time' => 'custom', 'custom_time' => 'about 20 days'], 20];
        yield 'custom with no number → null (falls back)' => [['time' => 'custom', 'custom_time' => 'same week'], null];
        yield 'empty time → null' => [['time' => '', 'note' => 'x'], null];
        yield 'unparseable time → null' => [['time' => 'soon'], null];
    }

    #[Test]
    public function fallsBackToVendorMaxDeliveryDaysWhenTheProductHasNoWindow(): void
    {
        $vendor = $this->makeVendor(9, maxDays: 12);
        $product = $this->makeProduct(1, deliveryInfo: null);

        $lead = DeliveryReadinessCalculator::leadDaysFor($product, $vendor);

        self::assertSame(12, $lead['days']);
        self::assertSame('vendor', $lead['source']);
    }

    #[Test]
    public function prefersTheProductWindowOverTheVendorFallback(): void
    {
        $vendor = $this->makeVendor(9, maxDays: 12);
        $product = $this->makeProduct(1, deliveryInfo: ['time' => '4-7']);

        $lead = DeliveryReadinessCalculator::leadDaysFor($product, $vendor);

        self::assertSame(7, $lead['days']);
        self::assertSame('product', $lead['source']);
    }

    // ===== order-level readiness =====

    #[Test]
    public function marksAnOrderReadyToSendAndOverdueWhenAllItemsArePastTheirReadyDate(): void
    {
        // Placed Jan 1, 7-day lead → ready Jan 8. Today Jan 10 → past due.
        $order = $this->makeOrder('2026-01-01');
        $this->addItem($order, 7, ['time' => '4-7'], OrderItem::ITEM_STATUS_PREPARING, 1);

        $r = $this->calc->forOrder($order, $this->today('2026-01-10'));

        self::assertSame('ready_to_send', $r['readiness']);
        self::assertSame('overdue', $r['bucket']);
        self::assertTrue($r['is_overdue']);
        self::assertSame('2026-01-08', $r['bottleneck_date']);
        self::assertSame(1, $r['ready_count']);
        self::assertSame(1, $r['unshipped_count']);
        self::assertTrue($r['items'][1]['is_late']);
        self::assertSame('ready', $r['items'][1]['state']);
    }

    #[Test]
    public function marksAnOrderReadyTodayWhenTheLastItemBecomesReadyOnTheDay(): void
    {
        $order = $this->makeOrder('2026-01-01');
        $this->addItem($order, 7, ['time' => '4-7'], OrderItem::ITEM_STATUS_ACCEPTED, 1);

        $r = $this->calc->forOrder($order, $this->today('2026-01-08'));

        self::assertSame('ready', $r['bucket']);
        self::assertFalse($r['is_overdue']);
        self::assertTrue($r['due_today']);
        self::assertFalse($r['items'][1]['is_late']);
    }

    #[Test]
    public function marksAnOrderWaitingWhenAnyItemIsStillUpcoming(): void
    {
        // Two stores: one ready (3-day lead → Jan 4), one slow (20 → Jan 21).
        $order = $this->makeOrder('2026-01-01');
        $this->addItem($order, 7, ['time' => '1-3'], OrderItem::ITEM_STATUS_PREPARING, 1);
        $this->addItem($order, 8, ['time' => 'custom', 'custom_time' => '20 days'], OrderItem::ITEM_STATUS_ACCEPTED, 2);

        $r = $this->calc->forOrder($order, $this->today('2026-01-05'));

        self::assertSame('waiting', $r['readiness']);
        self::assertSame('waiting', $r['bucket']);
        self::assertFalse($r['is_overdue']);
        self::assertSame('2026-01-21', $r['bottleneck_date']);
        self::assertSame(1, $r['ready_count']);
        self::assertSame(2, $r['unshipped_count']);
        self::assertSame('ready', $r['items'][1]['state']);
        self::assertSame('upcoming', $r['items'][2]['state']);
    }

    #[Test]
    public function reportsAllShippedWhenEveryActiveItemHasLeftTheVendor(): void
    {
        $order = $this->makeOrder('2026-01-01');
        $this->addItem($order, 7, ['time' => '4-7'], OrderItem::ITEM_STATUS_SHIPPED, 1);

        $r = $this->calc->forOrder($order, $this->today('2026-01-10'));

        self::assertSame('all_shipped', $r['readiness']);
        self::assertSame('shipped', $r['bucket']);
        self::assertSame(0, $r['unshipped_count']);
        self::assertSame(1, $r['active_count']);
        self::assertSame('shipped', $r['items'][1]['state']);
    }

    #[Test]
    public function flagsItemsStillAwaitingVendorAcceptance(): void
    {
        $order = $this->makeOrder('2026-01-01');
        $this->addItem($order, 7, ['time' => '4-7'], OrderItem::ITEM_STATUS_PENDING, 1);

        $r = $this->calc->forOrder($order, $this->today('2026-01-10'));

        self::assertTrue($r['awaiting_acceptance']);
        self::assertSame('pending', $r['items'][1]['state']);
        self::assertTrue($r['items'][1]['is_late']);
    }

    #[Test]
    public function ignoresTerminalItemsWhenRollingUpReadiness(): void
    {
        // A cancelled line must not keep an otherwise-ready order out of "ready".
        $order = $this->makeOrder('2026-01-01');
        $this->addItem($order, 7, ['time' => '4-7'], OrderItem::ITEM_STATUS_PREPARING, 1);
        $this->addItem($order, 7, ['time' => '4-7'], OrderItem::ITEM_STATUS_CANCELLED, 2);

        $r = $this->calc->forOrder($order, $this->today('2026-01-08'));

        self::assertSame('ready', $r['bucket']);
        self::assertSame(1, $r['unshipped_count']);
        self::assertSame('terminal', $r['items'][2]['state']);
    }

    // ===== fixtures =====

    private function today(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd . ' 09:00:00', new \DateTimeZone('Asia/Dubai'));
    }

    private function makeOrder(string $createdYmd): Order
    {
        $user = new User('c@example.test', '+971500000001', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $this->setProp($user, 'id', 1);
        $order = new Order(user: $user, orderReference: '3B-RDY', subtotal: '100.00');
        $this->setProp($order, 'id', 500);
        $this->setProp(
            $order,
            'createdAt',
            new \DateTimeImmutable($createdYmd . ' 00:00:00', new \DateTimeZone('UTC')),
        );
        return $order;
    }

    /**
     * @param array<string, mixed>|null $deliveryInfo
     */
    private function addItem(Order $order, int $vendorId, ?array $deliveryInfo, string $status, int $itemId): OrderItem
    {
        $vendor = $this->makeVendor($vendorId, maxDays: 14);
        $product = $this->makeProduct(200 + $itemId, $deliveryInfo, $vendor);

        $item = new OrderItem(
            product: $product,
            vendor: $vendor,
            quantity: 1,
            unitPrice: '100.00',
            productNameSnapshot: 'Item ' . $itemId,
            productImageSnapshot: 'cdn/' . $itemId . '.jpg',
        );
        $this->setProp($item, 'id', $itemId);
        $item->setItemStatus($status, true); // adminOverride: bypass transition rules for the fixture
        $order->addItem($item);
        return $item;
    }

    private function makeVendor(int $id, int $maxDays): Vendor
    {
        $vendor = new Vendor('store-' . $id, 'Store ' . $id, 'v' . $id . '@example.test');
        $this->setProp($vendor, 'id', $id);
        $this->setProp($vendor, 'maxDeliveryDays', $maxDays);
        return $vendor;
    }

    /**
     * @param array<string, mixed>|null $deliveryInfo
     */
    private function makeProduct(int $id, ?array $deliveryInfo, ?Vendor $vendor = null): Product
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($product, 'id', $id);
        $this->setProp($product, 'name', 'Item ' . $id);
        if ($vendor !== null) {
            $this->setProp($product, 'vendor', $vendor);
        }
        $product->setDeliveryInfo($deliveryInfo);
        return $product;
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
