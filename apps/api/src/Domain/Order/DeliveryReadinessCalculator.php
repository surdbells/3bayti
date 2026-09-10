<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use DateTimeImmutable;

/**
 * Computes, for an order, WHEN each line item is expected to be ready for
 * pickup and rolls those per-item dates up into an order-level "delivery
 * readiness" verdict. Pure domain logic (no persistence / HTTP) so the
 * operations Delivery-Readiness page can render a single view of the
 * Vendor → Pickup → Consolidated-Order → Customer-Delivery transition.
 *
 * There is no stored absolute per-product ready date, so it is ESTIMATED:
 *   expected_ready_date = order.created_at + lead-days
 * where lead-days is the upper bound of the vendor-configured per-product
 * delivery window (Product.delivery_info.time, e.g. "4-7" → 7; "custom" →
 * the largest number in custom_time), falling back to the vendor's
 * max_delivery_days when the product carries no usable window.
 *
 * Dates are compared at day granularity (Y-m-d), so "today" is inclusive.
 */
final class DeliveryReadinessCalculator
{
    /** Line-item statuses that are still awaiting dispatch (part of the pipeline). */
    private const UNSHIPPED_ACTIVE = [
        OrderItem::ITEM_STATUS_PENDING,
        OrderItem::ITEM_STATUS_ACCEPTED,
        OrderItem::ITEM_STATUS_PREPARING,
    ];

    /** Line-item statuses that have left the vendor (no longer awaiting readiness). */
    private const DISPATCHED = [
        OrderItem::ITEM_STATUS_SHIPPED,
        OrderItem::ITEM_STATUS_DELIVERED,
    ];

    /**
     * Per-order readiness verdict.
     *
     * @return array{
     *     readiness: 'ready_to_send'|'waiting'|'all_shipped',
     *     bucket: 'overdue'|'ready'|'waiting'|'shipped',
     *     is_overdue: bool,
     *     due_today: bool,
     *     awaiting_acceptance: bool,
     *     bottleneck_date: string|null,
     *     ready_count: int,
     *     unshipped_count: int,
     *     active_count: int,
     *     items: array<int, array{
     *         expected_ready_date: string,
     *         lead_days: int,
     *         lead_source: 'product'|'vendor',
     *         state: 'ready'|'upcoming'|'pending'|'shipped'|'terminal',
     *         is_late: bool
     *     }>
     * }
     */
    public function forOrder(Order $order, DateTimeImmutable $today): array
    {
        $todayKey = $today->format('Y-m-d');
        // Align the order's (TIMESTAMPTZ, usually UTC) creation instant to the
        // same wall-clock zone as "today" so the day-granular comparison does
        // not slip a day around midnight.
        $createdAt = $order->getCreatedAt()->setTimezone($today->getTimezone());

        $items = [];
        $bottleneck = null;          // latest expected date among unshipped active items
        $readyCount = 0;
        $unshippedCount = 0;
        $activeCount = 0;
        $awaitingAcceptance = false;

        foreach ($order->getItems() as $item) {
            $itemId = $item->getId() ?? 0;
            $status = $item->getItemStatus();
            $lead = self::leadDaysFor($item->getProduct(), $item->getVendor());
            $expected = $createdAt->modify('+' . $lead['days'] . ' days');
            $expectedKey = $expected->format('Y-m-d');

            if (in_array($status, self::DISPATCHED, true)) {
                $state = 'shipped';
                $activeCount++;
            } elseif (in_array($status, self::UNSHIPPED_ACTIVE, true)) {
                $activeCount++;
                $unshippedCount++;
                $isReady = $expectedKey <= $todayKey;
                if ($status === OrderItem::ITEM_STATUS_PENDING) {
                    $state = 'pending';
                    $awaitingAcceptance = true;
                } else {
                    $state = $isReady ? 'ready' : 'upcoming';
                }
                if ($isReady) {
                    $readyCount++;
                }
                if ($bottleneck === null || $expectedKey > $bottleneck) {
                    $bottleneck = $expectedKey;
                }
            } else {
                // rejected / cancelled / returned / refunded — not in the pipeline.
                $state = 'terminal';
            }

            $items[$itemId] = [
                'expected_ready_date' => $expectedKey,
                'lead_days' => $lead['days'],
                'lead_source' => $lead['source'],
                'state' => $state,
                'is_late' => $state !== 'shipped' && $state !== 'terminal' && $expectedKey < $todayKey,
            ];
        }

        if ($unshippedCount === 0) {
            // Everything active has been dispatched (or there's nothing active).
            return [
                'readiness' => 'all_shipped',
                'bucket' => 'shipped',
                'is_overdue' => false,
                'due_today' => false,
                'awaiting_acceptance' => false,
                'bottleneck_date' => null,
                'ready_count' => 0,
                'unshipped_count' => 0,
                'active_count' => $activeCount,
                'items' => $items,
            ];
        }

        $allReady = $readyCount === $unshippedCount;
        $isOverdue = $bottleneck !== null && $bottleneck < $todayKey;
        $dueToday = $bottleneck === $todayKey;

        if ($allReady) {
            $bucket = $isOverdue ? 'overdue' : 'ready';
            $readiness = 'ready_to_send';
        } else {
            $bucket = 'waiting';
            $readiness = 'waiting';
        }

        return [
            'readiness' => $readiness,
            'bucket' => $bucket,
            'is_overdue' => $isOverdue,
            'due_today' => $dueToday,
            'awaiting_acceptance' => $awaitingAcceptance,
            'bottleneck_date' => $bottleneck,
            'ready_count' => $readyCount,
            'unshipped_count' => $unshippedCount,
            'active_count' => $activeCount,
            'items' => $items,
        ];
    }

