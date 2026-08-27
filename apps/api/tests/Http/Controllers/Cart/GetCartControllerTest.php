<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Cart\GetCartController;
use Bayti\Api\Http\Serializers\CartSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(GetCartController::class)]
#[CoversClass(CartSerializer::class)]
final class GetCartControllerTest extends HttpTestCase
{
    #[Test]
    public function returns200WithEmptyCartShapeWhenUserHasNoCart(): void
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
            $this->jsonRequest('GET', '/v3/cart', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame(0, $body['cart']['id']);
        self::assertSame('active', $body['cart']['status']);
        self::assertSame('AED', $body['cart']['currency']);
        self::assertSame('PND', $body['cart']['cart_code']);
        self::assertSame('0.00', $body['cart']['subtotal']);
        self::assertSame(0, $body['cart']['item_count']);
        self::assertSame([], $body['cart']['items']);
    }

    #[Test]
    public function returns200WithItemsWhenCartExists(): void
    {
        $user = $this->makeUser(id: 7);

        // Build a cart with one item using reflection for IDs.
        $cart = new Cart(user: $user);
        $this->setEntityId($cart, 42);

        $product = $this->makeProduct(id: 100, name: 'Silk Abaya', price: '299.00');
        $item = new CartItem(
            product: $product,
            quantity: 2,
            unitPriceSnapshot: '299.00',
            size: 'M',
            color: 'Black',
        );
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
            $this->jsonRequest('GET', '/v3/cart', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame(42, $body['cart']['id']);
        self::assertSame(2, $body['cart']['item_count']); // sum of quantities (1 line × qty 2)
        self::assertSame('598.00', $body['cart']['subtotal']);

        self::assertCount(1, $body['cart']['items']);
        $line = $body['cart']['items'][0];
        self::assertSame(555, $line['id']);
        self::assertSame(100, $line['product_id']);
        self::assertSame('Silk Abaya', $line['product_name']);
        self::assertSame(2, $line['quantity']);
        self::assertSame('299.00', $line['unit_price']);
        self::assertSame('598.00', $line['line_subtotal']);
        self::assertSame('M', $line['size']);
        self::assertSame('Black', $line['color']);
        self::assertFalse($line['is_custom']);
    }

    #[Test]
    public function returns401WithoutAuthHeader(): void
    {
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/cart')
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
        // Build minimal Product via reflection, bypass constructor
        // requirements (vendor, etc.) we don't need to exercise here.
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
