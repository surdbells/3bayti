<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\Concierge\Style;

use Bayti\Api\Ai\AiProviderInterface;
use Bayti\Api\Ai\Concierge\ConciergeRanker;
use Bayti\Api\Ai\Concierge\IntentParser;
use Bayti\Api\Ai\Concierge\ProductRetrievalService;
use Bayti\Api\Ai\Concierge\Style\RestyleBrief;
use Bayti\Api\Ai\Concierge\Style\StyleRestyleService;
use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Disabled AI stub → deterministic passthrough, no external calls. */
final class RestyleFakeAiProvider implements AiProviderInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function embedModel(): ?string
    {
        return null;
    }

    public function completeJson(string $system, string $user, array $schema, string $schemaName = 'result'): array
    {
        return [];
    }

    public function embed(array $texts): array
    {
        return array_map(static fn (): array => [], $texts);
    }
}

#[CoversClass(StyleRestyleService::class)]
#[CoversClass(RestyleBrief::class)]
final class StyleRestylePipelineTest extends TestCase
{
    #[Test]
    public function rebuildsOnlyFromOrderableInStockProducts(): void
    {
        $vendor = $this->makeVendor(9);
        $cat = $this->makeCategory(20, 'bags');
        $p1 = $this->makeProduct($vendor, 1, 'Silk Scarf', $cat);
        $draft = $this->makeProduct($vendor, 2, 'Draft Bag', $cat, orderable: false);
        $oos = $this->makeProduct($vendor, 3, 'Sold Out Clutch', $cat, inStock: false);
        $p4 = $this->makeProduct($vendor, 4, 'Leather Bag', $cat);

        $seed = [$this->makeProduct($vendor, 100, 'Black Abaya', $this->makeCategory(10, 'abayas'))];

        $service = $this->service([$p1, $draft, $oos, $p4]);
        $result = $service->restyle($seed, 'make it more elegant for a wedding', 'en');

        self::assertSame([1, 4], $result->productIds());
        self::assertFalse($result->concierge->intent->isGift);
        self::assertNotSame('', $result->rationale);
    }

    #[Test]
    public function briefMergesInstructionSignalsOverSeed(): void
    {
        $vendor = $this->makeVendor(9);
        $seed = [$this->makeProduct($vendor, 100, 'Beige Abaya', $this->makeCategory(10, 'abayas'), colours: ['beige'])];

        $instructionIntent = \Bayti\Api\Ai\Concierge\ConciergeIntent::fromArray([
            'occasions' => ['wedding'],
            'colours' => ['black'],
            'styles' => ['elegant'],
            'keywords' => ['elegant', 'wedding'],
            'confidence' => 0.8,
        ]);
        $brief = new RestyleBrief($seed, 'style this for an elegant wedding in black', $instructionIntent);
        $intent = $brief->toIntent();

        self::assertFalse($intent->isGift);
        self::assertSame(['wedding'], $intent->occasions);
        // Explicit instruction colour wins over the seed's beige.
        self::assertSame(['black'], $intent->colours);
        self::assertContains('elegant', $intent->keywords);
        // Seed wardrobe token anchors the rebuild.
        self::assertContains('abayas', $intent->keywords);
        self::assertStringContainsString('Beige Abaya', $brief->toRankerQuery());
    }

    #[Test]
    public function emptyCatalogueReturnsNoProducts(): void
    {
        $vendor = $this->makeVendor(9);
        $seed = [$this->makeProduct($vendor, 100, 'Abaya', $this->makeCategory(10, 'abayas'))];

        $service = $this->service([]);
        $result = $service->restyle($seed, 'make it more traditional', 'ar');

        self::assertSame([], $result->productIds());
        self::assertNotSame('', $result->rationale); // deterministic ar rationale
    }

    // ===== fixtures =====

    /** @param list<Product> $items */
    private function service(array $items): StyleRestyleService
    {
        $ai = new RestyleFakeAiProvider();
        $retrieval = new ProductRetrievalService($this->emReturning($items), $ai, $this->noEmbedStore());
        return new StyleRestyleService(new IntentParser($ai), $retrieval, new ConciergeRanker($ai));
    }

    private function makeVendor(int $id): Vendor
    {
        $v = new Vendor("vendor-{$id}", "Vendor {$id}", "v{$id}@example.test");
        $v->approve();
        $this->setId($v, $id);
        return $v;
    }

    private function makeCategory(int $id, string $slug): Category
    {
        $c = new Category($slug, ucfirst($slug));
        $this->setId($c, $id);
        return $c;
    }

    /** @param list<string> $colours */
    private function makeProduct(Vendor $vendor, int $id, string $name, Category $category, bool $orderable = true, bool $inStock = true, array $colours = []): Product
    {
        $p = new Product(vendor: $vendor, slug: "p-{$id}", name: $name);
        $p->setCategory($category);
        if ($orderable) {
            $p->setStatus('active');
        }
        if (!$inStock) {
            $p->setStockStatus(Product::STOCK_OUT);
        }
        if ($colours !== []) {
            $p->setAvailableColors($colours);
        }
        $p->setPrice('300.00');
        $this->setId($p, $id);
        return $p;
    }

    /** @param list<Product> $items */
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

    private function noEmbedStore(): ProductAiAttributesStore
    {
        return new ProductAiAttributesStore($this->createMock(\Doctrine\DBAL\Connection::class));
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
