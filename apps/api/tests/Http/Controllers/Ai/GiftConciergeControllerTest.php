<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Ai;

use Bayti\Api\Ai\AiProviderInterface;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Controllers\Ai\GiftConciergeController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(GiftConciergeController::class)]
final class GiftConciergeControllerTest extends HttpTestCase
{
    private function bindDisabledAi(): void
    {
        $ai = $this->createMock(AiProviderInterface::class);
        $ai->method('isEnabled')->willReturn(false);
        $ai->method('completeJson')->willReturn([]);
        $ai->method('embed')->willReturn([]);
        $this->bind(AiProviderInterface::class, $ai);
    }

    /** @param list<Product> $items */
    private function bindProducts(array $items): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturn(['items' => $items, 'total' => count($items)]);

        $em = $this->stubEm(function ($em) use ($productRepo): void {
            $em->method('getRepository')->willReturnCallback(
                static fn (string $class) => $class === Product::class ? $productRepo : null,
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
        $rp = new \ReflectionProperty($p, 'id');
        $rp->setAccessible(true);
        $rp->setValue($p, $id);
        return $p;
    }

    #[Test]
    public function returnsRankedRealGiftIdeas(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Black Abaya'), $this->makeProduct(2, 'Beige Abaya'), $this->makeProduct(3, 'Grey Abaya')]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/gift', [
            'occasion' => 'eid',
            'recipient' => 'sister',
            'product_type' => 'abaya',
            'size' => 'M',
            'budget_max' => 600,
            'locale' => 'en',
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $decoded = json_decode((string) $res->getBody(), true);
        $data = $decoded['data'] ?? $decoded;
        self::assertArrayHasKey('interaction_id', $data);
        self::assertTrue($data['intent']['is_gift']);
        self::assertCount(3, $data['products']);
        self::assertArrayHasKey('reason', $data['products'][0]);
        self::assertArrayHasKey('price', $data['products'][0]);
        // Sized + well-matched apparel → no gift-card nudge.
        self::assertArrayNotHasKey('gift_card_suggestion', $data);
    }

    #[Test]
    public function surfacesGiftCardSuggestionForSizelessApparel(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Abaya A'), $this->makeProduct(2, 'Abaya B'), $this->makeProduct(3, 'Abaya C')]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/gift', [
            'occasion' => 'eid',
            'product_type' => 'abaya',
            'budget_max' => 600,
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $decoded = json_decode((string) $res->getBody(), true);
        $data = $decoded['data'] ?? $decoded;
        self::assertArrayHasKey('gift_card_suggestion', $data);
        self::assertSame('size_unknown', $data['gift_card_suggestion']['reason']);
        self::assertSame('500.00', $data['gift_card_suggestion']['suggested_denomination']);
        self::assertNotEmpty($data['gift_card_suggestion']['presets']);
    }

    #[Test]
    public function rejectsASignallessBrief(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/gift', ['recipient' => 'sister']));
        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
    }
}
