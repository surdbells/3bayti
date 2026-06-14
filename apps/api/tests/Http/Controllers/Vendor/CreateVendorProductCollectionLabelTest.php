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
use Bayti\Api\Http\Controllers\Vendor\Product\CreateVendorProductController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * Bug 3 — the product create flow must persist the chosen collection_id and
 * label_id (previously the picker selections were dropped entirely).
 */
#[CoversClass(CreateVendorProductController::class)]
final class CreateVendorProductCollectionLabelTest extends HttpTestCase
{
    private ?Product $saved = null;

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

    private function bindDeps(User $user, Vendor $vendor): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByOwnerUser')->willReturn([$vendor]);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(true);

        $catRepo = $this->createMock(\Bayti\Api\Domain\Catalog\CategoryRepository::class);
        $catRepo->method('find')->willReturn(null);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('save')->willReturnCallback(function (Product $p): void {
            $this->saved = $p;
            $rp = new \ReflectionProperty($p, 'id');
            $rp->setAccessible(true);
            $rp->setValue($p, 4242);
        });

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $catRepo, $productRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [Category::class, $catRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    #[Test]
    public function persistsCollectionAndLabel(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $res = $this->handle($this->jsonRequest('POST', '/v3/vendor/products', [
            'name' => 'Silk Abaya',
            'price' => 450,
            'stock_quantity' => 5,
            'collection_id' => 7,
            'label_id' => 3,
            'status' => 'active',
        ], ['Authorization' => 'Bearer ' . $pair->accessToken]));

        self::assertContains($res->getStatusCode(), [200, 201], (string) $res->getBody());
        self::assertNotNull($this->saved);
        self::assertSame(7, $this->saved->getCollectionId());
        self::assertSame(3, $this->saved->getLabelId());
        self::assertSame('active', $this->saved->getStatus());
    }
}
