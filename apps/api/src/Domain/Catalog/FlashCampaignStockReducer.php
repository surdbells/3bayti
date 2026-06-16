<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Bayti\Api\Domain\Order\Order;
use DateTimeImmutable;

/**
 * Decrements flash-campaign stock for the lines of a just-paid order.
 *
 * Invoked exactly once per order — on the first paid transition (callers
 * gate on {@see Order::markPaid()}'s boolean return). For each ordered
 * line, every live flash-campaign item referencing that product has its
 * stockRemaining reduced by the purchased quantity, floored at zero.
 *
 * This only mutates the managed {@see CampaignItem} entities; the caller's
 * existing flush persists the change inside its own unit of work, so the
 * decrement participates in the same transaction as the order transition.
 *
 * Anniversary deals and unlimited-stock flash items (stockRemaining null)
 * are deliberately left untouched.
 */
final class FlashCampaignStockReducer
{
    public function __construct(private readonly FlashCampaignItemFinder $finder)
    {
    }

    public function reduceForPaidOrder(Order $order, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();

        foreach ($order->getItems() as $line) {
            $quantity = $line->getQuantity();
            if ($quantity <= 0) {
                continue;
            }

            $productId = $line->getProduct()->getId();
            if ($productId === null) {
                continue;
            }

            foreach ($this->finder->findActiveFlashItemsForProduct($productId, $now) as $item) {
                $remaining = $item->getStockRemaining();
                if ($remaining === null) {
                    continue;
                }
                $item->setStockRemaining(max(0, $remaining - $quantity));
            }
        }
    }
}
