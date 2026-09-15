<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\Concierge;

use Bayti\Api\Ai\AiProviderInterface;
use Bayti\Api\Ai\Concierge\ConciergeRanker;
use Bayti\Api\Ai\Concierge\ConciergeService;
use Bayti\Api\Ai\Concierge\IntentParser;
use Bayti\Api\Ai\Concierge\ProductRetrievalService;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A configurable in-memory AI provider: each completeJson() call returns the
 * next queued object, so a test can script "intent then ranking".
 */
final class FakeAiProvider implements AiProviderInterface
{
    public bool $enabled = true;
    /** @var list<array<string, mixed>> */
    public array $queue = [];
    /** @var list<float> vector returned for every embed() input */
    public array $embedVector = [];

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function embedModel(): ?string
    {
        return 'fake-embed';
    }

    public function completeJson(string $system, string $user, array $schema, string $schemaName = 'result'): array
    {
        return array_shift($this->queue) ?? [];
    }

    public function embed(array $texts): array
    {
        return array_map(fn (): array => $this->embedVector, $texts);
    }
}

#[CoversClass(ConciergeService::class)]
#[CoversClass(IntentParser::class)]
#[CoversClass(ProductRetrievalService::class)]
#[CoversClass(ConciergeRanker::class)]
final class ConciergePipelineTest extends TestCase
{
    // ===== IntentParser =====

    #[Test]
    public function intentParserFallsBackToKeywordsWhenAiIsOff(): void
    {
        $ai = new FakeAiProvider();
        $ai->enabled = false;

        $intent = (new IntentParser($ai))->parse('elegant black abaya', 'en');

        self::assertSame(['elegant black abaya'], $intent->keywords);
        self::assertSame(0.0, $intent->confidence);
        self::assertNull($intent->productType);
    }

    #[Test]
    public function arabicAndEnglishQueriesFunnelIntoTheSameCanonicalIntent(): void
    {
        // The model returns canonical ENGLISH tags regardless of input language.
        $canonical = ['product_type' => 'abaya', 'colours' => ['black'], 'budget_max' => 800,
            'keywords' => ['abaya', 'wedding'], 'is_gift' => false, 'confidence' => 0.9];

        $en = new FakeAiProvider();
        $en->queue = [$canonical];
        $ar = new FakeAiProvider();
        $ar->queue = [$canonical];

        $iEn = (new IntentParser($en))->parse('an elegant black abaya for a wedding under 800', 'en');
        $iAr = (new IntentParser($ar))->parse('أريد عباية سوداء أنيقة للعيد', 'ar');

        self::assertSame('abaya', $iEn->productType);
        self::assertSame('abaya', $iAr->productType);
        self::assertSame(['black'], $iAr->colours);
        self::assertSame(800.0, $iEn->budgetMax);
    }

    // ===== ConciergeRanker =====

    #[Test]
    public function rankerKeepsOnlyShortlistIdsAndDropsHallucinations(): void
    {
        $vendor = $this->makeVendor(9);
        $p1 = $this->makeProduct($vendor, 1, 'Abaya One');
        $p2 = $this->makeProduct($vendor, 2, 'Abaya Two');

        $ai = new FakeAiProvider();
        // The model returns id 999 (never retrieved) — it must be dropped.
        $ai->queue = [['ranking' => [
            ['id' => 2, 'reason' => 'Matches the black wedding brief'],
            ['id' => 999, 'reason' => 'hallucinated'],
            ['id' => 1, 'reason' => 'A refined alternative'],
        ]]];

        $items = (new ConciergeRanker($ai))->rank(
            \Bayti\Api\Ai\Concierge\ConciergeIntent::keywordFallback('abaya'),
            [$p1, $p2],
            'black wedding abaya',
        );

        self::assertCount(2, $items);
        self::assertSame(2, $items[0]->product->getId());
        self::assertSame('Matches the black wedding brief', $items[0]->reason);
        self::assertSame(1, $items[1]->product->getId());
    }

    #[Test]
    public function rankerPassesTheShortlistThroughWhenAiIsOff(): void
    {
        $vendor = $this->makeVendor(9);
        $shortlist = [$this->makeProduct($vendor, 1, 'A'), $this->makeProduct($vendor, 2, 'B')];

        $ai = new FakeAiProvider();
        $ai->enabled = false;

        $items = (new ConciergeRanker($ai))->rank(
            \Bayti\Api\Ai\Concierge\ConciergeIntent::keywordFallback('a'),
            $shortlist,
            'a',
        );

        self::assertSame([1, 2], array_map(static fn ($i) => $i->product->getId(), $items));
        self::assertSame('', $items[0]->reason);
    }

    // ===== semantic retrieval (PHP-cosine fallback) =====

