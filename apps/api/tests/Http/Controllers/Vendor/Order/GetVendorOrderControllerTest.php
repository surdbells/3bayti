<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor\Order;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\Order\GetVendorOrderController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(GetVendorOrderController::class)]
final class GetVendorOrderControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsOrderWithItemsFilteredToVendor(): void
    {
        $user = $this->makeVendorUser(7);
        $myVendor = $this->makeVendor(id: 5);
        $otherVendor = $this->makeVendor(id: 6);
        $product = $this->makeProduct(id: 200, name: 'Silk Abaya');
        $order = $this->makeOrder($user, id: 100, reference: 'V3-001', subtotal: '299.00');

        $myItem = new OrderItem(
            product: $product, vendor: $myVendor,
            quantity: 1, unitPrice: '299.00',
            productNameSnapshot: 'Silk Abaya',
            productImageSnapshot: 'cdn/silk.jpg',
        );
        $this->setEntityId($myItem, 501);
        $order->addItem($myItem);

        $otherItem = new OrderItem(
            product: $product, vendor: $otherVendor,
            quantity: 1, unitPrice: '99.00',
            productNameSnapshot: 'OTHER VENDOR PRIVATE',
            productImageSnapshot: 'cdn/other.jpg',
        );
        $this->setEntityId($otherItem, 502);
        $order->addItem($otherItem);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForVendorIds')->with(100, [5])->willReturn($order);

        $this->bindEm($user, $orderRepo, $vendorRepo);
        $response = $this->makeGet($user, '/v3/vendor/orders/100');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        $items = $body['order']['items'];
        self::assertCount(1, $items, 'Cross-vendor item must not leak');
        self::assertSame(5, $items[0]['vendor_id']);
        self::assertSame('Silk Abaya', $items[0]['product_name']);

        // Sanity: the other vendor's content is not anywhere in the body
        $bodyJson = json_encode($body);
        self::assertStringNotContainsString('OTHER VENDOR PRIVATE', $bodyJson);
    }

    #[Test]
    public function returns404WhenOrderDoesNotExist(): void
    {
        $user = $this->makeVendorUser(7);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForVendorIds')->willReturn(null);

        $this->bindEm($user, $orderRepo, $vendorRepo);
        $response = $this->makeGet($user, '/v3/vendor/orders/9999');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404ForCrossVendorOrderNot403(): void
    {
        // Vendor A authenticates and requests Vendor B's order id.
        // Repository returns null because the order has no items from
        // Vendor A. Controller MUST return 404 (not 403) to avoid
        // leaking the existence of orders the vendor can't see.
        $user = $this->makeVendorUser(7);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);

        $orderRepo = $this->createMock(OrderRepository::class);
        // The order exists in DB but findForVendorIds returns null
        // because none of its items are from vendor 5.
        $orderRepo->method('findForVendorIds')->with(100, [5])->willReturn(null);

        $this->bindEm($user, $orderRepo, $vendorRepo);
        $response = $this->makeGet($user, '/v3/vendor/orders/100');

        self::assertSame(404, $response->getStatusCode(), 'Must be 404 not 403');
    }

    #[Test]
    public function returns404WhenVendorOwnsNoStores(): void
    {
        $user = $this->makeVendorUser(7);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([]);

        $orderRepo = $this->createMock(OrderRepository::class);
        // findForVendorIds with empty list returns null
        $orderRepo->method('findForVendorIds')->willReturn(null);

        $this->bindEm($user, $orderRepo, $vendorRepo);
        $response = $this->makeGet($user, '/v3/vendor/orders/100');

        self::assertSame(404, $response->getStatusCode());
    }

    // ===== Helpers =====

    private function makeVendorUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(vendor: true);
        return $user;
    }

    private function bindEm(User $user, OrderRepository $orderRepo, VendorRepository $vendorRepo): void
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
    }

    private function makeGet(User $user, string $uri): \Psr\Http\Message\ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
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

    private function makeProduct(int $id, string $name): Product
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($product, 'id', $id);
        $this->setEntityProp($product, 'name', $name);
        return $product;
    }

    private function makeVendor(int $id): Vendor
    {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($vendor, 'id', $id);
        return $vendor;
    }
}
