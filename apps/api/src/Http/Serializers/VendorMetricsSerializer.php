<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\Vendor;

/**
 * Shape VendorMetricsCalculator output for the HTTP response envelope
 * (M3.2.X.14-B/C/D).
 *
 * Three shapes:
 *
 *   - singleShape: { data: { vendor_id, vendor_slug, vendor_name,
 *                            window, metrics } }
 *     Used by GET /v3/admin/vendors/{id}/metrics and
 *     GET /v3/vendor/metrics.
 *
 *   - listShape: { data: [ { vendor_id, ..., metrics }, ... ],
 *                  meta: { total, limit, offset, window } }
 *     Used by GET /v3/admin/vendor-metrics.
 */
final class VendorMetricsSerializer
{
    /**
     * @param array{
     *     window: array{days: int, since: string, until: string},
     *     metrics: array<string, array<string, mixed>>
     * } $metrics
     * @return array{
     *     data: array{
     *         vendor_id: int,
     *         vendor_slug: string,
     *         vendor_name: string,
     *         window: array{days: int, since: string, until: string},
     *         metrics: array<string, array<string, mixed>>
     *     }
     * }
     */
    public function singleShape(Vendor $vendor, array $metrics): array
    {
        return [
            'data' => [
                'vendor_id' => $vendor->getId() ?? 0,
                'vendor_slug' => $vendor->getSlug(),
                'vendor_name' => $vendor->getName(),
                'window' => $metrics['window'],
                'metrics' => $metrics['metrics'],
            ],
        ];
    }

    /**
     * Build the list response. Vendors and per-vendor metrics arrive
     * separately (the calculator computes metrics keyed by vendor_id;
     * the controller fetches the corresponding Vendor entities and
     * preserves the sorted order chosen by the caller).
     *
     * @param list<Vendor> $vendors
     * @param array<int, array{
     *     window: array{days: int, since: string, until: string},
     *     metrics: array<string, array<string, mixed>>
     * }> $metricsByVendorId
     * @param array{days: int, since: string, until: string} $window
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{total: int, limit: int, offset: int, window: array{days: int, since: string, until: string}}
     * }
     */
    public function listShape(
        array $vendors,
        array $metricsByVendorId,
        int $total,
        int $limit,
        int $offset,
        array $window,
    ): array {
        $rows = [];
        foreach ($vendors as $v) {
            $vid = $v->getId() ?? 0;
            $entry = [
                'vendor_id' => $vid,
                'vendor_slug' => $v->getSlug(),
                'vendor_name' => $v->getName(),
            ];
            if (isset($metricsByVendorId[$vid])) {
                $entry['metrics'] = $metricsByVendorId[$vid]['metrics'];
            } else {
                // Defensive: vendor present in list but no metrics row.
                // This shouldn't happen, the calculator returns null-rate
                // entries for empty vendors, but degrade cleanly if it does.
                $entry['metrics'] = $this->nullMetrics();
            }
            $rows[] = $entry;
        }

        return [
            'data' => $rows,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'window' => $window,
            ],
        ];
    }

    /**
     * Canonical null-metrics block. Used as a degraded-state fallback
     * when a vendor entity exists but no metrics row was returned by
     * the calculator (defensive only, the calculator handles this
     * case internally).
     *
     * @return array<string, array<string, mixed>>
     */
    private function nullMetrics(): array
    {
        return [
            'fulfillment_rate' => ['value' => null, 'fulfilled_items' => 0, 'total_items' => 0],
            'cancellation_rate' => ['value' => null, 'rejected_items' => 0, 'total_items' => 0],
            'return_rate' => ['value' => null, 'approved_returns' => 0, 'total_items' => 0],
            'dispute_rate' => ['value' => null, 'disputed_orders' => 0, 'total_orders' => 0],
        ];
    }
}
