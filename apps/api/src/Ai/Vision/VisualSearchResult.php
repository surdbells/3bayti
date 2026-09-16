<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Vision;

use Bayti\Api\Domain\Catalog\Product;

/**
 * The outcome of a visual search: a short "what Ain sees" description of the
 * query image and the ranked, validated (orderable + in-stock) product matches.
 */
final class VisualSearchResult
{
    /**
     * @param list<Product> $products
     */
    public function __construct(
        public readonly ?string $description,
        public readonly array $products,
    ) {
    }

    /**
     * @return list<int>
     */
    public function productIds(): array
    {
        $ids = [];
        foreach ($this->products as $p) {
            $id = $p->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
