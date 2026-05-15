<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Cart\MergeAnonCartController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(MergeAnonCartController::class)]
final class MergeAnonCartControllerTest extends HttpTestCase
{
    #[Test]
    public function mergesGuestItemsIntoFreshServerCart(): void
    {
        $user = $this->makeUser(id: 7);
        $p1 = $this->makeProduct(id: 100, name: 'Silk Abaya', price: '299.00');
        $p2 = $this->makeProduct(id: 200, name: 'Kaftan', price: '199.00');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->willReturnMap([
            [100, $p1],
            [200, $p2],
        ]);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn(null);
        $cartRepo->method('saveWithItems');

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [Product::class, $productRepo],
            ]);
            $em->method('persist');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/merge', [
                'items' => [
                    ['product_id' => 100, 'quantity' => 2, 'size' => 'M'],
                    ['product_id' => 200, 'quantity' => 1],
                ],
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(2, $body['cart']['items']);
        self::assertSame([], $body['skipped']);
    }

    #[Test]
    public function mergesIntoExistingServerCartByEquivalence(): void
    {
        $user = $this->makeUser(id: 7);
        $p1 = $this->makeProduct(id: 100, name: 'Silk Abaya', price: '299.00');

        $cart = new Cart(user: $user);
        $this->setEntityId($cart, 42);
        $existing = new CartItem(
            product: $p1,
            quantity: 1,
            unitPriceSnapshot: '299.00',
            size: 'M',
            color: 'Black',
        );
        $this->setEntityId($existing, 555);
        $cart->addItem($existing);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->with(100)->willReturn($p1);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn($cart);
        $cartRepo->method('saveWithItems');

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/merge', [
                'items' => [
                    ['product_id' => 100, 'quantity' => 3, 'size' => 'M', 'color' => 'Black'],
                ],
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        // 1 + 3 = 4, merged into one line.
        self::assertCount(1, $body['cart']['items']);
        self::assertSame(4, $body['cart']['items'][0]['quantity']);
    }

    #[Test]
    public function skipsUnknownProducts(): void
    {
        $user = $this->makeUser(id: 7);
        $p1 = $this->makeProduct(id: 100, name: 'Silk Abaya', price: '299.00');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->willReturnMap([
            [100, $p1],
            [999, null], // unknown
        ]);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn(null);
        $cartRepo->method('saveWithItems');

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [Product::class, $productRepo],
            ]);
            $em->method('persist');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/merge', [
                'items' => [
                    ['product_id' => 100, 'quantity' => 1],
                    ['product_id' => 999, 'quantity' => 1],
                ],
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertCount(1, $body['cart']['items']);
        self::assertCount(1, $body['skipped']);
        self::assertSame(999, $body['skipped'][0]['product_id']);
        self::assertSame('product_unavailable', $body['skipped'][0]['reason']);
    }

    #[Test]
    public function emptyItemsArrayIsNoOp(): void
    {
        $user = $this->makeUser(id: 7);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $productRepo = $this->createMock(ProductRepository::class);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn(null);
        $cartRepo->method('saveWithItems');

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [Product::class, $productRepo],
            ]);
            $em->method('persist');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/merge', [
                'items' => [],
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['cart']['items']);
        self::assertSame([], $body['skipped']);
    }

    #[Test]
    public function requiresAuth(): void
    {
        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/merge', ['items' => []])
        );

        self::assertSame(401, $response->getStatusCode());
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
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

    private function setEntityProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
