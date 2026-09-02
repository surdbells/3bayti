<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Order;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Order::class)]
final class OrderChannelTest extends TestCase
{
    #[Test]
    public function storesTheCheckoutChannelUppercased(): void
    {
        // The web client sends 'web'; it's normalised to 'WEB'.
        $order = new Order(
            user: $this->user(),
            orderReference: 'V3-1',
            subtotal: '100.00',
            channel: 'web',
        );
        self::assertSame('WEB', $order->getChannel());
    }

    #[Test]
    public function nullAndEmptyChannelAreNull(): void
    {
        self::assertNull($this->orderWithChannel(null)->getChannel(), 'omitted → null');
        self::assertNull($this->orderWithChannel('')->getChannel(), 'empty → null');
        self::assertNull($this->orderWithChannel('   ')->getChannel(), 'blank → null');
    }

    #[Test]
    public function mobileChannelIsPreserved(): void
    {
        self::assertSame('MOBILE', $this->orderWithChannel('MOBILE')->getChannel());
    }

    private function orderWithChannel(?string $channel): Order
    {
        return new Order(
            user: $this->user(),
            orderReference: 'V3-1',
            subtotal: '100.00',
            channel: $channel,
        );
    }

    private function user(): User
    {
        return new User('c@example.test', '+971500000000', null, 'AE');
    }
}
