<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Campaign;
use Bayti\Api\Domain\Catalog\CampaignItem;
use Bayti\Api\Domain\Catalog\FlashCampaignItemFinder;
use Bayti\Api\Domain\Catalog\FlashCampaignStockReducer;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the flash-campaign stock reducer (no DB). The finder is
 * faked and order lines are mocked, so we exercise only the reducer's
 * decrement logic: match by product, reduce by quantity, floor at zero,
 * and leave unlimited / non-matching / zero-quantity lines alone.
 */
#[CoversClass(FlashCampaignStockReducer::class)]
final class FlashCampaignStockReducerTest extends TestCase
{
    /**
     * @param array<int, CampaignItem[]> $byProduct
     */
    private function finder(array $byProduct): FlashCampaignItemFinder
    {
        return new class($byProduct) implements FlashCampaignItemFinder {
            /** @param array<int, CampaignItem[]> $byProduct */
            public function __construct(private array $byProduct)
            {
            }

            public function findActiveFlashItemsForProduct(int $productId, DateTimeImmutable $now): array
            {
                return $this->byProduct[$productId] ?? [];
            }
        };
    }

    /** A CampaignItem carrying a given stockRemaining (campaign/product mocked). */
    private function item(?int $stock): CampaignItem
    {
        $ci = new CampaignItem(
            $this->createMock(Campaign::class),
            $this->createMock(Product::class),
        );
        $ci->setStockRemaining($stock);

        return $ci;
    }

    /** A mocked order line exposing a product id + quantity. */
    private function line(?int $productId, int $qty): OrderItem
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($productId);

        $line = $this->createMock(OrderItem::class);
        $line->method('getProduct')->willReturn($product);
        $line->method('getQuantity')->willReturn($qty);

        return $line;
    }

    private function order(OrderItem ...$lines): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getItems')->willReturn(new ArrayCollection($lines));

        return $order;
    }

    #[Test]
    public function it_decrements_a_live_flash_item_by_the_purchased_quantity(): void
    {
        $item = $this->item(10);
        $reducer = new FlashCampaignStockReducer($this->finder([1 => [$item]]));

        $reducer->reduceForPaidOrder($this->order($this->line(1, 3)));

        self::assertSame(7, $item->getStockRemaining());
    }

    #[Test]
    public function it_floors_stock_at_zero(): void
    {
        $item = $this->item(2);
        $reducer = new FlashCampaignStockReducer($this->finder([1 => [$item]]));

        $reducer->reduceForPaidOrder($this->order($this->line(1, 5)));

        self::assertSame(0, $item->getStockRemaining());
    }

    #[Test]
    public function it_leaves_unlimited_items_untouched(): void
    {
        $item = $this->item(null);
        $reducer = new FlashCampaignStockReducer($this->finder([1 => [$item]]));

        $reducer->reduceForPaidOrder($this->order($this->line(1, 4)));

        self::assertNull($item->getStockRemaining());
    }

    #[Test]
    public function it_decrements_every_matching_item_for_a_product(): void
    {
        $a = $this->item(10);
        $b = $this->item(5);
        $reducer = new FlashCampaignStockReducer($this->finder([1 => [$a, $b]]));

        $reducer->reduceForPaidOrder($this->order($this->line(1, 2)));

        self::assertSame(8, $a->getStockRemaining());
        self::assertSame(3, $b->getStockRemaining());
    }

    #[Test]
    public function it_aggregates_quantity_across_multiple_lines_for_the_same_product(): void
    {
        $item = $this->item(10);
        $reducer = new FlashCampaignStockReducer($this->finder([1 => [$item]]));

        // Two lines for product 1 (e.g. different size variants): 2 + 3.
        $reducer->reduceForPaidOrder($this->order($this->line(1, 2), $this->line(1, 3)));

        self::assertSame(5, $item->getStockRemaining());
    }

    #[Test]
    public function it_skips_lines_with_non_positive_quantity(): void
    {
        $item = $this->item(10);
        $reducer = new FlashCampaignStockReducer($this->finder([1 => [$item]]));

        $reducer->reduceForPaidOrder($this->order($this->line(1, 0)));

        self::assertSame(10, $item->getStockRemaining());
    }

    #[Test]
    public function it_skips_products_without_an_id(): void
    {
        // A transient product (null id) must not raise — and the finder is
        // never consulted for it.
        $reducer = new FlashCampaignStockReducer($this->finder([]));

        $reducer->reduceForPaidOrder($this->order($this->line(null, 3)));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function it_only_touches_items_for_the_ordered_product(): void
    {
        $ordered = $this->item(10);
        $other = $this->item(8);
        $reducer = new FlashCampaignStockReducer($this->finder([1 => [$ordered], 2 => [$other]]));

        // Order buys product 1 only.
        $reducer->reduceForPaidOrder($this->order($this->line(1, 4)));

        self::assertSame(6, $ordered->getStockRemaining());
        self::assertSame(8, $other->getStockRemaining(), 'un-ordered product left untouched');
    }
}
