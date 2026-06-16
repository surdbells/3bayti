<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Cart;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Http\Controllers\Cart\ResolveCartController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * HTTP tests for the public guest-cart resolve endpoint. The EM is
 * stubbed (no DB) and products are mocked, so we verify the resolution
 * logic + response shape: current name/image/price per line, computed
 * line + cart subtotals, dropped unknown products, and that no auth is
 * required.
 */
#[CoversClass(ResolveCartController::class)]
final class ResolveCartControllerTest extends HttpTestCase
{
    #[Test]
    public function resolvesGuestItemsWithCurrentNamesPricesAndImages(): void
    {
        $p1 = $this->makeProduct(id: 100, name: 'Silk Abaya', price: '299.00', image: 'https://cdn.test/abaya.jpg');
        $p2 = $this->makeProduct(id: 200, name: 'Kaftan', price: '199.00', image: null);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->willReturnMap([
            [100, $p1],
            [200, $p2],
        ]);

        $em = $this->stubEm(function ($em) use ($productRepo): void {
            $em->method('getRepository')->willReturnMap([
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/resolve', [
                'items' => [
                    ['product_id' => 100, 'quantity' => 2, 'size' => 'M', 'color' => 'Black'],
                    ['product_id' => 200, 'quantity' => 1],
                ],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertCount(2, $body['cart']['items']);

        $line1 = $body['cart']['items'][0];
        self::assertSame(100, $line1['product_id']);
        self::assertSame('Silk Abaya', $line1['product_name']);
        self::assertSame('https://cdn.test/abaya.jpg', $line1['product_image']);
        self::assertSame('299.00', $line1['unit_price']);
        self::assertSame('598.00', $line1['line_subtotal']); // 299.00 × 2
        self::assertSame('M', $line1['size']);
        self::assertSame('Black', $line1['color']);

        // Missing image resolves to empty string, not null.
        self::assertSame('', $body['cart']['items'][1]['product_image']);

        // Cart subtotal = 598.00 + 199.00.
        self::assertSame('797.00', $body['cart']['subtotal']);
        self::assertSame(3, $body['cart']['item_count']);
        self::assertSame([], $body['removed']);
    }

    #[Test]
    public function dropsUnknownProductsAndReportsThem(): void
    {
        $p1 = $this->makeProduct(id: 100, name: 'Silk Abaya', price: '299.00', image: null);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->willReturnMap([
            [100, $p1],
            [999, null], // deleted/unknown
        ]);

        $em = $this->stubEm(function ($em) use ($productRepo): void {
            $em->method('getRepository')->willReturnMap([
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/resolve', [
                'items' => [
                    ['product_id' => 100, 'quantity' => 1],
                    ['product_id' => 999, 'quantity' => 1],
                ],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertCount(1, $body['cart']['items']);
        self::assertSame(100, $body['cart']['items'][0]['product_id']);
        self::assertSame([999], $body['removed']);
    }

    #[Test]
    public function dropsInactiveProducts(): void
    {
        $inactive = $this->makeProduct(id: 100, name: 'Discontinued', price: '50.00', image: null, active: false);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->willReturnMap([[100, $inactive]]);

        $em = $this->stubEm(function ($em) use ($productRepo): void {
            $em->method('getRepository')->willReturnMap([
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/resolve', [
                'items' => [['product_id' => 100, 'quantity' => 1]],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['cart']['items']);
        self::assertSame([100], $body['removed']);
    }

    #[Test]
    public function emptyItemsReturnsEmptyCart(): void
    {
        $productRepo = $this->createMock(ProductRepository::class);

        $em = $this->stubEm(function ($em) use ($productRepo): void {
            $em->method('getRepository')->willReturnMap([
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/resolve', ['items' => []])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['cart']['items']);
        self::assertSame('0.00', $body['cart']['subtotal']);
        self::assertSame(0, $body['cart']['item_count']);
        self::assertSame([], $body['removed']);
    }

    #[Test]
    public function requiresNoAuth(): void
    {
        // Public endpoint: a request with no Authorization header must NOT 401.
        $productRepo = $this->createMock(ProductRepository::class);

        $em = $this->stubEm(function ($em) use ($productRepo): void {
            $em->method('getRepository')->willReturnMap([
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/resolve', ['items' => []])
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function makeProduct(
        int $id,
        string $name,
        string $price,
        ?string $image,
        bool $active = true,
    ): Product {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($product, 'id', $id);
        $this->setEntityProp($product, 'name', $name);
        $this->setEntityProp($product, 'price', $price);
        $this->setEntityProp($product, 'primaryImageUrl', $image);
        $this->setEntityProp($product, 'isActive', $active);
        return $product;
    }

    private function setEntityProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
