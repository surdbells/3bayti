<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Cart\UpdateCartItemController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(UpdateCartItemController::class)]
final class UpdateCartItemControllerTest extends HttpTestCase
{
    #[Test]
    public function setsQuantityAndReturns200(): void
    {
        $user = $this->makeUser(id: 7);
        $product = $this->makeProduct(id: 100, name: 'Silk Abaya', price: '299.00');

        $cart = new Cart(user: $user);
        $this->setEntityId($cart, 42);

        $item = new CartItem(
            product: $product,
            quantity: 1,
            unitPriceSnapshot: '299.00',
        );
        $this->setEntityId($item, 555);
        $cart->addItem($item);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn($cart);
        $cartRepo->method('saveWithItems');

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/cart/items/555', [
                'quantity' => 5,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertCount(1, $body['cart']['items']);
        self::assertSame(5, $body['cart']['items'][0]['quantity']);
    }

    #[Test]
    public function returns404ForOtherUsersItem(): void
    {
        $user = $this->makeUser(id: 7);

        // User's cart has item 555; they're asking to PATCH item 999.
        $cart = new Cart(user: $user);
        $this->setEntityId($cart, 42);

        $product = $this->makeProduct(id: 100, name: 'Abaya', price: '100.00');
        $item = new CartItem(product: $product, quantity: 1, unitPriceSnapshot: '100.00');
        $this->setEntityId($item, 555);
        $cart->addItem($item);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn($cart);

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/cart/items/999', [
                'quantity' => 3,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function rejectsQuantityZero(): void
    {
        $user = $this->makeUser(id: 7);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/cart/items/555', [
                'quantity' => 0,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        $msg = json_encode($body);
        self::assertNotFalse($msg);
        self::assertStringContainsString('DELETE to remove', $msg);
    }

    #[Test]
    public function requiresAuth(): void
    {
        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/cart/items/555', ['quantity' => 3])
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
