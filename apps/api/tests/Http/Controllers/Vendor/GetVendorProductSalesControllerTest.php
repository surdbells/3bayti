<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\Product\GetVendorProductSalesController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /v3/vendor/products/{id}/sales, per-product sales, owner-scoped.
 * Connection mocked to feed the aggregate queries.
 */
#[CoversClass(GetVendorProductSalesController::class)]
final class GetVendorProductSalesControllerTest extends HttpTestCase
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

    private function makeProduct(Vendor $vendor, int $id): Product
    {
        $p = new Product($vendor, "prod-{$id}", "Product {$id}");
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

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn([
            'units_sold' => '12', 'revenue' => '5400.00', 'order_count' => '4',
        ]);
        $conn->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [ // over_time
                ['day' => '2026-06-01', 'units' => '5', 'revenue' => '2250.00'],
                ['day' => '2026-06-02', 'units' => '7', 'revenue' => '3150.00'],
            ],
            [ // recent_orders
                ['order_reference' => 'ORD-1001', 'status' => 'delivered', 'created_at' => '2026-06-02T10:00:00+00', 'quantity' => '7', 'line_total' => '3150.00'],
            ],
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $productRepo, $conn): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
            $em->method('getConnection')->willReturn($conn);
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
    public function returnsSalesSummarySeriesAndRecentOrders(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor, $this->makeProduct($vendor, 5));

        $res = $this->get($user, '/v3/vendor/products/5/sales');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];

        self::assertSame('Product 5', $data['product']['name']);
        self::assertSame(12, $data['summary']['units_sold']);
        self::assertSame(5400.0, $data['summary']['revenue']);
        self::assertSame(4, $data['summary']['order_count']);
        self::assertSame(1350.0, $data['summary']['avg_order_value']);
        self::assertCount(2, $data['over_time']);
        self::assertSame(5, $data['over_time'][0]['units']);
        self::assertCount(1, $data['recent_orders']);
        self::assertSame('ORD-1001', $data['recent_orders'][0]['order_reference']);
    }

    #[Test]
    public function notFoundWhenNotOwned(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor, null);

        $res = $this->get($user, '/v3/vendor/products/999/sales');
        self::assertSame(404, $res->getStatusCode());
    }
}
