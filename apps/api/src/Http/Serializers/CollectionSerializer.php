<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\ProductCollection;

/**
 * Shapes ProductCollection for the PUBLIC storefront (shop-by-collection cards
 * + the collection detail/PLP header). Admin shaping stays in
 * CollectionCrudController::shape(); this is the customer-facing contract.
 *
 * `image_url` is the collection's representative image — the first curated
 * product's image (resolved by the repository), falling back to the
 * collection's own cover image, then null. The web home card renders it as a
 * full-bleed background; mobile uses it on the chip/hero.
 */
final class CollectionSerializer
{
    /**
     * Card shape for GET /v3/collections.
     *
     * @return array{id: int|null, slug: string, name: string, description: string|null, image_url: string|null, product_count: int, display_order: int|null}
     */
    public function listShape(ProductCollection $c, ?string $representativeImageUrl, int $productCount): array
    {
        return [
            'id' => $c->getId(),
            'slug' => $c->getSlug(),
            'name' => $c->getName(),
            'description' => $c->getDescription(),
            'image_url' => $representativeImageUrl ?? $c->getCoverImageUrl(),
            'product_count' => $productCount,
            'display_order' => $c->getDisplayOrder(),
        ];
    }

    /**
     * Detail header for GET /v3/collections/{slug} (products embedded by the
     * controller alongside this).
     *
     * @return array{id: int|null, slug: string, name: string, description: string|null, image_url: string|null, product_count: int}
     */
    public function detailShape(ProductCollection $c, ?string $representativeImageUrl, int $productCount): array
    {
        return [
            'id' => $c->getId(),
            'slug' => $c->getSlug(),
            'name' => $c->getName(),
            'description' => $c->getDescription(),
            'image_url' => $representativeImageUrl ?? $c->getCoverImageUrl(),
            'product_count' => $productCount,
        ];
    }
}
