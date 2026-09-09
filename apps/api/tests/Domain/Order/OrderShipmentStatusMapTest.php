<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Order;

use Bayti\Api\Domain\Order\OrderShipment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderShipment::class)]
final class OrderShipmentStatusMapTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function otoStatuses(): array
    {
        return [
            'delivered'            => ['Delivered', OrderShipment::STATUS_DELIVERED],
            // "out for delivery" contains "deliver" but must NOT map to delivered.
            'out for delivery'     => ['Out for delivery', OrderShipment::STATUS_IN_TRANSIT],
            'returned'             => ['Returned to sender', OrderShipment::STATUS_RETURNED],
            'cancelled'            => ['Cancelled', OrderShipment::STATUS_CANCELLED],
            'failed'               => ['Delivery failed', OrderShipment::STATUS_FAILED],
            'rejected'             => ['Rejected by courier', OrderShipment::STATUS_FAILED],
            'picked up'            => ['Picked up', OrderShipment::STATUS_IN_TRANSIT],
            'in transit'           => ['In transit', OrderShipment::STATUS_IN_TRANSIT],
            'in warehouse'         => ['Received in warehouse', OrderShipment::STATUS_IN_TRANSIT],
            'assigned'             => ['Assigned to driver', OrderShipment::STATUS_IN_TRANSIT],
            'processing'           => ['Processing', OrderShipment::STATUS_BOOKED],
            'created'              => ['Created', OrderShipment::STATUS_BOOKED],
            'unknown → booked'     => ['Some new status', OrderShipment::STATUS_BOOKED],
        ];
    }

    #[Test]
    #[DataProvider('otoStatuses')]
    public function mapsOtoStatusOntoTheLocalLifecycle(string $raw, string $expected): void
    {
        self::assertSame($expected, OrderShipment::mapOtoStatus($raw));
    }
}
