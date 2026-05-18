<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\User\User;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Determines whether a customer can submit a return request for one
 * or more items in an order (M3.2.X.18-C).
 *
 * Three rules (Q-EligibilityWindow + Q-Authorization locked):
 *
 *   Rule 1 — Window. Order must have been paid within the
 *            configured window (default 14 days). The window starts
 *            from paid_at (delivered_at not yet tracked in v3; paid_at
 *            is the most authoritative timestamp we have).
 *
 *   Rule 2 — Per-item. Each OrderItem in the request must be in the
 *            DELIVERED status, and must NOT already be in a returned/
 *            refunded/cancelled state (already settled).
 *
 *   Rule 3 — No overlap. None of the OrderItems in the request can be
 *            referenced by another in-flight (non-terminal) return
 *            request. Prevents accidental duplicate submissions.
 *
 * Returns a structured ReturnEligibilityResult — ok() if all rules
 * pass, or one of the failure factories with a specific error code
 * the controller will surface as a 422 with that code.
 *
 * Note on authorization
 * =====================
 * Q-Authorization = A locks customer-owns-order to a 404 (not 403)
 * for cross-user attempts. That check happens at the controller
 * layer BEFORE eligibility — by the time eligibility runs, we already
 * know the customer owns the order. This service does not re-check
 * authorization; it focuses purely on the lifecycle/state rules.
 */
final class ReturnRequestEligibilityService
{
    /** Default return window: 14 days from paid_at. */
    public const DEFAULT_WINDOW_DAYS = 14;

    public function __construct(
        private readonly OrderReturnRequestRepository $returnRepo,
        private readonly int $windowDays = self::DEFAULT_WINDOW_DAYS,
        private readonly ?DateTimeImmutable $now = null,
    ) {
    }

    /**
     * Evaluate eligibility for the given customer + order + requested
     * order item IDs. Each item ID must belong to the order.
     *
     * @param list<int> $requestedOrderItemIds
     */
    public function evaluate(
        User $customer,
        Order $order,
        array $requestedOrderItemIds,
    ): ReturnEligibilityResult {
        // Sanity: nothing requested.
        if ($requestedOrderItemIds === []) {
            return ReturnEligibilityResult::failure(
                'RETURN_NO_ITEMS_SPECIFIED',
                'At least one order item must be specified.',
            );
        }

        // The customer-owns-order check is enforced at the controller
        // layer per Q-Authorization. Defense in depth: assert the
        // order's customer matches the supplied customer here too —
        // if it doesn't, treat as RETURN_FORBIDDEN (the controller
        // would have already returned 404 in normal flow).
        if ($order->getUser()->getId() !== $customer->getId()) {
            return ReturnEligibilityResult::failure(
                'RETURN_FORBIDDEN',
                'Order does not belong to the requesting customer.',
            );
        }

        // Rule 1 — window.
        $windowResult = $this->checkWindow($order);
        if (!$windowResult->ok) {
            return $windowResult;
        }

        // Rule 2 — per-item eligibility. Builds a list of the actual
        // OrderItem instances on the way so we can hand them back to
        // the controller without re-fetching.
        $itemsResult = $this->checkItems($order, $requestedOrderItemIds);
        if (!$itemsResult->ok) {
            return $itemsResult;
        }

        // Rule 3 — no overlap with existing in-flight returns.
        if ($this->returnRepo->hasOverlappingPendingForOrderItems($requestedOrderItemIds)) {
            return ReturnEligibilityResult::failure(
                'RETURN_OVERLAPPING_PENDING',
                'One or more of these items already has an in-flight return request.',
            );
        }

        // All rules passed. Hand back the resolved OrderItems so the
        // controller doesn't have to re-fetch.
        return ReturnEligibilityResult::ok($itemsResult->resolvedItems);
    }

    // -----------------------------------------------------------------
    // Rule 1 — window
    // -----------------------------------------------------------------

    private function checkWindow(Order $order): ReturnEligibilityResult
    {
        $paidAt = $order->getPaidAt();
        if ($paidAt === null) {
            return ReturnEligibilityResult::failure(
                'RETURN_ORDER_NOT_PAID',
                'Returns can only be requested for paid orders.',
            );
        }

        $now = $this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $paidAt->add(new DateInterval("P{$this->windowDays}D"));

        if ($now > $cutoff) {
            $daysSince = (int) $now->diff($paidAt)->days;
            return ReturnEligibilityResult::failure(
                'RETURN_WINDOW_EXPIRED',
                "Return window of {$this->windowDays} days has expired ({$daysSince} days since payment).",
            );
        }

        return ReturnEligibilityResult::okWithoutItems();
    }

    // -----------------------------------------------------------------
    // Rule 2 — per-item
    // -----------------------------------------------------------------

    /**
     * @param list<int> $requestedOrderItemIds
     */
    private function checkItems(Order $order, array $requestedOrderItemIds): ReturnEligibilityResult
    {
        // Build an id-keyed map of the order's items for O(1) lookup.
        $itemsById = [];
        foreach ($order->getItems() as $item) {
            $id = $item->getId();
            if ($id !== null) {
                $itemsById[$id] = $item;
            }
        }

        $resolved = [];
        foreach ($requestedOrderItemIds as $itemId) {
            if (!isset($itemsById[$itemId])) {
                return ReturnEligibilityResult::failure(
                    'RETURN_ITEM_NOT_IN_ORDER',
                    "Order item {$itemId} does not belong to this order.",
                );
            }
            $item = $itemsById[$itemId];
            $status = $item->getItemStatus();

            if ($status !== OrderItem::ITEM_STATUS_DELIVERED) {
                return ReturnEligibilityResult::failure(
                    'RETURN_ITEM_NOT_DELIVERED',
                    "Order item {$itemId} is not eligible for return "
                    . "(status '{$status}'; must be 'delivered').",
                );
            }

            $resolved[] = $item;
        }

        return ReturnEligibilityResult::ok($resolved);
    }
}
