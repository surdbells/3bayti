<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\Personalization;

use Bayti\Api\Ai\Personalization\CustomerStyleProfile;
use Bayti\Api\Ai\Personalization\CustomerStyleProfileStore;
use Bayti\Api\Ai\Personalization\CustomerStyleSignals;
use Bayti\Api\Ai\Personalization\ForYouRail;
use Bayti\Api\Ai\Personalization\ForYouRailsService;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\RecommendationsService;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForYouRailsService::class)]
final class ForYouRailsServiceTest extends TestCase
{
    private Vendor $vendor;

    protected function setUp(): void
    {
        $this->vendor = new Vendor('vendor-1', 'Vendor 1', 'v1@example.test');
        $this->vendor->approve();
        $this->setId($this->vendor, 1);
    }

    #[Test]
    public function buildsPersonalisedRailsAndNeverSurfacesUnsellableOrOwnedProducts(): void
    {
        $yourStylePool = [
            $this->product(1),
            $this->product(2),
            $this->product(3),
            $this->product(4, inStock: false),   // sold out → dropped
            $this->product(5, orderable: false),  // unsellable → dropped
            $this->product(100),                   // owned (purchased) → excluded
        ];
        $storesPool = [$this->product(10), $this->product(11), $this->product(12)];
        // id 1 is repeated here but was already shown in your_style → de-duped out.
        $newPool = [$this->product(1), $this->product(20), $this->product(21), $this->product(22)];
        $likedRecs = $this->recRows([$this->product(30), $this->product(31), $this->product(32)]);

        $seed = $this->product(50);
        $profile = new CustomerStyleProfile(
            colours: [['tag' => 'black', 'weight' => 9.0]],
            categories: [['id' => 10, 'weight' => 9.0]],
            vendors: [['id' => 1, 'weight' => 6.0]],
            seedProductIds: [50],
        );

        $service = $this->service(
            profile: $profile,
            owned: [100],
            wishlisted: [101],
            findActivePaginated: static function (array $filters) use ($yourStylePool, $storesPool, $newPool): array {
                if (isset($filters['vendorId'])) {
                    return ['items' => $storesPool, 'total' => count($storesPool)];
                }
                if (isset($filters['isNew'])) {
                    return ['items' => $newPool, 'total' => count($newPool)];
                }
                return ['items' => $yourStylePool, 'total' => count($yourStylePool)];
            },
            seed: $seed,
            recsForProduct: $likedRecs,
            recsForUser: [],
        );

        $set = $service->build($this->user(7), 3);

        self::assertTrue($set->profileReady);
        self::assertSame(
            [
                ForYouRailsService::KEY_YOUR_STYLE,
                ForYouRailsService::KEY_STORES,
                ForYouRailsService::KEY_LIKED,
                ForYouRailsService::KEY_NEW,
            ],
            array_map(static fn (ForYouRail $r): string => $r->key, $set->rails),
        );

        $byKey = [];
        foreach ($set->rails as $rail) {
            $byKey[$rail->key] = array_map(static fn (Product $p): int => (int) $p->getId(), $rail->products);
        }
        self::assertSame([1, 2, 3], $byKey[ForYouRailsService::KEY_YOUR_STYLE]);
        self::assertSame([10, 11, 12], $byKey[ForYouRailsService::KEY_STORES]);
        self::assertSame([30, 31, 32], $byKey[ForYouRailsService::KEY_LIKED]);
        self::assertSame([20, 21, 22], $byKey[ForYouRailsService::KEY_NEW]); // id 1 de-duped

        // "Because you liked…" carries the seed name for the client heading.
        $liked = array_values(array_filter($set->rails, static fn (ForYouRail $r): bool => $r->key === ForYouRailsService::KEY_LIKED))[0];
        self::assertSame('Product 50', $liked->seedName);

        // Anti-hallucination: nothing unsellable or owned appears anywhere.
        $allIds = array_merge(...array_values($byKey));
        self::assertNotContains(4, $allIds);
        self::assertNotContains(5, $allIds);
        self::assertNotContains(100, $allIds);
        foreach ($set->rails as $rail) {
            foreach ($rail->products as $product) {
                self::assertTrue($product->isOrderable() && $product->isInStock());
            }
        }
    }

