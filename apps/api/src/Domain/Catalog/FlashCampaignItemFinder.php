<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use DateTimeImmutable;

/**
 * Finds the flash-campaign line items that are live at a given instant and
 * reference a given product, restricted to items that actually track stock
 * (stockRemaining is not null, a null allocation means "unlimited").
 *
 * Abstracted behind an interface so {@see FlashCampaignStockReducer} can be
 * unit-tested with a fake finder, without standing up a database.
 */
interface FlashCampaignItemFinder
{
    /**
     * @return CampaignItem[] Live, stock-tracking flash items for the product.
     */
    public function findActiveFlashItemsForProduct(int $productId, DateTimeImmutable $now): array;
}
