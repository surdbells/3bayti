<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\VendorLabel;

/**
 * Serialize VendorLabel entities for API responses.
 *
 * publicShape: storefront-facing view (id, slug, name, display_order).
 *
 * The vendor reference is intentionally omitted — labels are always
 * listed in the context of a vendor (the GET /v3/vendors/{slug}/labels
 * endpoint already locates the response under a known vendor), so
 * embedding the vendor on each label is redundant payload.
 */
final class VendorLabelSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function publicShape(VendorLabel $l): array
    {
        return [
            'id' => $l->getId(),
            'slug' => $l->getSlug(),
            'name' => $l->getName(),
            'display_order' => $l->getDisplayOrder(),
        ];
    }

    /**
     * @param list<VendorLabel> $labels
     * @return list<array<string, mixed>>
     */
    public function publicShapeMany(array $labels): array
    {
        return array_map(fn (VendorLabel $l) => $this->publicShape($l), $labels);
    }
}
