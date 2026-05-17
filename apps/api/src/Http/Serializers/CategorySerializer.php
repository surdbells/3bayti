<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\Category;
use DateTimeInterface;

/**
 * Serialize Category entities.
 *
 * Three shapes:
 *   - publicShape:  storefront leaf representation (no nested children)
 *   - publicShapeWithChildren: recursive tree (depth-bounded by caller)
 *   - adminShape:   includes is_active + timestamps + parent_id
 */
final class CategorySerializer
{
    /**
     * @return array<string, mixed>
     */
    public function publicShape(Category $c): array
    {
        return [
            'id' => $c->getId(),
            'slug' => $c->getSlug(),
            'name' => $c->getName(),
            'description' => $c->getDescription(),
            'display_order' => $c->getDisplayOrder(),
            'image_url' => $c->getImageUrl(),
            'path' => $c->getPath(),
            'parent_id' => $c->getParent()?->getId(),
        ];
    }

    /**
     * Tree shape — same as publicShape but with `children` key
     * containing the child array.
     *
     * @param Category $c
     * @param list<Category> $children Already-fetched direct children
     * @param callable(Category): list<Category> $fetchChildren Recursive fetcher
     * @return array<string, mixed>
     */
    public function publicShapeWithChildren(
        Category $c,
        array $children,
        callable $fetchChildren,
    ): array {
        $serialized = $this->publicShape($c);
        $childShapes = [];
        foreach ($children as $child) {
            $grandChildren = $fetchChildren($child);
            $childShapes[] = $this->publicShapeWithChildren($child, $grandChildren, $fetchChildren);
        }
        $serialized['children'] = $childShapes;
        return $serialized;
    }

    /**
     * @return array<string, mixed>
     */
    public function adminShape(Category $c): array
    {
        return array_merge($this->publicShape($c), [
            'is_active' => $c->isActive(),
            'created_at' => $c->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $c->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Category-detail-page shape for apps/web `/category/:slug` route.
     *
     * Matches the apps/web `CategoryDetail` interface defined in
     * apps/web/src/app/features/categories/category.model.ts.
     * Any field-name change here is a breaking change for the wire
     * contract — keep them in sync.
     *
     * Differences vs publicShape (the new fields apps/web requires)
     * ============================================================
     *   - `image`:    nested `{url}` object form, transformed from
     *                 the flat `image_url` field. `null` when no
     *                 image is set.
     *   - `icon_name`: Lucide icon name fallback (e.g. '@tui.sparkles').
     *                 Historically the legacy `category.icon` column
     *                 stored icon names rather than image filenames.
     *                 Apps/web's UI renders a letter-fallback when
     *                 neither `image` nor a recognizable icon exists.
     *   - `product_count`: raw count of products joined to this
     *                 category (NO active/published filter).
     *                 Distinct from `meta.total_products` which
     *                 is the active-only count.
     *
     * Why the serializer takes pre-computed product_count
     * ====================================================
     * Same separation-of-concerns rationale as VendorSerializer::
     * featuredShape (M3.2.X.2). The serializer must not perform DB
     * queries — that couples it to the EntityManager and makes it
     * untestable. The controller computes the count via
     * ProductRepository::countByCategoryRaw, then the serializer
     * shapes the response.
     *
     * Backwards compatibility
     * =======================
     * All publicShape fields are preserved (display_order, image_url,
     * path, parent_id). Other consumers (admin tool, internal scripts)
     * that depend on the existing shape continue to work; this method
     * only ADDS fields.
     *
     * The controller layers `children` + `products` onto the result
     * (Q-Children = A locked; admin tool depends on children being
     * emitted).
     *
     * @return array<string, mixed>
     */
    public function detailShape(Category $c, int $rawProductCount): array
    {
        $imageUrl = $c->getImageUrl();
        return array_merge($this->publicShape($c), [
            // Apps/web's image field is { url: string } | null. Convert
            // the flat image_url into the object form here so apps/web's
            // strict TypeScript interface is satisfied without runtime
            // adapters on the client side.
            'image' => $imageUrl !== null ? ['url' => $imageUrl] : null,
            // Apps/web's category-detail component reads icon_name to
            // pick the Lucide icon for the letter-fallback styling.
            'icon_name' => $c->getIcon(),
            // Raw count — caller-supplied. Apps/web's category.model.ts
            // distinguishes this from meta.total_products explicitly
            // (raw join vs filtered-by-status count).
            'product_count' => $rawProductCount,
        ]);
    }

    /**
     * @param iterable<Category> $categories
     * @return list<array<string, mixed>>
     */
    public function publicShapeMany(iterable $categories): array
    {
        $out = [];
        foreach ($categories as $c) { $out[] = $this->publicShape($c); }
        return $out;
    }

    /**
     * @param iterable<Category> $categories
     * @return list<array<string, mixed>>
     */
    public function adminShapeMany(iterable $categories): array
    {
        $out = [];
        foreach ($categories as $c) { $out[] = $this->adminShape($c); }
        return $out;
    }
}
