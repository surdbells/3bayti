<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Order;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Order\ListOrdersController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ListOrdersController::class)]
final class ListOrdersControllerTest extends HttpTestCase
{
    #[Test]
    public function returns200WithEmptyListWhenNoOrders(): void
    {
        $user = $this->makeUser(id: 7);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('paginatedForUser')->willReturn([[], 0]);

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
            $this->jsonRequest('GET', '/v3/orders', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame([], $body['orders']);
        self::assertSame(10, $body['pagination']['limit']);
        self::assertSame(0, $body['pagination']['offset']);
        self::assertSame(0, $body['pagination']['count']);
        self::assertSame(0, $body['pagination']['total']);
    }

    #[Test]
    public function returnsOrdersWithItems(): void
    {
        $user = $this->makeUser(id: 7);
        $order = $this->makeOrder($user, id: 100, reference: 'V3-001', subtotal: '299.00');

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

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('paginatedForUser')->willReturn([[$order], 1]);

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
            $this->jsonRequest('GET', '/v3/orders', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertCount(1, $body['orders']);
        self::assertSame(100, $body['orders'][0]['id']);
        self::assertSame('V3-001', $body['orders'][0]['order_reference']);
        self::assertSame('299.00', $body['orders'][0]['total']);
        self::assertCount(1, $body['orders'][0]['items']);
        self::assertSame('Silk Abaya', $body['orders'][0]['items'][0]['product_name']);
        self::assertSame(5, $body['orders'][0]['items'][0]['store']);
    }

    #[Test]
    public function clampsLimitAndOffsetFromQuery(): void
    {
        $user = $this->makeUser(id: 7);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        // Verify the controller passed clamped values to repo (no status filter)
        $orderRepo->expects(self::once())
            ->method('paginatedForUser')
            ->with($user, 50, 20, null)
            ->willReturn([[], 0]);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        // limit=999 should clamp to 50; offset=20 passes through
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/orders?limit=999&offset=20', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(50, $body['pagination']['limit']);
        self::assertSame(20, $body['pagination']['offset']);
    }

    #[Test]
    public function passesKnownStatusFilterToRepo(): void
    {
        $user = $this->makeUser(id: 7);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->expects(self::once())
            ->method('paginatedForUser')
            ->with($user, 10, 0, 'delivered')
            ->willReturn([[], 0]);

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
            $this->jsonRequest('GET', '/v3/orders?status=delivered', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('delivered', $body['pagination']['status']);
    }

    #[Test]
    public function ignoresUnknownStatusFilter(): void
    {
        $user = $this->makeUser(id: 7);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        // 'bogus' is not a known Order status → controller normalises to null
        $orderRepo->expects(self::once())
            ->method('paginatedForUser')
            ->with($user, 10, 0, null)
            ->willReturn([[], 0]);

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
            $this->jsonRequest('GET', '/v3/orders?status=bogus', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertNull($body['pagination']['status']);
    }

    #[Test]
    public function requiresAuth(): void
    {
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/orders')
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

    private function makeOrder(User $user, int $id, string $reference, string $subtotal): Order
    {
        $order = new Order(user: $user, orderReference: $reference, subtotal: $subtotal);
        $this->setEntityId($order, $id);
        return $order;
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
