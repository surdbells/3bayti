<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\Product\ListVendorOwnProductsController;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /v3/vendor/products, verifies the vendor product-management shape
 * (status, stock, category name, flat image/price keys) and that filter
 * query params are forwarded to the repository.
 */
#[CoversClass(ListVendorOwnProductsController::class)]
#[CoversClass(ProductSerializer::class)]
final class ListVendorOwnProductsControllerTest extends HttpTestCase
{
    /** @var array<string, mixed>|null captured filters passed to the repo */
    private ?array $capturedFilters = null;

    private function makeVendorUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(vendor: true);
        return $u;
    }

    private function makeVendor(int $id): Vendor
    {
        $v = new Vendor("vendor-{$id}", "Vendor {$id}", "vendor{$id}@example.com");
        $v->approve();
        $rp = new \ReflectionProperty($v, 'id');
        $rp->setAccessible(true);
        $rp->setValue($v, $id);
        return $v;
    }

    private function makeProduct(Vendor $vendor, int $id): Product
    {
        $p = new Product($vendor, "prod-{$id}", "Product {$id}");
        $cat = new Category('abayas', 'Abayas');
        $p->setCategory($cat);
        $p->setStatus(Product::STATUS_ACTIVE);
        $p->setPrice('450.00');
        $p->setStockQuantity(7);
        $p->setStockStatus(Product::STOCK_IN);
        $rp = new \ReflectionProperty($p, 'id');
        $rp->setAccessible(true);
        $rp->setValue($p, $id);
        return $p;
    }

    private function bindDeps(User $user, Vendor $vendor, array $products): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([(int) $vendor->getId()]);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(true);
        $vendorRepo->method('find')->willReturn($vendor);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findForVendorPaginated')->willReturnCallback(
            function (array $filters) use ($products): array {
                $this->capturedFilters = $filters;
                return ['items' => $products, 'total' => count($products)];
            },
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $productRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function get(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    #[Test]
    public function returnsVendorManagementShape(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor, [$this->makeProduct($vendor, 5)]);

        $res = $this->get($user, '/v3/vendor/products');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $row = $this->jsonBody($res)['data'][0];

        // The management fields the portal table + image cell read.
        self::assertSame('active', $row['status']);
        self::assertSame('Abayas', $row['category']);
        self::assertSame(7, $row['quantity']);
        self::assertSame(7, $row['stock_quantity']);
        self::assertSame('in_stock', $row['stock_status']);
        self::assertSame(450.0, $row['price']);
        self::assertSame('AED 450.00', $row['price_formatted']);
        self::assertArrayHasKey('image', $row);
    }

    #[Test]
    public function forwardsFilterParamsToRepository(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor, []);

        $this->get(
            $user,
            '/v3/vendor/products?search=abaya&status=draft&stock_status=out_of_stock&category_id=3&price_min=100&price_max=500',
        );

        self::assertSame('abaya', $this->capturedFilters['search']);
        self::assertSame('draft', $this->capturedFilters['status']);
        self::assertSame('out_of_stock', $this->capturedFilters['stock_status']);
        self::assertSame(3, $this->capturedFilters['category_id']);
        self::assertSame(100.0, $this->capturedFilters['price_min']);
        self::assertSame(500.0, $this->capturedFilters['price_max']);
    }

    #[Test]
    public function emptyFiltersAreNull(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor, []);

        $this->get($user, '/v3/vendor/products');

        self::assertNull($this->capturedFilters['status']);
        self::assertNull($this->capturedFilters['category_id']);
        self::assertNull($this->capturedFilters['price_min']);
    }
}
