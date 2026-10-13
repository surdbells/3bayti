<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Controllers\Catalog\ListVendorProductsController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /v3/vendors/{slug}/products page-size behaviour.
 *
 * The shipped mobile store page loads its "All" grid and "Latest" rail with
 * ONE request that carries no limit/offset (and has no infinite scroll), so
 * an absent limit must return the whole store catalogue (bounded at 500).
 * An explicit limit keeps the historical 1-100 clamp.
 *
 * The product repository is faked to slice a real in-memory catalogue by the
 * limit/offset the controller passes, so the response reflects exactly what
 * the database would return for that page.
 */
#[CoversClass(ListVendorProductsController::class)]
final class ListVendorProductsControllerTest extends HttpTestCase
{
    /** @var list<array<string, mixed>> filters each findActivePaginated call received */
    private array $repoCalls = [];

    #[Test]
    public function noLimitReturnsTheWholeStoreCatalogue(): void
    {
        $this->givenStoreWithProducts(30);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendors/funun-abaya/products'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(30, $body['data']);
        self::assertSame('Product 1', $body['data'][0]['name']);
        self::assertSame('Product 30', $body['data'][29]['name']);
        self::assertSame(['total' => 30, 'limit' => 500, 'offset' => 0, 'has_more' => false], $body['meta']);

        self::assertCount(1, $this->repoCalls);
        self::assertSame(42, $this->repoCalls[0]['vendorId']);
        self::assertSame('newest', $this->repoCalls[0]['sort']);
        self::assertSame(500, $this->repoCalls[0]['limit']);
        self::assertSame(0, $this->repoCalls[0]['offset']);
    }

    #[Test]
    public function explicitLimitIsHonouredAndReportsMore(): void
    {
        $this->givenStoreWithProducts(30);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendors/funun-abaya/products?limit=10'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(10, $body['data']);
        self::assertSame(['total' => 30, 'limit' => 10, 'offset' => 0, 'has_more' => true], $body['meta']);
        self::assertSame(10, $this->repoCalls[0]['limit']);
    }

    #[Test]
    public function explicitLimitAboveTheMaxIsCappedAt100(): void
    {
        $this->givenStoreWithProducts(130);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendors/funun-abaya/products?limit=250'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(100, $body['data']);
        self::assertSame(['total' => 130, 'limit' => 100, 'offset' => 0, 'has_more' => true], $body['meta']);
        self::assertSame(100, $this->repoCalls[0]['limit']);
    }

    #[Test]
    public function offsetIsHonouredWithoutALimit(): void
    {
        $this->givenStoreWithProducts(30);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendors/funun-abaya/products?offset=20'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(10, $body['data']);
        self::assertSame('Product 21', $body['data'][0]['name']);
        self::assertSame(['total' => 30, 'limit' => 500, 'offset' => 20, 'has_more' => false], $body['meta']);
        self::assertSame(500, $this->repoCalls[0]['limit']);
        self::assertSame(20, $this->repoCalls[0]['offset']);
    }

    #[Test]
    public function aStoreLargerThanTheCatalogueBoundReportsHasMore(): void
    {
        $this->givenStoreWithProducts(503);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendors/funun-abaya/products'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(500, $body['data']);
        self::assertSame(['total' => 503, 'limit' => 500, 'offset' => 0, 'has_more' => true], $body['meta']);
    }

    /**
     * Present-but-odd limit values keep the historical clamp
     * (max(1, min(100, (int) $limit))): they are NOT treated as absent.
     *
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function oddExplicitLimits(): iterable
    {
        yield 'empty string' => ['', 1];
        yield 'garbage'      => ['abc', 1];
        yield 'zero'         => ['0', 1];
        yield 'negative'     => ['-5', 1];
    }

    #[Test]
    #[DataProvider('oddExplicitLimits')]
    public function oddExplicitLimitsKeepTheHistoricalClamp(string $limit, int $expected): void
    {
        $this->givenStoreWithProducts(30);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/funun-abaya/products?limit=' . rawurlencode($limit)),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount($expected, $body['data']);
        self::assertSame($expected, $body['meta']['limit']);
        self::assertTrue($body['meta']['has_more']);
        self::assertSame($expected, $this->repoCalls[0]['limit']);
    }

    #[Test]
    public function unknownStoreIs404(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findBySlug')->willReturn(null);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::never())->method('findActivePaginated');

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendors/nope/products'));

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * A store that may not sell is treated as nonexistent: 404, and the
     * product query (which now returns up to 500 rows with no limit) never
     * runs. Each case isolates ONE failing gate.
     *
     * @return iterable<string, array{0: \Closure(Vendor): void}>
     */
    public static function storesThatMayNotSell(): iterable
    {
        yield 'pending (active, never approved)' => [static function (Vendor $v): void {
        }];
        yield 'approved but inactive' => [static function (Vendor $v): void {
            $v->approve();
            $v->setActive(false);
        }];
        yield 'suspended (active)' => [static function (Vendor $v): void {
            $v->approve();
            $v->suspend();
        }];
    }

    /**
     * @param \Closure(Vendor): void $arrange
     */
    #[Test]
    #[DataProvider('storesThatMayNotSell')]
    public function storeThatMayNotSellIs404(\Closure $arrange): void
    {
        $vendor = new Vendor('funun-abaya', 'Funun Abaya', 'funun@example.test');
        $arrange($vendor);
        self::assertFalse($vendor->isActive() && $vendor->isApproved());

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findBySlug')->with('funun-abaya')->willReturn($vendor);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::never())->method('findActivePaginated');

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendors/funun-abaya/products'));

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Install an approved, active store (slug funun-abaya, internal id 42)
     * with $count active products ("Product 1" is the newest) and a fake
     * repository that pages through them by the controller's limit/offset.
     */
    private function givenStoreWithProducts(int $count): void
    {
        $vendor = new Vendor('funun-abaya', 'Funun Abaya', 'funun@example.test');
        $vendor->approve();
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setValue($vendor, 42);

        $catalogue = [];
        for ($i = 1; $i <= $count; $i++) {
            $product = new Product($vendor, 'product-' . $i, 'Product ' . $i);
            $product->setStatus(Product::STATUS_ACTIVE);
            $catalogue[] = $product;
        }

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findBySlug')->with('funun-abaya')->willReturn($vendor);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturnCallback(
            function (array $filters) use ($catalogue): array {
                $this->repoCalls[] = $filters;
                return [
                    'items' => array_slice($catalogue, (int) $filters['offset'], (int) $filters['limit']),
                    'total' => count($catalogue),
                ];
            },
        );

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }
}
