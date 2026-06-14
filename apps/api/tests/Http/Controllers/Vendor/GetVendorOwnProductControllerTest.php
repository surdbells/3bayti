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
use Bayti\Api\Http\Controllers\Vendor\Product\GetVendorOwnProductController;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /v3/vendor/products/{id} — vendor single-product detail (any status),
 * owner-scoped. Backs the preview drawer.
 */
#[CoversClass(GetVendorOwnProductController::class)]
final class GetVendorOwnProductControllerTest extends HttpTestCase
{
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

    private function makeProduct(Vendor $vendor, int $id, string $status = Product::STATUS_DRAFT): Product
    {
        $p = new Product($vendor, "prod-{$id}", "Product {$id}");
        $p->setCategory(new Category('abayas', 'Abayas'));
        $p->setStatus($status);
        $p->setPrice('320.00');
        $p->setStockQuantity(3);
        $p->setStockStatus(Product::STOCK_IN);
        $p->setAvailableSizes(['S', 'M', 'L']);
        $p->setAvailableColors(['black', 'red']);
        $rp = new \ReflectionProperty($p, 'id');
        $rp->setAccessible(true);
        $rp->setValue($p, $id);
        return $p;
    }

    private function bindDeps(User $user, Vendor $vendor, ?Product $product): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([(int) $vendor->getId()]);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(true);
        $vendorRepo->method('find')->willReturn($vendor);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findOneByIdForVendor')->willReturn($product);

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
    public function returnsDraftProductDetailForOwner(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor, $this->makeProduct($vendor, 5, Product::STATUS_DRAFT));

        $res = $this->get($user, '/v3/vendor/products/5');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];

        // Detail shape: management fields + rich detail, draft included.
        self::assertSame('draft', $data['status']);
        self::assertSame('Abayas', $data['category']);
        self::assertCount(3, $data['sizes']);
        self::assertSame('S', $data['sizes'][0]['label']);
        self::assertCount(2, $data['colors']);
        self::assertArrayHasKey('images', $data);
        self::assertArrayHasKey('description', $data);
    }

    #[Test]
    public function notFoundWhenNotOwned(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor, null); // findOneByIdForVendor → null

        $res = $this->get($user, '/v3/vendor/products/999');
        self::assertSame(404, $res->getStatusCode());
    }
}
