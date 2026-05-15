<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Order;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderAddress;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Order\GetOrderController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(GetOrderController::class)]
final class GetOrderControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsOrderWithItemsAndAddresses(): void
    {
        $user = $this->makeUser(id: 7);

        $order = new Order(user: $user, orderReference: 'V3-001', subtotal: '299.00');
        $this->setEntityId($order, 100);

        $vendor = $this->makeVendor(id: 5);
        $product = $this->makeProduct(id: 200, name: 'Silk Abaya', price: '299.00');

        $item = new OrderItem(
            product: $product,
            vendor: $vendor,
            quantity: 1,
            unitPrice: '299.00',
            productNameSnapshot: 'Silk Abaya',
            productImageSnapshot: 'https://cdn/abaya.jpg',
        );
        $this->setEntityId($item, 555);
        $order->addItem($item);

        $billing = new OrderAddress(
            type: OrderAddress::TYPE_BILLING,
            firstName: 'Sodiq',
            phone: '500000000',
            email: 'sodiq@test.local',
            street: '123 Main St',
            city: 'Dubai',
        );
        $shipping = new OrderAddress(
            type: OrderAddress::TYPE_SHIPPING,
            firstName: 'Sodiq',
            phone: '500000000',
            email: 'sodiq@test.local',
            street: '456 Ship Rd',
            city: 'Abu Dhabi',
        );
        $order->addAddress($billing);
        $order->addAddress($shipping);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForUser')->with(100, $user)->willReturn($order);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/orders/100', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame(100, $body['order']['id']);
        self::assertSame('V3-001', $body['order']['order_reference']);
        self::assertSame('299.00', $body['order']['total']);
        self::assertCount(1, $body['order']['items']);
        self::assertSame('Silk Abaya', $body['order']['items'][0]['product_name']);

        self::assertSame('Dubai', $body['order']['billing_address']['city']);
        self::assertSame('Abu Dhabi', $body['order']['shipping_address']['city']);
    }

    #[Test]
    public function returns404WhenOrderNotFound(): void
    {
        $user = $this->makeUser(id: 7);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForUser')->willReturn(null);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/orders/999', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404ForOtherUsersOrder(): void
    {
        $user = $this->makeUser(id: 7);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        // findForUser enforces tenant-id at query level; returns null
        // when the order belongs to another user.
        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->expects(self::once())
            ->method('findForUser')
            ->with(100, $user)
            ->willReturn(null);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/orders/100', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        // 404, not 403, to avoid leaking that the order exists.
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function requiresAuth(): void
    {
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/orders/100')
        );

        self::assertSame(401, $response->getStatusCode());
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

    private function makeProduct(int $id, string $name, string $price): Product
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($product, 'id', $id);
        $this->setEntityProp($product, 'name', $name);
        $this->setEntityProp($product, 'price', $price);
        $this->setEntityProp($product, 'isActive', true);
        return $product;
    }

    private function makeVendor(int $id): Vendor
    {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($vendor, 'id', $id);
        return $vendor;
    }
}
