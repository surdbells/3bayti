<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\Style;

/**
 * Serialize Style entities for API responses.
 *
 * listShape: storefront-facing view used by the GET /v3/styles
 * endpoint. Includes the embedded products array — pre-loaded by the
 * caller via StyleRepository::loadProductsForStyles so we avoid an
 * N+1 fetch from inside the serializer.
 *
 * Output shape (matches the mobile bindings on styles.page.html):
 *   {
 *     id, slug, name, description, cover_image_url,
 *     style_type: 'community' | 'editorial',
 *     total_price (string, DECIMAL preserved as-is),
 *     products: [{id, slug, name, primary_image_url, price, display_order}, …]
 *   }
 *
 * Why style_type renders as a string in the response (not int)
 * =============================================================
 * Mobile sends `type: 'community'` in the request body, and reads
 * style.style_name (no style_type binding in the current HTML). The
 * v3 DB stores it as a smallint enum (1=community, 2=editorial) for
 * efficiency + CHECK constraint protection. The serializer reverses
 * the mapping on egress so the API surface uses human-readable
 * strings — consistent with how mobile thinks about the field.
 */
final class StyleSerializer
{
    /**
     * @param list<array{
     *     id: int,
     *     slug: string,
     *     name: string,
     *     primary_image_url: ?string,
     *     price: string,
     *     display_order: int
     * }> $products
     * @return array<string, mixed>
     */
    public function listShape(Style $s, array $products): array
    {
        return [
            'id' => $s->getId(),
            'slug' => $s->getSlug(),
            'name' => $s->getName(),
            'description' => $s->getDescription(),
            'cover_image_url' => $s->getCoverImageUrl(),
            'style_type' => $this->styleTypeLabel($s->getStyleType()),
            'total_price' => $s->getTotalPrice(),
            'products' => $products,
        ];
    }

    /**
     * Build a list of style shapes from an entity list and a pre-
     * loaded products map. The products map is keyed by style id —
     * exactly the shape returned by StyleRepository::loadProductsForStyles.
     *
     * Styles missing from the products map render with products=[]
     * (a style with no products is unusual but not an error).
     *
     * @param list<Style> $styles
     * @param array<int, list<array{
     *     id: int,
     *     slug: string,
     *     name: string,
     *     primary_image_url: ?string,
     *     price: string,
     *     display_order: int
     * }>> $productsByStyleId
     * @return list<array<string, mixed>>
     */
    public function listShapeMany(array $styles, array $productsByStyleId): array
    {
        return array_map(
            fn (Style $s) => $this->listShape(
                $s,
                $productsByStyleId[(int) $s->getId()] ?? [],
            ),
            $styles,
        );
    }

    private function styleTypeLabel(int $type): string
    {
        return match ($type) {
            Style::TYPE_COMMUNITY => 'community',
            Style::TYPE_EDITORIAL => 'editorial',
            default => 'community',
        };
    }
}
