<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\Campaign;
use Bayti\Api\Domain\Catalog\CampaignItem;
use DateTimeInterface;

/**
 * Shapes Campaign aggregates for the public API.
 *
 * Pricing is derived, never stored: each item embeds the product via the
 * existing ProductSerializer::listShape() (so it already carries the
 * display-currency-converted price), and the campaign discount is applied
 * on top of that shaped price. This keeps campaign pricing correct as
 * product prices change AND consistent with the active display currency.
 *
 * Response item shape:
 *   {
 *     product: <ProductSerializer listShape>,
 *     discount_percent: int,        // effective (item override or campaign default)
 *     campaign_price: Money|null,    // product price with the discount applied (null if 0%)
 *     stock_total: int|null,
 *     stock_remaining: int|null
 *   }
 */
final class CampaignSerializer
{
    /**
     * @param array<string,mixed> ...$noop
     * @return array<string,mixed>
     */
    public function shape(Campaign $c, ProductSerializer $products): array
    {
        $items = [];
        foreach ($c->getItems() as $item) {
            // Campaign items are loaded without a product-active / vendor filter,
            // so an unapproved/suspended store's product (or an inactive one)
            // could otherwise surface on the home feed. Only show orderable
            // products — isOrderable() = product active AND vendor may sell.
            if (!$item->getProduct()->isOrderable()) {
                continue;
            }
            $items[] = $this->shapeItem($item, $products);
        }

        return [
            'id'               => $c->getId(),
            'slug'             => $c->getSlug(),
            'type'             => $c->getType(),
            'title'            => $c->getTitle(),
            'subtitle'         => $c->getSubtitle(),
            'discount_percent' => $c->getDiscountPercent(),
            'starts_at'        => $c->getStartsAt()->format(DateTimeInterface::ATOM),
            'ends_at'          => $c->getEndsAt()->format(DateTimeInterface::ATOM),
            'items'            => $items,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function shapeItem(CampaignItem $item, ProductSerializer $products): array
    {
        $productShape = $products->listShape($item->getProduct());
        $pct          = $item->effectiveDiscountPercent();

        /** @var array<string,mixed>|null $regular */
        $regular       = is_array($productShape['price'] ?? null) ? $productShape['price'] : null;
        $campaignPrice = ($pct > 0 && $regular !== null) ? $this->applyDiscount($regular, $pct) : null;

        return [
            'product'         => $productShape,
            'discount_percent' => $pct,
            'campaign_price'  => $campaignPrice,
            'stock_total'     => $item->getStockTotal(),
            'stock_remaining' => $item->getStockRemaining(),
        ];
    }

    /**
     * Apply a percentage discount to a money shape (amount + optional
     * dual-currency source_amount), preserving currency keys.
     *
     * @param array<string,mixed> $money
     * @return array<string,mixed>
     */
    private function applyDiscount(array $money, int $pct): array
    {
        $factor = (100 - max(0, min(100, $pct))) / 100;
        $out    = $money;

        if (isset($money['amount']) && is_numeric($money['amount'])) {
            $out['amount'] = round(((float) $money['amount']) * $factor, 2);
        }
        if (isset($money['source_amount']) && is_numeric($money['source_amount'])) {
            $out['source_amount'] = round(((float) $money['source_amount']) * $factor, 2);
        }

        return $out;
    }
}
