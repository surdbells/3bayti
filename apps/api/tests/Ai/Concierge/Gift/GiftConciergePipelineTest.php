<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\Concierge\Gift;

use Bayti\Api\Ai\AiProviderInterface;
use Bayti\Api\Ai\Concierge\ConciergeRanker;
use Bayti\Api\Ai\Concierge\Gift\GiftBrief;
use Bayti\Api\Ai\Concierge\Gift\GiftCardSuggestion;
use Bayti\Api\Ai\Concierge\Gift\GiftConciergeService;
use Bayti\Api\Ai\Concierge\ProductRetrievalService;
use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Disabled AI stub → deterministic passthrough ranking, no external calls. */
final class GiftFakeAiProvider implements AiProviderInterface
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

#[CoversClass(GiftConciergeService::class)]
#[CoversClass(GiftBrief::class)]
#[CoversClass(GiftCardSuggestion::class)]
final class GiftConciergePipelineTest extends TestCase
{
    #[Test]
    public function returnsOnlyOrderableInStockProductsAsGiftIdeas(): void
    {
        $vendor = $this->makeVendor(9);
        $p1 = $this->makeProduct($vendor, 1, 'Black Abaya');
        $draft = $this->makeProduct($vendor, 2, 'Draft Abaya', orderable: false);
        $oos = $this->makeProduct($vendor, 3, 'Sold Out Abaya', inStock: false);
        $p4 = $this->makeProduct($vendor, 4, 'Beige Abaya');

        $service = $this->service([$p1, $draft, $oos, $p4]);
        $result = $service->askGift($this->brief(['occasion' => 'eid', 'product_type' => 'abaya', 'size' => 'M']));

        // The non-orderable draft (2) and out-of-stock (3) are never surfaced.
        self::assertSame([1, 4], $result->productIds());
        self::assertTrue($result->concierge->intent->isGift);
    }

    #[Test]
    public function suggestsAGiftCardForSizelessApparelWithEnoughMatches(): void
    {
        $vendor = $this->makeVendor(9);
        $products = [
            $this->makeProduct($vendor, 1, 'Abaya A'),
            $this->makeProduct($vendor, 2, 'Abaya B'),
            $this->makeProduct($vendor, 3, 'Abaya C'),
        ];

        $service = $this->service($products);
        // apparel product type, NO size → size is the classic gifting risk.
        $result = $service->askGift($this->brief(['product_type' => 'abaya', 'budget_max' => 600]));

        self::assertNotNull($result->suggestion);
        self::assertSame(GiftCardSuggestion::REASON_SIZE_UNKNOWN, $result->suggestion->reason);
        // Largest preset not exceeding the 600 budget.
        self::assertSame('500.00', $result->suggestion->suggestedDenomination);
    }

    #[Test]
    public function suggestsAGiftCardWhenTooFewMatches(): void
    {
        $vendor = $this->makeVendor(9);
        $service = $this->service([$this->makeProduct($vendor, 1, 'Only One')]);

        $result = $service->askGift($this->brief(['occasion' => 'birthday', 'budget_max' => 250, 'size' => 'M']));

        self::assertCount(1, $result->concierge->items);
        self::assertNotNull($result->suggestion);
        self::assertSame(GiftCardSuggestion::REASON_FEW_MATCHES, $result->suggestion->reason);
        self::assertSame('200.00', $result->suggestion->suggestedDenomination);
    }

    #[Test]
    public function noGiftCardWhenSizedApparelIsWellMatched(): void
    {
        $vendor = $this->makeVendor(9);
        $products = [
            $this->makeProduct($vendor, 1, 'Abaya A'),
            $this->makeProduct($vendor, 2, 'Abaya B'),
            $this->makeProduct($vendor, 3, 'Abaya C'),
        ];

        $service = $this->service($products);
        $result = $service->askGift($this->brief(['product_type' => 'abaya', 'size' => 'M', 'budget_max' => 600]));

        self::assertNull($result->suggestion);
    }

    #[Test]
    public function suggestedDenominationIsNullWhenBudgetIsBelowTheMinimumCard(): void
    {
        $vendor = $this->makeVendor(9);
        $service = $this->service([$this->makeProduct($vendor, 1, 'Only One')]);

        // Thin match → few_matches suggestion, but a 50 budget is below the 100 min card.
        $result = $service->askGift($this->brief(['occasion' => 'birthday', 'budget_max' => 50]));

        self::assertNotNull($result->suggestion);
        self::assertSame(GiftCardSuggestion::REASON_FEW_MATCHES, $result->suggestion->reason);
        self::assertNull($result->suggestion->suggestedDenomination);
    }

    #[Test]
    public function emptyCatalogueStillReturnsAGiftCardFallback(): void
    {
        $service = $this->service([]);
        $result = $service->askGift($this->brief(['occasion' => 'eid', 'budget_max' => 300]));

        self::assertSame([], $result->productIds());
        self::assertNotNull($result->suggestion);
        self::assertSame(GiftCardSuggestion::REASON_FEW_MATCHES, $result->suggestion->reason);
    }

    #[Test]
    public function briefBuildsAGiftIntentWithComposedKeywordsAndNoRecipientLeak(): void
    {
        $brief = $this->brief([
            'recipient' => 'sister',
            'occasion' => 'eid',
            'colours' => ['black'],
            'styles' => ['elegant'],
            'product_type' => 'abaya',
            'budget_max' => 800,
        ]);
        $intent = $brief->toIntent();

        self::assertTrue($intent->isGift);
        self::assertSame(['eid'], $intent->occasions);
        self::assertSame(['black'], $intent->colours);
        self::assertSame(800.0, $intent->budgetMax);
        self::assertContains('elegant', $intent->keywords);
        self::assertContains('abaya', $intent->keywords);
        // The recipient noun must NOT dilute the substring search.
        self::assertNotContains('sister', $intent->keywords);
        self::assertStringContainsString('sister', $brief->toRankerQuery());
    }

    // ===== fixtures =====

    /** @param list<Product> $items */
    private function service(array $items): GiftConciergeService
    {
        $ai = new GiftFakeAiProvider();
        $retrieval = new ProductRetrievalService($this->emReturning($items), $ai, $this->noEmbedStore());
        return new GiftConciergeService($retrieval, new ConciergeRanker($ai));
    }

    /** @param array<string, mixed> $overrides */
    private function brief(array $overrides): GiftBrief
    {
        return GiftBrief::fromArray($overrides);
    }

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
            $p->setStatus('active');
        }
        if (!$inStock) {
            $p->setStockStatus(Product::STOCK_OUT);
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
