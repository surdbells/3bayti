<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Chat;

use Bayti\Api\Domain\Chat\OrderDetailsMessageBuilder;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderAddress;
use Bayti\Api\Domain\Order\OrderItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderDetailsMessageBuilder::class)]
final class OrderDetailsMessageBuilderTest extends TestCase
{
    private function address(): OrderAddress
    {
        $a = $this->createMock(OrderAddress::class);
        $a->method('getFirstName')->willReturn('Layla');
        $a->method('getLastName')->willReturn('Hassan');
        $a->method('getPhone')->willReturn('0501234567');
        $a->method('getStreet')->willReturn('Building 4, Marina Walk');
        $a->method('getCity')->willReturn('Dubai');
        $a->method('getStateProvince')->willReturn('Dubai');
        $a->method('getCountryCode')->willReturn('AE');
        $a->method('getPostalCode')->willReturn('00000');
        return $a;
    }

    private function order(OrderAddress $shipping): Order
    {
        $o = $this->createMock(Order::class);
        $o->method('getOrderReference')->willReturn('3B-2026-0001');
        $o->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2026-06-14 10:00'));
        $o->method('getCurrency')->willReturn('AED');
        $o->method('getSubtotal')->willReturn('450.00');
        $o->method('getDeliveryFee')->willReturn('20.00');
        $o->method('getDiscount')->willReturn('0.00');
        $o->method('getGiftCardAmount')->willReturn('50.00');
        $o->method('getTotal')->willReturn('420.00');
        $o->method('getShippingAddress')->willReturn($shipping);
        return $o;
    }

    private function item(string $measurement): OrderItem
    {
        $i = $this->createMock(OrderItem::class);
        $i->method('getItemStatus')->willReturn('processing');
        $i->method('getProductNameSnapshot')->willReturn('Custom Silk Abaya');
        $i->method('getQuantity')->willReturn(1);
        $i->method('getSize')->willReturn('M');
        $i->method('getColor')->willReturn('Midnight Blue');
        $i->method('isCustom')->willReturn(true);
        $i->method('getMeasurement')->willReturn($measurement);
        $i->method('getExtraMeasurement')->willReturn(null);
        $i->method('getNote')->willReturn('Please add inner lining');
        $i->method('getUnitPrice')->willReturn('450.00');
        $i->method('getSubtotal')->willReturn('450.00');
        return $i;
    }

    #[Test]
    public function buildsBilingualMessageWithAllSections(): void
    {
        $builder = new OrderDetailsMessageBuilder();
        $measurement = json_encode(['bust' => '92cm', 'sleeve_length' => '60cm']);
        [$en, $ar] = $builder->build($this->order($this->address()), $this->item((string) $measurement));

        // Sections
        self::assertStringContainsString('ORDER', $en);
        self::assertStringContainsString('ITEM', $en);
        self::assertStringContainsString('MEASUREMENTS', $en);
        self::assertStringContainsString('PRICING', $en);
        self::assertStringContainsString('SHIPPING', $en);

        // Item + decoded measurements (snake_case humanised)
        self::assertStringContainsString('Custom Silk Abaya', $en);
        self::assertStringContainsString('Bust: 92cm', $en);
        self::assertStringContainsString('Sleeve Length: 60cm', $en);
        self::assertStringContainsString('Please add inner lining', $en);

        // Pricing incl. gift card (because > 0), excl. discount (0)
        self::assertStringContainsString('AED 420.00', $en);
        self::assertStringContainsString('Gift card: -AED 50.00', $en);
        self::assertStringNotContainsString('Discount:', $en);

        // Shipping details present
        self::assertStringContainsString('Layla Hassan', $en);
        self::assertStringContainsString('Building 4, Marina Walk', $en);
        self::assertStringContainsString('Dubai, Dubai', $en);

        // Policy reminder
        self::assertStringContainsString('not allowed', $en);

        // Arabic rendering present with Arabic labels + shared values
        self::assertNotSame('', trim($ar));
        self::assertStringContainsString('الطلب', $ar);
        self::assertStringContainsString('الشحن', $ar);
        self::assertStringContainsString('Custom Silk Abaya', $ar);
    }

    #[Test]
    public function freeTextMeasurementFallsBackToDetails(): void
    {
        $builder = new OrderDetailsMessageBuilder();
        [$en] = $builder->build($this->order($this->address()), $this->item('Loose fit, ankle length'));
        self::assertStringContainsString('Details: Loose fit, ankle length', $en);
    }
}
