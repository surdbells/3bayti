<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Order;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Order\ReorderController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(ReorderController::class)]
final class ReorderControllerTest extends HttpTestCase
{
    #[Test]
    public function addsAvailableItemsAndSkipsUnavailableOnes(): void
    {
        $user = $this->makeUser(id: 7);
        $vendor = $this->makeVendor(5);
        $order = $this->makeOrder($user, 100);
        // One active product (added), one inactive product (skipped).
        $order->addItem($this->makeItem($this->makeProduct(200, active: true), $vendor, 'Abaya'));
        $order->addItem($this->makeItem($this->makeProduct(201, active: false), $vendor, 'Kaftan'));

        $this->bindEm($user, $order, new Cart(user: $user));
        $response = $this->post($user, '/v3/orders/100/reorder');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(1, $body['reorder']['added']);
        self::assertSame(1, $body['reorder']['skipped']);
        self::assertSame(2, $body['reorder']['total']);
    }

    #[Test]
    public function returns404WhenOrderNotFound(): void
    {
        $user = $this->makeUser(id: 7);
        $this->bindEm($user, null, new Cart(user: $user));

        $response = $this->post($user, '/v3/orders/999/reorder');
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function requiresAuth(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/orders/100/reorder'));
        self::assertSame(401, $response->getStatusCode());
    }

    // ===== helpers =====

    private function makeOrder(User $user, int $id): Order
    {
        $order = new Order(user: $user, orderReference: 'V3-' . $id, subtotal: '100.00');
        $this->setProp($order, 'id', $id);
        return $order;
    }

    private function makeItem(Product $product, Vendor $vendor, string $name): OrderItem
    {
        return new OrderItem(
            product: $product,
            vendor: $vendor,
            quantity: 1,
            unitPrice: '100.00',
            productNameSnapshot: $name,
        );
    }

    private function makeProduct(int $id, bool $active): Product
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($product, 'id', $id);
        $this->setProp($product, 'name', 'Product ' . $id);
        $this->setProp($product, 'price', '100.00');
        $this->setProp($product, 'salePrice', null);
        $this->setProp($product, 'isActive', $active);
        $this->setProp($product, 'requiresExtraMsmt', false);
        // CartSerializer::itemShape reads the primary image on the added line.
        $this->setProp($product, 'primaryImageUrl', null);
        return $product;
    }

    private function makeVendor(int $id): Vendor
    {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setProp($vendor, 'id', $id);
        return $vendor;
    }

    private function bindEm(User $user, ?Order $order, Cart $cart): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForUser')->willReturn($order);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->willReturn($cart);

        // CartSerializer is final; use the real one (the product fixtures carry
        // the fields itemShape reads).

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo, $cartRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
                [Cart::class, $cartRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function post(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('POST', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
