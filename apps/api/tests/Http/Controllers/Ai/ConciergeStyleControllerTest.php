<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Ai;

use Bayti\Api\Ai\AiProviderInterface;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Controllers\Ai\AiStatusController;
use Bayti\Api\Http\Controllers\Ai\ConciergeStyleController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ConciergeStyleController::class)]
#[CoversClass(AiStatusController::class)]
final class ConciergeStyleControllerTest extends HttpTestCase
{
    /** Bind a disabled AI provider (deterministic keyword path, no external calls). */
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
        $p->setPrice('250.00');
        $rp = new \ReflectionProperty($p, 'id');
        $rp->setAccessible(true);
        $rp->setValue($p, $id);
        return $p;
    }

    #[Test]
    public function returnsRankedRealProductsForAQuery(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Black Abaya'), $this->makeProduct(2, 'Beige Abaya')]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', [
            'query' => 'an elegant black abaya for a wedding',
            'locale' => 'en',
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $decoded = json_decode((string) $res->getBody(), true);
        $data = $decoded['data'] ?? $decoded;
        self::assertArrayHasKey('interaction_id', $data);
        self::assertArrayHasKey('intent', $data);
        self::assertCount(2, $data['products']);
        // Cards are real product shapes + Ain's reason field.
        self::assertArrayHasKey('reason', $data['products'][0]);
        self::assertArrayHasKey('price', $data['products'][0]);
    }

    #[Test]
    public function rejectsAnEmptyQuery(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', ['query' => '   ']));
        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
    }

    #[Test]
    public function statusReportsWhetherAinIsEnabled(): void
    {
        $this->bindDisabledAi();

        $res = $this->handle($this->jsonRequest('GET', '/v3/ai/status', []));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $decoded = json_decode((string) $res->getBody(), true);
        $data = $decoded['data'] ?? $decoded;
        self::assertFalse($data['enabled']);
    }
}
