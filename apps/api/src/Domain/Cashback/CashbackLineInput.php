<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

/**
 * One order (or cart) line as the calculator sees it. `key` is the v3
 * order_items.id for real orders (null for a cart preview).
 */
final class CashbackLineInput
{
    /**
     * @param list<int> $categoryIds the product's category + its ancestors
     */
    public function __construct(
        public readonly ?int $key,
        public readonly ?int $vendorId,
        public readonly ?int $productId,
        public readonly array $categoryIds,
        public readonly string $subtotal,
    ) {
    }
}