    #[Test]
    public function coldStartWhenNoProfileReturnsPopularRail(): void
    {
        $cold = $this->recRows([$this->product(1), $this->product(2), $this->product(3), $this->product(4, inStock: false)]);

        $service = $this->service(
            profile: null,
            owned: [],
            wishlisted: [],
            findActivePaginated: static fn (array $filters): array => ['items' => [], 'total' => 0],
            seed: null,
            recsForProduct: [],
            recsForUser: $cold,
        );

        $set = $service->build($this->user(7), 3);

        self::assertFalse($set->profileReady);
        self::assertCount(1, $set->rails);
        self::assertSame(ForYouRailsService::KEY_POPULAR, $set->rails[0]->key);
        self::assertSame([1, 2, 3], array_map(static fn (Product $p): int => (int) $p->getId(), $set->rails[0]->products));
    }

    #[Test]
    public function fallsBackToPopularWhenProfileYieldsNothingSellable(): void
    {
        // Profile exists, but every retrieval comes back empty (e.g. their
        // colours are all sold out) → the cold popular rail rescues the page.
        $cold = $this->recRows([$this->product(7), $this->product(8), $this->product(9)]);
        $profile = new CustomerStyleProfile(colours: [['tag' => 'black', 'weight' => 9.0]]);

        $service = $this->service(
            profile: $profile,
            owned: [],
            wishlisted: [],
            findActivePaginated: static fn (array $filters): array => ['items' => [], 'total' => 0],
            seed: null,
            recsForProduct: [],
            recsForUser: $cold,
        );

        $set = $service->build($this->user(7), 3);

        self::assertFalse($set->profileReady);
        self::assertCount(1, $set->rails);
        self::assertSame(ForYouRailsService::KEY_POPULAR, $set->rails[0]->key);
    }

    // ===== fixtures =====

    /**
     * @param list<int> $owned
     * @param list<int> $wishlisted
     * @param callable(array<string,mixed>): array{items: list<Product>, total: int} $findActivePaginated
     * @param list<array{product: Product, score: string, source: string}> $recsForProduct
     * @param list<array{product: Product, score: string, source: string}> $recsForUser
     */
    private function service(
        ?CustomerStyleProfile $profile,
        array $owned,
        array $wishlisted,
        callable $findActivePaginated,
        ?Product $seed,
        array $recsForProduct,
        array $recsForUser,
    ): ForYouRailsService {
        $store = $this->createMock(CustomerStyleProfileStore::class);
        $store->method('fetch')->willReturn($profile);

        $signals = $this->createMock(CustomerStyleSignals::class);
        $signals->method('purchasedProductIds')->willReturn($owned);
        $signals->method('wishlistProductIds')->willReturn($wishlisted);

        $recs = $this->createMock(RecommendationsService::class);
        $recs->method('getRecommendationsForProduct')->willReturn($recsForProduct);
        $recs->method('getRecommendationsForUser')->willReturn($recsForUser);

        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findActivePaginated')->willReturnCallback($findActivePaginated);
        $repo->method('find')->willReturnCallback(
            static fn (mixed $id) => ($seed !== null && (int) $id === (int) $seed->getId()) ? $seed : null,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class) => $class === Product::class ? $repo : null,
        );

        return new ForYouRailsService($store, $signals, $recs, $em);
    }

    /**
     * @param list<Product> $products
     * @return list<array{product: Product, score: string, source: string}>
     */
    private function recRows(array $products): array
    {
        return array_map(
            static fn (Product $p): array => ['product' => $p, 'score' => '1.0', 'source' => 'copurchase'],
            $products,
        );
    }

    private function product(int $id, bool $orderable = true, bool $inStock = true): Product
    {
        $p = new Product(vendor: $this->vendor, slug: "p-{$id}", name: "Product {$id}");
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

    private function user(int $id): User
    {
        $u = new User('u@example.test', null, 'hash');
        $this->setId($u, $id);
        return $u;
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
