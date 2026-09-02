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
use Bayti\Api\Http\Controllers\Vendor\Order\ListVendorOrdersController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ListVendorOrdersController::class)]
final class ListVendorOrdersControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsEmptyListWhenVendorOwnsNoStores(): void
    {
        $user = $this->makeVendorUser(7);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->with($user)->willReturn([]);

        $orderRepo = $this->createMock(OrderRepository::class);
        // With empty vendor ids, repo returns [[], 0]
        $orderRepo->method('paginatedForVendorIds')->willReturn([[], 0]);

        $this->bindEm($user, $orderRepo, $vendorRepo);
        $response = $this->makeRequest($user, '/v3/vendor/orders');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['orders']);
        self::assertSame(0, $body['pagination']['total']);
    }

    #[Test]
    public function returnsOnlyOrdersContainingOwnedVendorItems(): void
    {
        $user = $this->makeVendorUser(7);
        $myVendor = $this->makeVendor(id: 5);
        $otherVendor = $this->makeVendor(id: 6);

        $product = $this->makeProduct(id: 200, name: 'Silk Abaya');
        $order = $this->makeOrder($user, id: 100, reference: 'V3-001', subtotal: '299.00');

        // Order contains item from my vendor (5) AND another vendor (6).
        // Repository scopes already filter to orders containing my items;
        // serializer filters items[] to only my vendor's.
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
            productNameSnapshot: 'Cross-vendor item',
            productImageSnapshot: 'cdn/other.jpg',
        );
        $this->setEntityId($otherItem, 502);
        $order->addItem($otherItem);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('paginatedForVendorIds')
            ->with([5], 10, 0, null, null, null, null)
            ->willReturn([[$order], 1]);

        $this->bindEm($user, $orderRepo, $vendorRepo);
        $response = $this->makeRequest($user, '/v3/vendor/orders');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(1, $body['orders']);

        $items = $body['orders'][0]['items'];
        self::assertCount(1, $items, 'Cross-vendor item should be filtered out');
        self::assertSame(5, $items[0]['vendor_id']);
        self::assertSame('Silk Abaya', $items[0]['product_name']);
    }

    #[Test]
    public function statusFilterIsPassedToRepository(): void
    {
        $user = $this->makeVendorUser(7);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->expects(self::once())
            ->method('paginatedForVendorIds')
            ->with([5], 10, 0, Order::STATUS_FULFILLING, null, null, null)
            ->willReturn([[], 0]);

        $this->bindEm($user, $orderRepo, $vendorRepo);
        $response = $this->makeRequest($user, '/v3/vendor/orders?status=fulfilling');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function invalidStatusFilterIsIgnored(): void
    {
        $user = $this->makeVendorUser(7);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->expects(self::once())
            ->method('paginatedForVendorIds')
            ->with([5], 10, 0, null, null, null, null) // null because the bogus status drops
            ->willReturn([[], 0]);

        $this->bindEm($user, $orderRepo, $vendorRepo);
        $response = $this->makeRequest($user, '/v3/vendor/orders?status=bogus');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function cancelledStatusFilterIsIgnored(): void
    {
        // Cancelled orders are hidden from vendors, so status=cancelled is no
        // longer a valid vendor filter: it drops to null (and the repository
        // excludes cancelled unconditionally regardless).
        $user = $this->makeVendorUser(7);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->expects(self::once())
            ->method('paginatedForVendorIds')
            ->with([5], 10, 0, null, null, null, null)
            ->willReturn([[], 0]);

        $this->bindEm($user, $orderRepo, $vendorRepo);
        $response = $this->makeRequest($user, '/v3/vendor/orders?status=cancelled');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function paginationParamsAreClampedAndForwarded(): void
    {
        $user = $this->makeVendorUser(7);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);

        $orderRepo = $this->createMock(OrderRepository::class);
        // limit clamped to 100 (max); offset preserved
        $orderRepo->expects(self::once())
            ->method('paginatedForVendorIds')
            ->with([5], 100, 50, null, null, null, null)
            ->willReturn([[], 0]);

        $this->bindEm($user, $orderRepo, $vendorRepo);
        $response = $this->makeRequest($user, '/v3/vendor/orders?limit=9999&offset=50');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(100, $body['pagination']['limit']);
        self::assertSame(50, $body['pagination']['offset']);
    }

    #[Test]
    public function searchAndDateRangeAreForwarded(): void
    {
        $user = $this->makeVendorUser(7);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->expects(self::once())
            ->method('paginatedForVendorIds')
            ->with([5], 10, 0, null, 'abaya', '2026-06-01', '2026-06-30')
            ->willReturn([[], 0]);

        $this->bindEm($user, $orderRepo, $vendorRepo);
        $response = $this->makeRequest(
            $user,
            '/v3/vendor/orders?search=abaya&date_from=2026-06-01&date_to=2026-06-30',
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function malformedDateIsDropped(): void
    {
        $user = $this->makeVendorUser(7);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([5]);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->expects(self::once())
            ->method('paginatedForVendorIds')
            ->with([5], 10, 0, null, null, null, null) // junk date + blank search → null
            ->willReturn([[], 0]);

        $this->bindEm($user, $orderRepo, $vendorRepo);
        $response = $this->makeRequest($user, '/v3/vendor/orders?search=%20%20&date_from=June%201st');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function nonVendorGets403(): void
    {
        // Plain customer hitting /v3/vendor/orders, middleware rejects.
        $user = $this->makeUser(id: 99);
        // user is NOT a vendor

        // No EM stubs needed, middleware short-circuits before controller.
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        // We need user repo to find the user during AuthMiddleware
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendor/orders', [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));

        self::assertSame(403, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('vendor_required', $body['error']['code']);
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

    private function makeRequest(User $user, string $uri): \Psr\Http\Message\ResponseInterface
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