    /**
     * Resolve the delivery lead time (in days) for a product, preferring the
     * vendor-configured per-product window and falling back to the vendor's
     * store-wide max_delivery_days.
     *
     * @return array{days: int, source: 'product'|'vendor'}
     */
    public static function leadDaysFor(Product $product, Vendor $vendor): array
    {
        $fromProduct = self::leadDaysFromDeliveryInfo($product->getDeliveryInfo());
        if ($fromProduct !== null) {
            return ['days' => $fromProduct, 'source' => 'product'];
        }
        return ['days' => max(0, $vendor->getMaxDeliveryDays()), 'source' => 'vendor'];
    }

    /**
     * Parse the UPPER bound (in days) out of a Product.delivery_info payload
     * ({ time, custom_time, note }). `time` is either a range like "4-7",
     * a single number, or the literal "custom" (then the largest number in
     * `custom_time` free text is used). Returns null when nothing usable is
     * found, so the caller applies the vendor fallback.
     *
     * @param array<string, mixed>|null $deliveryInfo
     */
    public static function leadDaysFromDeliveryInfo(?array $deliveryInfo): ?int
    {
        if ($deliveryInfo === null) {
            return null;
        }

        $time = isset($deliveryInfo['time']) && is_scalar($deliveryInfo['time'])
            ? trim((string) $deliveryInfo['time'])
            : '';

        if ($time !== '' && strtolower($time) !== 'custom') {
            $days = self::maxIntIn($time);
            if ($days !== null) {
                return $days;
            }
        }

        if (strtolower($time) === 'custom') {
            $custom = isset($deliveryInfo['custom_time']) && is_scalar($deliveryInfo['custom_time'])
                ? (string) $deliveryInfo['custom_time']
                : '';
            $days = self::maxIntIn($custom);
            if ($days !== null) {
                return $days;
            }
        }

        return null;
    }

    /** Largest non-negative integer appearing in a string, or null if none. */
    private static function maxIntIn(string $s): ?int
    {
        if (preg_match_all('/\d+/', $s, $m) && $m[0] !== []) {
            $nums = array_map('intval', $m[0]);
            return max($nums);
        }
        return null;
    }
}
