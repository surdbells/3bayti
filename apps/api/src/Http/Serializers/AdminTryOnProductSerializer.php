<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\TryOn\TryOnGarmentEligibility;

/**
 * Row shape of the admin virtual try-on enablement screens
 * (GET /v3/admin/try-on/products items, PUT /v3/admin/products/{id}/try-on):
 *
 *   { id, slug, name, status, vendor: {id, name, slug} | null,
 *     primary_image_url | null, try_on_enabled,
 *     eligibility: { eligible, reason: null | no_image | external_image | unsupported_format } }
 *
 * `id` / `vendor.id` are v3 ids (never legacy ids). try_on_enabled is the RAW
 * stored flag; eligibility comes from the same {@see TryOnGarmentEligibility}
 * the try-on worker uses.
 */
final class AdminTryOnProductSerializer
{
    public function __construct(
        private readonly TryOnGarmentEligibility $eligibility,
    ) {
    }

    /**
     * @return array{id: int|null, slug: string, name: string, status: string, vendor: array{id: int|null, name: string, slug: string}|null, primary_image_url: string|null, try_on_enabled: bool, eligibility: array{eligible: bool, reason: string|null}}
     */
    public function shape(Product $p): array
    {
        $vendor = $p->getVendor();
        $primary = $p->getPrimaryImageUrl();

        return [
            'id' => $p->getId(),
            'slug' => $p->getSlug(),
            'name' => $p->getName(),
            'status' => $p->getStatus(),
            'vendor' => [
                'id' => $vendor->getId(),
                'name' => $vendor->getName(),
                'slug' => $vendor->getSlug(),
            ],
            'primary_image_url' => $primary !== null && $primary !== '' ? $primary : null,
            'try_on_enabled' => $p->isTryOnEnabled(),
            'eligibility' => $this->eligibility->describe($p),
        ];
    }

    /**
     * @param iterable<Product> $products
     * @return list<array<string, mixed>>
     */
    public function shapeMany(iterable $products): array
    {
        $out = [];
        foreach ($products as $p) {
            $out[] = $this->shape($p);
        }
        return $out;
    }
}
