<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Shipping;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderAddress;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Shipping\Oto\OtoOrderPayloadBuilder;
use Bayti\Api\Shipping\ShippingException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtoOrderPayloadBuilder::class)]
final class OtoOrderPayloadBuilderTest extends TestCase
{
    private OtoOrderPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new OtoOrderPayloadBuilder(1.0);
    }

    #[Test]
    public function buildsCreateOrderPayloadForOneVendorSlice(): void
    {
        $vendor = $this->makeVendorWithPickup(7);
        $order = $this->makeOrderWithAddress('3B-123-2C27');
        $item1 = $this->addItem($order, $vendor, 'Beach Waves', '265.00', 1, 701);
        $item2 = $this->addItem($order, $vendor, 'Silk Abaya', '150.00', 2, 702);

        $body = $this->builder->build($order, $vendor, [$item1, $item2]);

        // Order-level: unique per-vendor id, prepaid, auto-shipment.
        self::assertSame('3B-123-2C27-V7', $body['orderId']);
        self::assertSame('paid', $body['payment_method']);
        self::assertSame(0.0, $body['amount_due']);
        self::assertSame(565.0, $body['amount']); // 265*1 + 150*2
        self::assertTrue($body['createShipment']);
        self::assertSame('AED', $body['currency']);
        self::assertSame(1.5, $body['packageWeight']); // 0.5 * 3 qty, >= 1.0 floor
        self::assertArrayNotHasKey('deliveryOptionId', $body); // auto-assign

        // Customer (recipient) block.
        self::assertSame('Mariam Alkaabi', $body['customer']['name']);
        self::assertSame('+971508099229', $body['customer']['mobile']);
        self::assertSame('Abu Dhabi', $body['customer']['city']);
        self::assertSame('AE', $body['customer']['country']);

        // Sender (vendor pickup) block.
        self::assertSame('Store Contact', $body['senderFullName']);
        self::assertSame('+971500000000', $body['senderMobile']);
        self::assertSame('Dubai', $body['senderCity']);
        self::assertSame('Al Quoz', $body['senderDistrict']);

        // Items.
        self::assertCount(2, $body['items']);
        self::assertSame('Beach Waves', $body['items'][0]['name']);
        self::assertSame(2, $body['items'][1]['quantity']);
    }

    #[Test]
    public function forcesTheChosenDeliveryOptionWhenGiven(): void
    {
        $vendor = $this->makeVendorWithPickup(7);
        $order = $this->makeOrderWithAddress('3B-999');
        $item = $this->addItem($order, $vendor, 'Kaftan', '99.00', 1, 800);

        $body = $this->builder->build($order, $vendor, [$item], 'aramex-domestic');

        self::assertSame('aramex-domestic', $body['deliveryOptionId']);
    }

    #[Test]
    public function throwsWhenTheVendorPickupAddressIsIncomplete(): void
    {
        $vendor = new Vendor('store-7', 'ABAYA BY MAS', 'v7@example.test'); // no pickup set
        $this->setId($vendor, 7);
        $order = $this->makeOrderWithAddress('3B-1');
        $item = $this->addItem($order, $vendor, 'Kaftan', '99.00', 1, 810);

        $this->expectException(ShippingException::class);
        $this->expectExceptionMessageMatches('/pickup address/i');
        $this->builder->build($order, $vendor, [$item]);
    }

    #[Test]
    public function throwsWhenTheOrderHasNoDeliveryAddress(): void
    {
        $vendor = $this->makeVendorWithPickup(7);
        $order = new Order(user: $this->makeUser(), orderReference: '3B-2', subtotal: '99.00');
        $this->setId($order, 55);
        $item = $this->addItem($order, $vendor, 'Kaftan', '99.00', 1, 820);

        $this->expectException(ShippingException::class);
        $this->expectExceptionMessageMatches('/delivery address/i');
        $this->builder->build($order, $vendor, [$item]);
    }

    // ===== Fixtures =====

    private function makeVendorWithPickup(int $id): Vendor
    {
        $vendor = new Vendor('store-' . $id, 'ABAYA BY MAS', 'v' . $id . '@example.test');
        $this->setId($vendor, $id);
        $vendor->setCountry('AE');
        $vendor->setPickupContactName('Store Contact');
        $vendor->setPickupPhone('+971500000000');
        $vendor->setPickupCity('Dubai');
        $vendor->setPickupArea('Al Quoz');
        $vendor->setPickupStreet('Warehouse 12, Street 4');
        $vendor->setPickupBuildingNo('12');
        return $vendor;
    }

    private function makeUser(): User
    {
        $u = new User('customer@example.com', '+971508099229', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $this->setId($u, 42);
        return $u;
    }

    private function makeOrderWithAddress(string $reference): Order
    {
        $order = new Order(user: $this->makeUser(), orderReference: $reference, subtotal: '265.00');
        $this->setId($order, 100);
        $order->addAddress(new OrderAddress(
            type: 'shipping',
            firstName: 'Mariam',
            phone: '+971508099229',
            email: 'customer@example.com',
            street: 'Shiab Alshker 31',
            city: 'Abu Dhabi',
            lastName: 'Alkaabi',
            stateProvince: 'Abu Dhabi',
            countryCode: 'AE',
        ));
        return $order;
    }

    private function addItem(
        Order $order,
        Vendor $vendor,
        string $name,
        string $unitPrice,
        int $qty,
        int $itemId,
    ): OrderItem {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($product, 'id', 200 + $itemId);
        $this->setProp($product, 'name', $name);
        $this->setProp($product, 'vendor', $vendor);

        $item = new OrderItem(
            product: $product,
            vendor: $vendor,
            quantity: $qty,
            unitPrice: $unitPrice,
            productNameSnapshot: $name,
            productImageSnapshot: 'cdn/' . $itemId . '.jpg',
        );
        $this->setId($item, $itemId);
        $order->addItem($item);
        return $item;
    }

    private function setId(object $entity, int $id): void
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
