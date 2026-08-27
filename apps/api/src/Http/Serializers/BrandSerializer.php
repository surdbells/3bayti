<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\Brand;
use DateTimeInterface;

/**
 * Convert Brand entities to public response shapes.
 *
 * One shape exposed: brand summary. No "full vs summary" distinction
 * needed yet, brand has so few fields that one shape covers admin
 * + public reads. If we add per-brand statistics (product count,
 * top categories) later, that's a separate "full" shape.
 *
 * is_active is NOT exposed in the public shape, public consumers
 * shouldn't even know about inactive brands. Admin response wraps
 * the same shape via a separate adminShape() that includes it.
 */
final class BrandSerializer
{
    /**
     * Public shape, what storefront consumers see.
     *
     * @return array<string, mixed>
     */
    public function publicShape(Brand $brand): array
    {
        return [
            'id' => $brand->getId(),
            'slug' => $brand->getSlug(),
            'name' => $brand->getName(),
            'logo_url' => $brand->getLogoUrl(),
        ];
    }

    /**
     * Admin shape, includes lifecycle fields hidden from public.
     *
     * @return array<string, mixed>
     */
    public function adminShape(Brand $brand): array
    {
        return array_merge($this->publicShape($brand), [
            'is_active' => $brand->isActive(),
            'created_at' => $brand->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $brand->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ]);
    }

    /**
     * @param iterable<Brand> $brands
     * @return list<array<string, mixed>>
     */
    public function publicShapeMany(iterable $brands): array
    {
        $result = [];
        foreach ($brands as $b) {
            $result[] = $this->publicShape($b);
        }
        return $result;
    }

    /**
     * @param iterable<Brand> $brands
     * @return list<array<string, mixed>>
     */
    public function adminShapeMany(iterable $brands): array
    {
        $result = [];
        foreach ($brands as $b) {
            $result[] = $this->adminShape($b);
        }
        return $result;
    }
}
