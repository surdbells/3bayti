<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\Personalization;

use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Ai\Personalization\CustomerStyleProfileBuilder;
use Bayti\Api\Ai\Personalization\CustomerStyleSignals;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(CustomerStyleProfileBuilder::class)]
final class CustomerStyleProfileBuilderTest extends TestCase
{
    #[Test]
    public function aggregatesWeightedAffinitiesAcrossSignals(): void
    {
        $vendor50 = $this->makeVendor(50);
        $vendor60 = $this->makeVendor(60);
        $vendor70 = $this->makeVendor(70);
        $cat10 = $this->makeCategory(10, 'abayas');
        $cat20 = $this->makeCategory(20, 'kaftans');
        $cat30 = $this->makeCategory(30, 'bags');

        // Mixed casing on purpose: the catalogue stores colours verbatim
        // ("Black"/"black"), and the emitted tag must preserve the real spelling
        // (majority casing) so the case-sensitive colours filter can match.
        $p1 = $this->makeProduct($vendor50, 1, $cat10, ['Black'], '200.00');           // wishlist w=3
        $p2 = $this->makeProduct($vendor50, 2, $cat10, ['Black', 'Beige'], '300.00');  // wishlist w=3
        $p3 = $this->makeProduct($vendor60, 3, $cat20, ['black'], '500.00');           // purchased w=4
        $p4 = $this->makeProduct($vendor70, 4, $cat30, ['Rose'], '150.00');            // viewed w=1

        $signals = $this->signals(
            wishlist: [1, 2],
            purchased: [3],
            viewed: [4],
            followedVendors: [99],
        );
        $tags = $this->tagStore([
            1 => ['occasions' => [], 'colours' => [], 'styles' => ['minimal']],
            3 => ['occasions' => ['wedding'], 'colours' => [], 'styles' => ['elegant']],
        ]);
        $em = $this->emReturning([$p1, $p2, $p3, $p4]);

        $builder = new CustomerStyleProfileBuilder($signals, $tags, $em, new NullLogger());
        $profile = $builder->build(userId: 7);

        // black = 3+3+4 = 10 (top, grouped case-insensitively), beige = 3, rose = 1.
        // Emitted spelling preserves the catalogue casing (majority: "Black" 2 vs "black" 1).
        self::assertSame(['Black', 'Beige', 'Rose'], $profile->topColours(5));
        // cat10 = 6, cat20 = 4, cat30 = 1
        self::assertSame([10, 20, 30], $profile->topCategoryIds(5));
        // vendor50 = 6, follow99 = 5, vendor60 = 4, vendor70 = 1
        self::assertSame([50, 99, 60, 70], $profile->topVendorIds(5));
        // styles: elegant (4) then minimal (3); occasions: wedding (4)
        self::assertSame(['elegant', 'minimal'], $profile->topStyles(5));
        self::assertSame(['wedding'], $profile->topOccasions(5));
        // budget from strong signals (200/300/500) — <5 prices → raw min/max
        self::assertSame('200.00', $profile->budgetMin);
        self::assertSame('500.00', $profile->budgetMax);
        // seeds = recent wishlist then views
        self::assertSame([1, 2, 4], $profile->seedProductIds);
        self::assertSame(
            ['wishlist' => 2, 'orders' => 1, 'views' => 1, 'follows' => 1],
            $profile->signalCounts,
        );
    }

    #[Test]
    public function emptyWhenTheUserHasNoSignals(): void
    {
        $signals = $this->signals(wishlist: [], purchased: [], viewed: [], followedVendors: []);
        $builder = new CustomerStyleProfileBuilder($signals, $this->tagStore([]), $this->emReturning([]), new NullLogger());

        self::assertTrue($builder->build(1)->isEmpty());
    }

    #[Test]
    public function followsAloneStillProduceAVendorAffinity(): void
    {
        // A user who only follows stores (no wishlist/orders/views) still gets a
        // usable profile driven by vendor affinity.
        $signals = $this->signals(wishlist: [], purchased: [], viewed: [], followedVendors: [42, 43]);
        $builder = new CustomerStyleProfileBuilder($signals, $this->tagStore([]), $this->emReturning([]), new NullLogger());

        $profile = $builder->build(1);
        self::assertFalse($profile->isEmpty());
        self::assertSame([42, 43], $profile->topVendorIds(5));
    }

    // ===== fixtures =====

    /**
     * @param list<int> $wishlist
     * @param list<int> $purchased
     * @param list<int> $viewed
     * @param list<int> $followedVendors
     */
    private function signals(array $wishlist, array $purchased, array $viewed, array $followedVendors): CustomerStyleSignals
    {
        $mock = $this->createMock(CustomerStyleSignals::class);
        $mock->method('wishlistProductIds')->willReturn($wishlist);
        $mock->method('purchasedProductIds')->willReturn($purchased);
        $mock->method('viewedProductIds')->willReturn($viewed);
        $mock->method('followedVendorIds')->willReturn($followedVendors);
        return $mock;
    }

    /**
     * @param array<int, array{occasions: list<string>, colours: list<string>, styles: list<string>}> $tagsById
     */
    private function tagStore(array $tagsById): ProductAiAttributesStore
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturnCallback(
            static function () use ($tagsById): array {
                $rows = [];
                foreach ($tagsById as $id => $tags) {
                    $rows[] = [
                        'product_id' => $id,
                        'occasions' => (string) json_encode($tags['occasions']),
                        'colours' => (string) json_encode($tags['colours']),
                        'styles' => (string) json_encode($tags['styles']),
                    ];
                }
                return $rows;
            },
        );
        return new ProductAiAttributesStore($conn);
    }

    /**
     * @param list<Product> $products
     */
    private function emReturning(array $products): EntityManagerInterface
    {
        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findBy')->willReturn($products);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class) => $class === Product::class ? $repo : null,
        );
        return $em;
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

    /**
     * @param list<string> $colours
     */
    private function makeProduct(Vendor $vendor, int $id, Category $category, array $colours, string $price): Product
    {
        $p = new Product(vendor: $vendor, slug: "p-{$id}", name: "Product {$id}");
        $p->setCategory($category);
        $p->setStatus('active');
        $p->setPrice($price);
        $p->setAvailableColors($colours);
        $this->setId($p, $id);
        return $p;
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
