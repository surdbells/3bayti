<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The publish-tracking stamps that drive the follower new-product alert cron:
 * setStatus() stamps published_at exactly once (the first draft→active), and
 * markFollowersNotified() records the idempotency marker.
 */
#[CoversClass(Product::class)]
final class ProductPublishStampTest extends TestCase
{
    #[Test]
    public function firstPublishStampsPublishedAt(): void
    {
        $product = $this->makeProduct();
        self::assertNull($product->getPublishedAt());

        $product->setStatus(Product::STATUS_ACTIVE);

        self::assertNotNull($product->getPublishedAt());
    }

    #[Test]
    public function republishingDoesNotResetPublishedAt(): void
    {
        $product = $this->makeProduct();
        $product->setStatus(Product::STATUS_ACTIVE);
        $first = $product->getPublishedAt();

        // Unpublish then republish: the original publish instant must survive,
        // so the cron never re-fires for an already-announced product.
        $product->setStatus(Product::STATUS_DRAFT);
        $product->setStatus(Product::STATUS_ACTIVE);

        self::assertSame($first, $product->getPublishedAt());
    }

    #[Test]
    public function draftNeverStampsPublishedAt(): void
    {
        $product = $this->makeProduct();
        $product->setStatus(Product::STATUS_DRAFT);

        self::assertNull($product->getPublishedAt());
    }

    #[Test]
    public function markFollowersNotifiedRecordsTheTimestamp(): void
    {
        $product = $this->makeProduct();
        self::assertNull($product->getFollowersNotifiedAt());

        $at = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $product->markFollowersNotified($at);

        self::assertSame($at, $product->getFollowersNotifiedAt());
    }

    private function makeProduct(): Product
    {
        return new Product($this->createMock(Vendor::class), 'product-x', 'Product X');
    }
}
