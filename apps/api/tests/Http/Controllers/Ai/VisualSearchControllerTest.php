<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Ai;

use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Ai\Vision\VisionEmbedderInterface;
use Bayti\Api\Ai\Vision\VisionEmbedding;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Controllers\Ai\VisualSearchController;
use Bayti\Api\Tests\Ai\Vision\FakeVisionEmbedder;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(VisualSearchController::class)]
final class VisualSearchControllerTest extends HttpTestCase
{
    // A 1x1 transparent PNG.
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function bindVision(bool $enabled, VisionEmbedding $embedding): void
    {
        $this->bind(VisionEmbedderInterface::class, new FakeVisionEmbedder($enabled, $embedding));
    }

    /**
     * @param array<int, list<float>> $embeddings
     * @param list<Product> $products
     */
    private function bindCatalogue(array $embeddings, array $products): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(false);
        $conn->method('fetchAllAssociative')->willReturnCallback(
            static function () use ($embeddings): array {
                $rows = [];
                foreach ($embeddings as $id => $vec) {
                    $rows[] = ['product_id' => $id, 'embedding' => (string) json_encode($vec)];
                }
                return $rows;
            },
        );
        $this->bind(ProductAiAttributesStore::class, new ProductAiAttributesStore($conn));

        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findBy')->willReturn($products);
        $em = $this->stubEm(function ($em) use ($repo): void {
            $em->method('getRepository')->willReturnCallback(
                static fn (string $class) => $class === Product::class ? $repo : null,
            );
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function makeProduct(int $id, string $name): Product
    {
        $vendor = new Vendor("v-{$id}", "Store {$id}", "v{$id}@example.test");
        $vendor->approve();
        $p = new Product(vendor: $vendor, slug: "p-{$id}", name: $name);
        $p->setStatus('active');
        $p->setPrice('300.00');
        $ref = new \ReflectionProperty($p, 'id');
        $ref->setAccessible(true);
        $ref->setValue($p, $id);
        return $p;
    }

    #[Test]
    public function returnsEmptyWhenVisionIsDisabled(): void
    {
        $this->bindVision(false, VisionEmbedding::empty());

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/visual-search', [
            'image' => 'data:image/png;base64,' . self::PNG_B64,
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res);
        $data = $data['data'] ?? $data;
        self::assertSame([], $data['products']);
        self::assertNull($data['description']);
    }

    #[Test]
    public function returnsVisuallySimilarProductsWhenEnabled(): void
    {
        $this->bindVision(true, new VisionEmbedding([1.0, 0.0], 'black embellished abaya'));
        $this->bindCatalogue(
            [1 => [0.99, 0.01], 2 => [0.2, 0.9]],
            [$this->makeProduct(1, 'Near'), $this->makeProduct(2, 'Far')],
        );

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/visual-search', [
            'image' => 'data:image/png;base64,' . self::PNG_B64,
            'locale' => 'en',
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res);
        $data = $data['data'] ?? $data;
        self::assertSame('black embellished abaya', $data['description']);
        self::assertCount(2, $data['products']);
        self::assertSame(1, $data['products'][0]['id']);
        self::assertArrayHasKey('interaction_id', $data);
    }

    #[Test]
    public function rejectsAMissingImage(): void
    {
        $this->bindVision(false, VisionEmbedding::empty());
        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/visual-search', []));
        self::assertSame(400, $res->getStatusCode(), (string) $res->getBody());
    }

    #[Test]
    public function rejectsAnUnsupportedImageType(): void
    {
        $this->bindVision(false, VisionEmbedding::empty());
        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/visual-search', [
            'image' => 'data:image/gif;base64,' . self::PNG_B64,
        ]));
        self::assertSame(400, $res->getStatusCode(), (string) $res->getBody());
    }
}
