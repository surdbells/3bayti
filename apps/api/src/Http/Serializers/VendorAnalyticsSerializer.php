<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\Vendor;

/**
 * Serialize the X.13 vendor analytics envelope for HTTP response
 * (M3.2.X.13-E).
 *
 * Shapes the response with:
 *   data: vendor identity + analytics envelope from
 *         VendorAnalyticsCalculator
 *   meta: computed_at + cache status
 *
 * Cache status is hardcoded to 'miss' in v1 (Q-Caching = A
 * locked: no cache). When operator follow-up #35 adds Redis
 * caching, this field becomes 'miss' | 'hit'.
 */
final class VendorAnalyticsSerializer
{
    /**
     * @param array{
     *     window: array{days: int, since: string, until: string},
     *     totals: array{revenue_aed: string, orders: int, items: int, aov_aed: string, unique_customers: int},
     *     revenue_series: list<array{date: string, revenue_aed: string, orders: int}>,
     *     top_products_by_units: list<array{product_id: int, slug: string, name: string, units: int, revenue_aed: string}>,
     *     top_products_by_revenue: list<array{product_id: int, slug: string, name: string, units: int, revenue_aed: string}>,
     *     customer_mix: array{new: int, returning: int, total: int},
     *     status_mix: array{delivered: int, cancelled: int, returned: int, total: int},
     * } $analytics
     * @return array<string, mixed>
     */
    public function shape(Vendor $vendor, array $analytics): array
    {
        return [
            'data' => [
                'vendor' => [
                    'id' => $vendor->getId(),
                    'slug' => $vendor->getSlug(),
                    'name' => $vendor->getName(),
                ],
                ...$analytics,
            ],
            'meta' => [
                'computed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->format(\DateTimeInterface::ATOM),
                'cache' => 'miss',
            ],
        ];
    }
}
