<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\Concierge\Outfit;

use Bayti\Api\Ai\AiProviderInterface;
use Bayti\Api\Ai\Concierge\ConciergeRanker;
use Bayti\Api\Ai\Concierge\Gift\GiftCardSuggestion;
use Bayti\Api\Ai\Concierge\Outfit\OutfitBrief;
use Bayti\Api\Ai\Concierge\Outfit\OutfitComposerService;
use Bayti\Api\Ai\Concierge\Outfit\OutfitPiece;
use Bayti\Api\Ai\Concierge\ProductRetrievalService;
use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Disabled AI stub → deterministic passthrough ranking, no external calls. */
final class OutfitFakeAiProvider implements AiProviderInterface
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

#[CoversClass(OutfitComposerService::class)]
#[CoversClass(OutfitBrief::class)]
final class OutfitComposerServiceTest extends TestCase
{
    private Vendor $vendor;
    private Category $abayas;
    private Category $bags;
    private Category $scarves;

    protected function setUp(): void
    {
        $this->vendor = $this->makeVendor(9);
        $this->abayas = $this->makeCategory(10, 'abayas');
        $this->bags = $this->makeCategory(20, 'bags');
        $this->scarves = $this->makeCategory(30, 'scarves');
    }

    #[Test]
    public function composesHeroPlusOnePiecePerCategoryAndDropsUnsellable(): void
    {
        $pool = [
            $this->makeProduct(1, 'Rose Abaya', $this->abayas, '300.00'),                 // HERO (first)
            $this->makeProduct(2, 'Gold Clutch', $this->bags, '120.00'),                  // complement
            $this->makeProduct(3, 'Silk Scarf', $this->scarves, '90.00'),                 // complement
            $this->makeProduct(4, 'Black Abaya', $this->abayas, '280.00'),                // same category as hero → excluded
            $this->makeProduct(5, 'Draft Bag', $this->bags, '150.00', orderable: false),  // unsellable → excluded
            $this->makeProduct(6, 'Sold Out Scarf', $this->scarves, '80.00', inStock: false), // out of stock → excluded
        ];

        $result = $this->service($pool)->compose(
            OutfitBrief::fromArray(['occasion' => 'wedding', 'styles' => ['elegant'], 'colours' => ['rose']]),
        );

        self::assertSame([1, 2, 3], $result->productIds());
        self::assertSame(
            [OutfitPiece::ROLE_HERO, OutfitPiece::ROLE_COMPLEMENT, OutfitPiece::ROLE_COMPLEMENT],
            array_map(static fn (OutfitPiece $p): string => $p->role, $result->pieces),
        );
        self::assertSame(['abayas', 'bags', 'scarves'], array_map(static fn (OutfitPiece $p): string => $p->categorySlug, $result->pieces));
        self::assertNull($result->suggestion);
        // Every piece is orderable + in stock.
        foreach ($result->pieces as $piece) {
            self::assertTrue($piece->product->isOrderable() && $piece->product->isInStock());
        }
    }

    #[Test]
    public function keepsTheWholeLookWithinBudget(): void
    {
        $pool = [
            $this->makeProduct(1, 'Statement Abaya', $this->abayas, '400.00'),
            $this->makeProduct(2, 'Small Clutch', $this->bags, '80.00'),     // 400+80 = 480 <= 525 → in
            $this->makeProduct(3, 'Ornate Scarf', $this->scarves, '200.00'), // 480+200 = 680 > 525 → dropped
        ];

        $result = $this->service($pool)->compose(
            OutfitBrief::fromArray(['occasion' => 'wedding', 'budget_max' => 500]),
        );

        self::assertSame([1, 2], $result->productIds());
    }

    #[Test]
    public function emptyCatalogueReturnsAGiftCardFallback(): void
    {
        $result = $this->service([])->compose(
            OutfitBrief::fromArray(['occasion' => 'eid', 'budget_max' => 300]),
        );

        self::assertSame([], $result->productIds());
        self::assertNotNull($result->suggestion);
        self::assertSame(GiftCardSuggestion::REASON_FEW_MATCHES, $result->suggestion->reason);
        // Largest gift-card preset not exceeding the 300 budget (presets are 100/200/500…).
        self::assertSame('200.00', $result->suggestion->suggestedDenomination);
    }

    #[Test]
    public function rationaleReflectsTheBriefInEnglishAndArabic(): void
    {
        $pool = [
            $this->makeProduct(1, 'Rose Abaya', $this->abayas, '300.00'),
            $this->makeProduct(2, 'Gold Clutch', $this->bags, '120.00'),
        ];

        $en = $this->service($pool)->compose(
            OutfitBrief::fromArray(['occasion' => 'wedding', 'styles' => ['elegant'], 'colours' => ['rose']]),
            'en',
        );
        self::assertStringContainsString('elegant', $en->rationale);
        self::assertStringContainsString('wedding', $en->rationale);
        self::assertStringContainsString('rose', $en->rationale);
        self::assertStringContainsString('Rose Abaya', $en->rationale);

        $ar = $this->service($pool)->compose(
            OutfitBrief::fromArray(['occasion' => 'wedding', 'styles' => ['elegant'], 'colours' => ['rose']]),
            'ar',
        );
        self::assertMatchesRegularExpression('/\p{Arabic}/u', $ar->rationale);
        self::assertStringContainsString('Rose Abaya', $ar->rationale);
    }

    #[Test]
    public function briefBuildsANonGiftIntentWithComposedKeywords(): void
    {
        $brief = OutfitBrief::fromArray([
            'occasion' => 'eid',
            'colours' => ['black'],
            'styles' => ['elegant'],
            'product_type' => 'abaya',
            'budget_max' => 800,
        ]);
        $intent = $brief->toIntent();

        self::assertFalse($intent->isGift);
        self::assertSame(['eid'], $intent->occasions);
        self::assertSame(['black'], $intent->colours);
        self::assertSame(800.0, $intent->budgetMax);
        self::assertContains('elegant', $intent->keywords);
        self::assertContains('abaya', $intent->keywords);
        self::assertTrue($brief->hasEnoughSignal());
        self::assertFalse(OutfitBrief::fromArray([])->hasEnoughSignal());
    }

    // ===== fixtures =====

    /** @param list<Product> $items */
    private function service(array $items): OutfitComposerService
    {
        $ai = new OutfitFakeAiProvider();
        $retrieval = new ProductRetrievalService($this->emReturning($items), $ai, $this->noEmbedStore());
        return new OutfitComposerService($retrieval, new ConciergeRanker($ai));
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

    private function makeProduct(int $id, string $name, Category $category, string $price, bool $orderable = true, bool $inStock = true): Product
    {
        $p = new Product(vendor: $this->vendor, slug: "p-{$id}", name: $name);
        $p->setCategory($category);
        if ($orderable) {
            $p->setStatus('active');
        }
        if (!$inStock) {
            $p->setStockStatus(Product::STOCK_OUT);
        }
        $p->setPrice($price);
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
