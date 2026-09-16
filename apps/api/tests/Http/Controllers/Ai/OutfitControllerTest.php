<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Ai;

use Bayti\Api\Ai\AiProviderInterface;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Controllers\Ai\OutfitController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(OutfitController::class)]
final class OutfitControllerTest extends HttpTestCase
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

    private function makeProduct(int $id, string $name, string $categorySlug, string $price): Product
    {
        $vendor = new Vendor("v-{$id}", "Store {$id}", "v{$id}@example.test");
        $vendor->approve();
        $category = new Category($categorySlug, ucfirst($categorySlug));
        $this->setId($category, 100 + crc32($categorySlug) % 1000);
        $p = new Product(vendor: $vendor, slug: "p-{$id}", name: $name);
        $p->setCategory($category);
        $p->setStatus('active');
        $p->setPrice($price);
        $this->setId($p, $id);
        return $p;
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    #[Test]
    public function generatesACoordinatedOutfit(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([
            $this->makeProduct(1, 'Rose Abaya', 'abayas', '400.00'),
            $this->makeProduct(2, 'Gold Clutch', 'bags', '120.00'),
            $this->makeProduct(3, 'Silk Scarf', 'scarves', '90.00'),
        ]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/outfit', [
            'occasion' => 'wedding',
            'styles' => ['elegant'],
            'colours' => ['rose'],
            'locale' => 'en',
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $decoded = json_decode((string) $res->getBody(), true);
        $data = $decoded['data'] ?? $decoded;

        self::assertArrayHasKey('interaction_id', $data);
        self::assertFalse($data['intent']['is_gift']);
        self::assertNotEmpty($data['rationale']);
        self::assertCount(3, $data['items']);
        self::assertSame('hero', $data['items'][0]['role']);
        self::assertArrayHasKey('reason', $data['items'][0]);
        self::assertArrayHasKey('product', $data['items'][0]);
        self::assertArrayHasKey('id', $data['items'][0]['product']);
        self::assertArrayHasKey('total_price', $data);
        self::assertSame(610.0, $data['total_price']['amount']);
        self::assertArrayNotHasKey('gift_card_suggestion', $data);
    }

    #[Test]
    public function rejectsASignallessBrief(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/outfit', []));
        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
    }

    #[Test]
    public function suggestsAGiftCardWhenNoLookCanBeBuilt(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/outfit', [
            'occasion' => 'eid',
            'budget_max' => 500,
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $decoded = json_decode((string) $res->getBody(), true);
        $data = $decoded['data'] ?? $decoded;
        self::assertSame([], $data['items']);
        self::assertArrayHasKey('gift_card_suggestion', $data);
        self::assertSame('few_matches', $data['gift_card_suggestion']['reason']);
    }
}
