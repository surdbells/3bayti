<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRecommendation;
use Bayti\Api\Domain\Catalog\Vendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProductRecommendation entity (M3.2.X.12-B).
 *
 * Verifies invariants enforced in constructor + setters:
 *   - score must be numeric, >= 0, <= 9999.9999
 *   - source must be one of VALID_SOURCES
 *   - rank must be >= 1
 *   - product cannot recommend itself
 */
#[CoversClass(ProductRecommendation::class)]
final class ProductRecommendationTest extends TestCase
{
    #[Test]
    public function happyPathConstruction(): void
    {
        $source = $this->makeProduct(100);
        $target = $this->makeProduct(200);

        $rec = new ProductRecommendation(
            product: $source,
            recommendedProduct: $target,
            score: '12.5000',
            source: ProductRecommendation::SOURCE_COPURCHASE,
            rank: 1,
        );

        self::assertSame(100, $rec->getProduct()->getId());
        self::assertSame(200, $rec->getRecommendedProduct()->getId());
        self::assertSame('12.5000', $rec->getScore());
        self::assertSame('copurchase', $rec->getSource());
        self::assertSame(1, $rec->getRank());
        self::assertNull($rec->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $rec->getComputedAt());
    }

    #[Test]
    public function computedAtDefaultsToNow(): void
    {
        $before = new \DateTimeImmutable('-1 second');
        $rec = $this->makeRec();
        $after = new \DateTimeImmutable('+1 second');

        self::assertGreaterThanOrEqual($before, $rec->getComputedAt());
        self::assertLessThanOrEqual($after, $rec->getComputedAt());
    }

    #[Test]
    public function explicitComputedAtPreserved(): void
    {
        $when = new \DateTimeImmutable('2026-05-18T10:00:00+00:00');
        $rec = new ProductRecommendation(
            product: $this->makeProduct(100),
            recommendedProduct: $this->makeProduct(200),
            score: '5.0',
            source: ProductRecommendation::SOURCE_CATEGORY,
            rank: 3,
            computedAt: $when,
        );
        self::assertSame($when, $rec->getComputedAt());
    }

    // =================================================================
    // Score validation
    // =================================================================

    #[Test]
    public function scoreMustBeNumeric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/numeric/');
        new ProductRecommendation(
            product: $this->makeProduct(100),
            recommendedProduct: $this->makeProduct(200),
            score: 'not-a-number',
            source: ProductRecommendation::SOURCE_COPURCHASE,
            rank: 1,
        );
    }

    #[Test]
    public function scoreMustBeNonNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/>= 0/');
        new ProductRecommendation(
            product: $this->makeProduct(100),
            recommendedProduct: $this->makeProduct(200),
            score: '-1.0',
            source: ProductRecommendation::SOURCE_COPURCHASE,
            rank: 1,
        );
    }

    #[Test]
    public function scoreCapAtNumericMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/<= 9999/');
        new ProductRecommendation(
            product: $this->makeProduct(100),
            recommendedProduct: $this->makeProduct(200),
            score: '99999.9999',
            source: ProductRecommendation::SOURCE_COPURCHASE,
            rank: 1,
        );
    }

    #[Test]
    public function scoreZeroAccepted(): void
    {
        // Edge case: a score of exactly 0 is valid (e.g. a
        // fallback_popular row with no actual popularity signal)
        $rec = new ProductRecommendation(
            product: $this->makeProduct(100),
            recommendedProduct: $this->makeProduct(200),
            score: '0.0000',
            source: ProductRecommendation::SOURCE_FALLBACK_POPULAR,
            rank: 1,
        );
        self::assertSame('0.0000', $rec->getScore());
    }

    // =================================================================
    // Source validation
    // =================================================================

    #[Test]
    public function sourceMustBeKnownValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be one of/');
        new ProductRecommendation(
            product: $this->makeProduct(100),
            recommendedProduct: $this->makeProduct(200),
            score: '5.0',
            source: 'machine-learning',
            rank: 1,
        );
    }

    #[Test]
    public function allThreeValidSourcesAccepted(): void
    {
        foreach (ProductRecommendation::VALID_SOURCES as $source) {
            $rec = new ProductRecommendation(
                product: $this->makeProduct(100),
                recommendedProduct: $this->makeProduct(200),
                score: '1.0',
                source: $source,
                rank: 1,
            );
            self::assertSame($source, $rec->getSource());
        }
    }

    // =================================================================
    // Rank validation
    // =================================================================

    #[Test]
    public function rankMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/>= 1/');
        new ProductRecommendation(
            product: $this->makeProduct(100),
            recommendedProduct: $this->makeProduct(200),
            score: '5.0',
            source: ProductRecommendation::SOURCE_COPURCHASE,
            rank: 0,
        );
    }

    // =================================================================
    // Self-recommendation guard
    // =================================================================

    #[Test]
    public function productCannotRecommendItself(): void
    {
        $product = $this->makeProduct(100);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot recommend itself/');
        new ProductRecommendation(
            product: $product,
            recommendedProduct: $product,
            score: '5.0',
            source: ProductRecommendation::SOURCE_COPURCHASE,
            rank: 1,
        );
    }

    // =================================================================
    // touchComputedAt
    // =================================================================

    #[Test]
    public function touchComputedAtUpdatesTimestamp(): void
    {
        $rec = new ProductRecommendation(
            product: $this->makeProduct(100),
            recommendedProduct: $this->makeProduct(200),
            score: '5.0',
            source: ProductRecommendation::SOURCE_COPURCHASE,
            rank: 1,
            computedAt: new \DateTimeImmutable('2026-01-01'),
        );
        self::assertSame('2026-01-01', $rec->getComputedAt()->format('Y-m-d'));

        $newWhen = new \DateTimeImmutable('2026-05-18');
        $rec->touchComputedAt($newWhen);
        self::assertSame($newWhen, $rec->getComputedAt());
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeProduct(int $id): Product
    {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $vIdRef = new \ReflectionProperty(Vendor::class, 'id');
        $vIdRef->setAccessible(true);
        $vIdRef->setValue($vendor, 5);
        $vSlugRef = new \ReflectionProperty(Vendor::class, 'slug');
        $vSlugRef->setAccessible(true);
        $vSlugRef->setValue($vendor, 'v');
        $vNameRef = new \ReflectionProperty(Vendor::class, 'name');
        $vNameRef->setAccessible(true);
        $vNameRef->setValue($vendor, 'V');

        $product = new Product($vendor, "slug-{$id}", "Product {$id}");
        $idRef = new \ReflectionProperty(Product::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($product, $id);
        $product->setPrice('100.00');
        return $product;
    }

    private function makeRec(): ProductRecommendation
    {
        return new ProductRecommendation(
            product: $this->makeProduct(100),
            recommendedProduct: $this->makeProduct(200),
            score: '5.0',
            source: ProductRecommendation::SOURCE_COPURCHASE,
            rank: 1,
        );
    }
}
