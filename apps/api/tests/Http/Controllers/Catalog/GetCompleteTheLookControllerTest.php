<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Controllers\Catalog\GetCompleteTheLookController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(GetCompleteTheLookController::class)]
final class GetCompleteTheLookControllerTest extends HttpTestCase
{
    /**
     * @param array<int, Product> $byId    seed lookups (find())
     * @param list<Product>        $pool    findActivePaginated pool
     */
    private function bindCatalog(array $byId, array $pool): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->willReturnCallback(
            static fn ($id) => $byId[(int) $id] ?? null,
        );
        $productRepo->method('findActivePaginated')->willReturn(['items' => $pool, 'total' => count($pool)]);

        $em = $this->stubEm(function ($em) use ($productRepo): void {
            $em->method('getRepository')->willReturnCallback(
                static fn (string $class) => $class === Product::class ? $productRepo : null,
            );
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function makeCategory(int $id, string $slug): Category
    {
        $c = new Category($slug, ucfirst($slug));
        $rp = new \ReflectionProperty(Category::class, 'id');
        $rp->setAccessible(true);
        $rp->setValue($c, $id);
        return $c;
    }

    private function makeProduct(int $id, string $name, Category $category): Product
    {
        $vendor = new Vendor("v-{$id}", "Store {$id}", "v{$id}@example.test");
        $vendor->approve();
        $p = new Product(vendor: $vendor, slug: "p-{$id}", name: $name);
        $p->setCategory($category);
        $p->setStatus('active');
        $p->setPrice('300.00');
        $rp = new \ReflectionProperty($p, 'id');
        $rp->setAccessible(true);
        $rp->setValue($p, $id);
        return $p;
    }

    #[Test]
    public function returnsCrossCategoryComplements(): void
    {
        $abayas = $this->makeCategory(10, 'abayas');
        $bags = $this->makeCategory(20, 'bags');
        $scarves = $this->makeCategory(30, 'scarves');

        $seed = $this->makeProduct(1, 'Seed Abaya', $abayas);
        $pool = [$this->makeProduct(3, 'Leather Bag', $bags), $this->makeProduct(4, 'Silk Scarf', $scarves)];

        $this->bindCatalog([1 => $seed], $pool);

        $res = $this->handle($this->jsonRequest('GET', '/v3/products/1/complete-the-look', []));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $decoded = json_decode((string) $res->getBody(), true);
        $data = $decoded['data'] ?? $decoded;
        self::assertSame(1, $data['seed_product_id']);
        self::assertCount(2, $data['items']);
        self::assertArrayHasKey('reason', $data['items'][0]);
        self::assertArrayHasKey('complement_category', $data['items'][0]);
        self::assertArrayHasKey('price', $data['items'][0]);
        // Never the seed itself.
        self::assertNotContains(1, array_map(static fn ($i) => $i['id'], $data['items']));
    }

    #[Test]
    public function returns404ForAnUnknownProduct(): void
    {
        $this->bindCatalog([], []);

        $res = $this->handle($this->jsonRequest('GET', '/v3/products/999/complete-the-look', []));
        self::assertSame(404, $res->getStatusCode(), (string) $res->getBody());
    }

    #[Test]
    public function returns404ForAnUnorderableSeed(): void
    {
        $abayas = $this->makeCategory(10, 'abayas');
        $draft = new Vendor('v-2', 'Store 2', 'v2@example.test');
        $draft->approve();
        $seed = new Product(vendor: $draft, slug: 'p-2', name: 'Draft'); // status draft → not orderable
        $seed->setCategory($abayas);
        $rp = new \ReflectionProperty($seed, 'id');
        $rp->setAccessible(true);
        $rp->setValue($seed, 2);

        $this->bindCatalog([2 => $seed], []);

        $res = $this->handle($this->jsonRequest('GET', '/v3/products/2/complete-the-look', []));
        self::assertSame(404, $res->getStatusCode(), (string) $res->getBody());
    }
}