    #[Test]
    public function retrievalReordersTheShortlistBySemanticSimilarity(): void
    {
        $vendor = $this->makeVendor(9);
        $p1 = $this->makeProduct($vendor, 1, 'A');
        $p2 = $this->makeProduct($vendor, 2, 'B');
        $p3 = $this->makeProduct($vendor, 3, 'C');

        $ai = new FakeAiProvider();
        $ai->embedVector = [1.0, 0.0]; // query vector

        // Store over a mocked connection: embeddings exist, pgvector does not,
        // and product 2 is closest to the query, then 1, then 3.
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $conn->method('fetchOne')->willReturnCallback(
            static fn (string $sql) => str_contains($sql, 'information_schema') ? false : 1,
        );
        $conn->method('fetchAllAssociative')->willReturn([
            ['product_id' => 1, 'embedding' => (string) json_encode([0.5, 0.5])],
            ['product_id' => 2, 'embedding' => (string) json_encode([0.99, 0.01])],
            ['product_id' => 3, 'embedding' => (string) json_encode([0.1, 0.9])],
        ]);
        $store = new \Bayti\Api\Ai\Enrichment\ProductAiAttributesStore($conn);

        $service = new ProductRetrievalService($this->emReturning([$p1, $p2, $p3]), $ai, $store);
        $out = $service->retrieve(\Bayti\Api\Ai\Concierge\ConciergeIntent::keywordFallback('abaya'), 12);

        self::assertSame([2, 1, 3], array_map(static fn ($p) => $p->getId(), $out));
    }

    // ===== ConciergeService end-to-end =====

    #[Test]
    public function serviceReturnsOnlyOrderableInStockProductsRanked(): void
    {
        $vendor = $this->makeVendor(9);
        $p1 = $this->makeProduct($vendor, 1, 'Black Abaya');           // orderable + in stock
        $p2Draft = $this->makeProduct($vendor, 2, 'Draft Abaya', orderable: false); // not active
        $p3Oos = $this->makeProduct($vendor, 3, 'Sold Out Abaya', inStock: false);  // out of stock
        $p4 = $this->makeProduct($vendor, 4, 'Beige Abaya');           // orderable + in stock

        $ai = new FakeAiProvider();
        $ai->queue = [
            // 1) intent
            ['product_type' => 'abaya', 'colours' => ['black'], 'keywords' => ['abaya'], 'is_gift' => false, 'confidence' => 0.8],
            // 2) ranking (only real, retrieved ids)
            ['ranking' => [['id' => 4, 'reason' => 'Beige suits the brief'], ['id' => 1, 'reason' => 'Classic black']]],
        ];

        $service = new ConciergeService(
            new IntentParser($ai),
            new ProductRetrievalService($this->emReturning([$p1, $p2Draft, $p3Oos, $p4]), $ai, $this->noEmbedStore()),
            new ConciergeRanker($ai),
        );

        $result = $service->ask('an elegant black abaya for a wedding', 'en');

        // The non-orderable draft (2) and out-of-stock (3) are never surfaced.
        self::assertSame([4, 1], $result->productIds());
        self::assertSame('abaya', $result->intent->productType);
        self::assertSame('Beige suits the brief', $result->items[0]->reason);
    }

    // ===== fixtures =====

    private function makeVendor(int $id): Vendor
    {
        $v = new Vendor("vendor-{$id}", "Vendor {$id}", "v{$id}@example.test");
        $v->approve();
        $this->setId($v, $id);
        return $v;
    }

    private function makeProduct(Vendor $vendor, int $id, string $name, bool $orderable = true, bool $inStock = true): Product
    {
        $p = new Product(vendor: $vendor, slug: "p-{$id}", name: $name);
        if ($orderable) {
            $p->setStatus('active'); // isActive => orderable (vendor already sells)
        }
        if (!$inStock) {
            $p->setStockStatus(Product::STOCK_OUT);
        }
        $p->setPrice('100.00');
        $this->setId($p, $id);
        return $p;
    }

    /**
     * EntityManager stub whose ProductRepository::findActivePaginated always
     * returns the given products (the retrieval service dedupes across passes).
     *
     * @param list<Product> $items
     */
    private function emReturning(array $items): EntityManagerInterface
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturn(['items' => $items, 'total' => count($items)]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class) => $class === Product::class ? $productRepo : null,
        );
        return $em;
    }

    /** A store over a mocked connection: hasAnyEmbedding() is false, so the semantic pass is skipped. */
    private function noEmbedStore(): \Bayti\Api\Ai\Enrichment\ProductAiAttributesStore
    {
        return new \Bayti\Api\Ai\Enrichment\ProductAiAttributesStore(
            $this->createMock(\Doctrine\DBAL\Connection::class),
        );
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
