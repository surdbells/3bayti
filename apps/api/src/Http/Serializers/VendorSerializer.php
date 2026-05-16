<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use DateTimeInterface;

/**
 * Serialize Vendor entities for API responses.
 *
 * publicShape:    storefront-facing view (no commission, no contact phone)
 * featuredShape:  Designer Spotlight view (publicShape + rating
 *                 aggregate + embedded products) — apps/web home page
 * adminShape:     admin view (includes commission, all fields, is_featured)
 *
 * Contact email/phone are not in publicShape because exposing them
 * publicly invites scraping. Customers contact vendors through the
 * marketplace, not directly.
 */
final class VendorSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function publicShape(Vendor $v): array
    {
        return [
            'id' => $v->getId(),
            'slug' => $v->getSlug(),
            'name' => $v->getName(),
            'description' => $v->getDescription(),
            'logo_url' => $v->getLogoUrl(),
            'cover_image_url' => $v->getCoverImageUrl(),
            'is_verified' => $v->isVerified(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminShape(Vendor $v): array
    {
        return array_merge($this->publicShape($v), [
            'contact_email' => $v->getContactEmail(),
            'contact_phone' => $v->getContactPhone(),
            'commission_rate' => $v->getCommissionRate(),
            'is_active' => $v->isActive(),
            'is_featured' => $v->isFeatured(),
            'legacy_vendor_id' => $v->getLegacyVendorId(),
            'created_at' => $v->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $v->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Featured-vendor card shape for the apps/web Designer Spotlight.
     *
     * Matches the FeaturedVendor + FeaturedVendorProduct interfaces
     * defined in apps/web/src/app/features/catalog/designer-card.ts.
     * Any field-name change here is a breaking change for that
     * contract — keep them in sync.
     *
     * Why this method takes pre-computed data
     * ========================================
     * Rating + product list need DB queries. If this serializer
     * fetched them itself, it would couple the serializer to the
     * EntityManager and make it untestable. Per separation of
     * concerns, the controller computes the aggregates + product
     * list, the serializer shapes them. Same pattern as PriceSerializer
     * etc. elsewhere in the codebase.
     *
     * @param list<Product> $embeddedProducts up to 4 (controller-clamped)
     * @return array<string, mixed>
     */
    public function featuredShape(
        Vendor $vendor,
        array $embeddedProducts,
        ?float $rating,
        int $ratingCount,
    ): array {
        return [
            'slug' => $vendor->getSlug(),
            'name' => $vendor->getName(),
            'description' => $vendor->getDescription(),
            // Rating: round to 1dp for display; null preserved as null
            // (apps/web hides the rating chip when rating_count === 0,
            // so the null vs 0.0 distinction matters for accuracy.)
            'rating' => $rating !== null ? round($rating, 1) : null,
            'rating_count' => $ratingCount,
            'products' => array_map(
                static fn (Product $p): array => [
                    'id' => $p->getId(),
                    'slug' => $p->getSlug(),
                    // null primary_image_url falls back to empty string;
                    // apps/web's <img> would render a broken icon either way
                    // but empty string is the more honest representation.
                    'image_url' => $p->getPrimaryImageUrl() ?? '',
                    'name' => $p->getName(),
                ],
                $embeddedProducts,
            ),
        ];
    }

    /**
     * @param iterable<Vendor> $vendors
     * @return list<array<string, mixed>>
     */
    public function publicShapeMany(iterable $vendors): array
    {
        $out = [];
        foreach ($vendors as $v) { $out[] = $this->publicShape($v); }
        return $out;
    }

    /**
     * @param iterable<Vendor> $vendors
     * @return list<array<string, mixed>>
     */
    public function adminShapeMany(iterable $vendors): array
    {
        $out = [];
        foreach ($vendors as $v) { $out[] = $this->adminShape($v); }
        return $out;
    }
}
