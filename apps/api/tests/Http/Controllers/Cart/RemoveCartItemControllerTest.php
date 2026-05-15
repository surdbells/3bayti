<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Cart\RemoveCartItemController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RemoveCartItemController::class)]
final class RemoveCartItemControllerTest extends HttpTestCase
{
    #[Test]
    public function removesItemAndReturns200WithUpdatedCart(): void
    {
        $user = $this->makeUser(id: 7);
        $product = $this->makeProduct(id: 100, name: 'Silk Abaya', price: '299.00');

        $cart = new Cart(user: $user);
        $this->setEntityId($cart, 42);

        $item1 = new CartItem(product: $product, quantity: 1, unitPriceSnapshot: '299.00');
        $this->setEntityId($item1, 555);
        $cart->addItem($item1);

        $product2 = $this->makeProduct(id: 200, name: 'Kaftan', price: '199.00');
        $item2 = new CartItem(product: $product2, quantity: 2, unitPriceSnapshot: '199.00');
        $this->setEntityId($item2, 666);
        $cart->addItem($item2);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn($cart);
        $cartRepo->method('removeItem')->willReturnCallback(
            function (Cart $c, CartItem $i): void {
                $c->removeItem($i);
            },
        );

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
            $this->jsonRequest('DELETE', '/v3/cart/items/555', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        // One item left (the kaftan).
        self::assertCount(1, $body['cart']['items']);
        self::assertSame(200, $body['cart']['items'][0]['product_id']);
    }

    #[Test]
    public function returns404ForUnknownItem(): void
    {
        $user = $this->makeUser(id: 7);

        $cart = new Cart(user: $user);
        $this->setEntityId($cart, 42);

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
            $this->jsonRequest('DELETE', '/v3/cart/items/999', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenUserHasNoCart(): void
    {
        $user = $this->makeUser(id: 7);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn(null);

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
            $this->jsonRequest('DELETE', '/v3/cart/items/555', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function requiresAuth(): void
    {
        $response = $this->handle(
            $this->jsonRequest('DELETE', '/v3/cart/items/555')
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
