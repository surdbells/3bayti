<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Wishlist\WishlistLabel;

/**
 * Convert WishlistLabel entities into public response shapes.
 *
 * Shape:
 *   { id, name, count, created_at }
 *
 * `count` (saved-product count per label) is supplied by the caller -
 * the controller computes it in one grouped query rather than N
 * per-label counts.
 */
final class WishlistLabelSerializer
{
    /**
     * @param array<int,int> $counts map of labelId → saved-product count
     * @return array{id:int,name:string,count:int,created_at:string}
     */
    public function publicShape(WishlistLabel $label, array $counts = []): array
    {
        $id = $label->getId() ?? 0;

        return [
            'id' => $id,
            'name' => $label->getName(),
            'count' => $counts[$id] ?? 0,
            'created_at' => $label->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param iterable<WishlistLabel> $labels
     * @param array<int,int> $counts
     * @return list<array{id:int,name:string,count:int,created_at:string}>
     */
    public function publicShapeMany(iterable $labels, array $counts = []): array
    {
        $result = [];
        foreach ($labels as $label) {
            $result[] = $this->publicShape($label, $counts);
        }
        return $result;
    }
}
