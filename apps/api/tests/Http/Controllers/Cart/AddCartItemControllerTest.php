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
use Bayti\Api\Http\Controllers\Cart\AddCartItemController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(AddCartItemController::class)]
final class AddCartItemControllerTest extends HttpTestCase
{
    #[Test]
    public function adds201CreatesCartWhenUserHasNone(): void
    {
        $user = $this->makeUser(id: 7);
        $product = $this->makeProduct(id: 100, name: 'Silk Abaya', price: '299.00');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->with(100)->willReturn($product);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn(null);
        // Capture the cart that gets persisted, attach an id, return on save.
        $cartRepo->method('saveWithItems')->willReturnCallback(
            function (Cart $cart): void {
                $this->setEntityId($cart, 42);
                foreach ($cart->getItems() as $item) {
                    if ($item->getId() === null) {
                        $this->setEntityId($item, 999);
                    }
                }
            },
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [Product::class, $productRepo],
            ]);
            // persist is called for new carts
            $em->method('persist');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/items', [
                'product_id' => 100,
                'quantity' => 2,
                'size' => 'M',
                'color' => 'Black',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(201, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame(2, $body['cart']['item_count']);
        self::assertSame('598.00', $body['cart']['subtotal']);
        self::assertCount(1, $body['cart']['items']);

        $line = $body['cart']['items'][0];
        self::assertSame(100, $line['product_id']);
        self::assertSame('Silk Abaya', $line['product_name']);
        self::assertSame(2, $line['quantity']);
        self::assertSame('299.00', $line['unit_price']);
        self::assertSame('M', $line['size']);
        self::assertSame('Black', $line['color']);
        self::assertFalse($line['is_custom']);
    }

    #[Test]
    public function mergesIntoExistingEquivalentLine(): void
    {
        $user = $this->makeUser(id: 7);
        $product = $this->makeProduct(id: 100, name: 'Silk Abaya', price: '299.00');

        $cart = new Cart(user: $user);
        $this->setEntityId($cart, 42);

        // Pre-existing line with same product + size + color
        $existing = new CartItem(
            product: $product,
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
        $productRepo->method('find')->with(100)->willReturn($product);

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
            $this->jsonRequest('POST', '/v3/cart/items', [
                'product_id' => 100,
                'quantity' => 3,
                'size' => 'M',
                'color' => 'Black',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(201, $response->getStatusCode());
        $body = $this->jsonBody($response);

        // 1 + 3 = 4, merged into the same line (not a new line).
        self::assertCount(1, $body['cart']['items']);
        self::assertSame(4, $body['cart']['items'][0]['quantity']);
        self::assertSame(4, $body['cart']['item_count']);
    }

    #[Test]
    public function rejectsUnknownProductWith404(): void
    {
        $user = $this->makeUser(id: 7);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->with(999)->willReturn(null);

        $em = $this->stubEm(function ($em) use ($userRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/items', [
                'product_id' => 999,
                'quantity' => 1,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function rejectsMissingProductIdWith422(): void
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
            $this->jsonRequest('POST', '/v3/cart/items', [
                'quantity' => 1,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsQuantityZeroWith422(): void
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
            $this->jsonRequest('POST', '/v3/cart/items', [
                'product_id' => 100,
                'quantity' => 0,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsRequiredExtraMeasurementMissingWith422(): void
    {
        $user = $this->makeUser(id: 7);
        $product = $this->makeProduct(id: 100, name: 'Bespoke Abaya', price: '799.00', requiresMsmt: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->with(100)->willReturn($product);

        $em = $this->stubEm(function ($em) use ($userRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/items', [
                'product_id' => 100,
                'quantity' => 1,
                /* no extra_measurement supplied */
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
        self::assertArrayHasKey('extra_measurement', $body['error']['details']['fields']);
    }

    #[Test]
    public function acceptsWithExtraMeasurementWith201(): void
    {
        $user = $this->makeUser(id: 7);
        $product = $this->makeProduct(id: 100, name: 'Bespoke Abaya', price: '799.00', requiresMsmt: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->with(100)->willReturn($product);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn(null);
        $cartRepo->method('saveWithItems')->willReturnCallback(
            function (Cart $cart): void {
                $this->setEntityId($cart, 42);
                foreach ($cart->getItems() as $item) {
                    if ($item->getId() === null) {
                        $this->setEntityId($item, 999);
                    }
                }
            },
        );

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
            $this->jsonRequest('POST', '/v3/cart/items', [
                'product_id' => 100,
                'quantity' => 1,
                'extra_measurement' => 'Length 56 inches',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(201, $response->getStatusCode());
        $line = $this->jsonBody($response)['cart']['items'][0];
        self::assertSame('Length 56 inches', $line['extra_measurement']);
    }

    #[Test]
    public function requiresAuth(): void
    {
        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/items', [
                'product_id' => 100,
                'quantity' => 1,
            ])
        );

        self::assertSame(401, $response->getStatusCode());
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeProduct(int $id, string $name, string $price, bool $requiresMsmt = false): Product
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($product, 'id', $id);
        $this->setEntityProp($product, 'name', $name);
        $this->setEntityProp($product, 'price', $price);
        $this->setEntityProp($product, 'isActive', true);
        $this->setEntityProp($product, 'requiresExtraMsmt', $requiresMsmt);
        return $product;
    }

    private function setEntityProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
