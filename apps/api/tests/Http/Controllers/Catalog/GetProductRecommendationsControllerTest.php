<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRecommendation;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\RecommendationsService;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Controllers\Catalog\GetProductRecommendationsController;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Bayti\Api\Http\Serializers\RecommendationsSerializer;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP-level tests for GET /v3/products/{slug}/recommendations
 * (M3.2.X.12-G).
 *
 * No auth required, public endpoint.
 */
#[CoversClass(GetProductRecommendationsController::class)]
#[CoversClass(RecommendationsSerializer::class)]
final class GetProductRecommendationsControllerTest extends HttpTestCase
{
    private int $capturedLimit = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capturedLimit = 0;
    }

    #[Test]
    public function returnsRecommendationsForExistingProduct(): void
    {
        $vendor = $this->makeVendor();
        $source = $this->makeProduct(100, $vendor, 'source-product');
        $target1 = $this->makeProduct(200, $vendor, 'recommendation-one');
        $target2 = $this->makeProduct(300, $vendor, 'recommendation-two');

        $this->bindDeps($source, recommendations: [
            ['product' => $target1, 'score' => '23.0000', 'source' => 'copurchase'],
            ['product' => $target2, 'score' => '15.0000', 'source' => 'copurchase'],
        ]);

        $response = $this->makeGet('/v3/products/source-product/recommendations');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertCount(2, $body['data']);
        self::assertSame(200, $body['data'][0]['product']['id']);
        self::assertSame('23.0000', $body['data'][0]['score']);
        self::assertSame('copurchase', $body['data'][0]['source']);
        self::assertSame(2, $body['meta']['total']);
    }

    #[Test]
    public function nonExistentSlugReturns404(): void
    {
        $this->bindDeps(productBySlug: null, recommendations: []);

        $response = $this->makeGet('/v3/products/not-real/recommendations');
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function customLimitForwardedToService(): void
    {
        $vendor = $this->makeVendor();
        $source = $this->makeProduct(100, $vendor, 'source-product');
        $this->bindDeps($source, recommendations: []);

        $this->makeGet('/v3/products/source-product/recommendations?limit=15');
        self::assertSame(15, $this->capturedLimit);
    }

    #[Test]
    public function nonNumericLimitFallsBackToDefault(): void
    {
        $vendor = $this->makeVendor();
        $source = $this->makeProduct(100, $vendor, 'source-product');
        $this->bindDeps($source, recommendations: []);

        $this->makeGet('/v3/products/source-product/recommendations?limit=banana');
        self::assertSame(RecommendationsService::DEFAULT_LIMIT, $this->capturedLimit);
    }

    #[Test]
    public function emptyRecommendationsReturnEmptyArray(): void
    {
        $vendor = $this->makeVendor();
        $source = $this->makeProduct(100, $vendor, 'source-product');
        $this->bindDeps($source, recommendations: []);

        $response = $this->makeGet('/v3/products/source-product/recommendations');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param list<array{product: Product, score: string, source: string}> $recommendations
     */
    private function bindDeps(?Product $productBySlug, array $recommendations): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findBySlug')->willReturn($productBySlug);

        $em = $this->stubEm(function ($em) use ($productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $service = $this->createMock(RecommendationsService::class);
        $service->method('getRecommendationsForProduct')->willReturnCallback(
            function (int $_pid, int $limit) use ($recommendations): array {
                $this->capturedLimit = $limit;
                return $recommendations;
            },
        );
        $this->bind(RecommendationsService::class, $service);

        // The serializer uses real ProductSerializer for listShape.
        // Bind the real serializer + recommendations serializer.
        $this->bind(
            RecommendationsSerializer::class,
            new RecommendationsSerializer(new ProductSerializer()),
        );
    }

    private function makeGet(string $uri): ResponseInterface
    {
        return $this->handle($this->jsonRequest('GET', $uri, []));
    }

    private function makeVendor(): Vendor
    {
        $vendor = new Vendor('test-vendor', 'Test Vendor', 'tv@example.com');
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, 5);
        return $vendor;
    }

    private function makeProduct(int $id, Vendor $vendor, string $slug): Product
    {
        $product = new Product($vendor, $slug, "Product {$id}");
        $idRef = new \ReflectionProperty(Product::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($product, $id);
        $product->setPrice('100.00');
        return $product;
    }
}
