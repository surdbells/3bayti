<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

/**
 * Computes the refund amount for a return request (M3.2.X.18-C).
 *
 * Per Q-PartialRefundComputation = A locked:
 *
 *   refund_amount = sum(returned_item.line_subtotal)
 *                 - pro_rated_discount
 *
 * where pro_rated_discount = order.discount × (returned_items_subtotal / order.subtotal)
 *
 * The delivery_fee is NOT refunded (industry standard for returns).
 *
 * All arithmetic uses bcmath at DECIMAL(10,2) precision so we don't
 * accumulate float drift on cents.
 *
 * Worked example
 * ==============
 *   Order:
 *     subtotal      = 200.00
 *     delivery_fee  = 25.00
 *     discount      = 20.00  (a 10%-off promo)
 *     total         = 205.00
 *
 *   Customer returns items worth line_subtotal = 80.00 total.
 *
 *   pro_rated_discount = 20.00 × (80.00 / 200.00) = 8.00
 *   refund_amount      = 80.00 − 8.00 = 72.00
 *
 *   Delivery fee not refunded.
 *
 * Edge cases handled
 * ==================
 *   - Zero discount on the order → refund = sum of returned items
 *   - Zero subtotal on the order → defensive: returns sum of returned
 *     items unchanged (a zero-subtotal order shouldn't exist, but we
 *     don't divide-by-zero)
 *   - Returned subtotal exceeds order subtotal → mathematically can't
 *     happen given the per-item line subtotals, but the calculator
 *     clamps pro-rated discount to never exceed the order's discount
 *
 * The calculator is pure, no DB, no DI, no time. Easy to unit-test
 * exhaustively and reused by the admin endpoint (X.18-F) to pre-fill
 * the refund DTO with the suggested amount.
 */
final class ReturnRefundCalculator
{
    /**
     * Compute the suggested refund amount for the given OrderReturnRequest
     * items against the parent Order.
     *
     * @param Order $order The parent order (carries subtotal + discount).
     * @param list<OrderReturnRequestItem> $returnItems Items being returned.
     *
     * @return string A DECIMAL(10,2) money string.
     */
    public function compute(Order $order, array $returnItems): string
    {
        if ($returnItems === []) {
            return '0.00';
        }

        // Sum the line subtotals.
        $returnedSubtotal = '0.00';
        foreach ($returnItems as $item) {
            $returnedSubtotal = bcadd($returnedSubtotal, $item->getLineSubtotal(), 2);
        }

        $orderDiscount = $order->getDiscount();
        $orderSubtotal = $order->getSubtotal();

        // Defensive: zero/empty discount → no pro-ration needed.
        if (bccomp($orderDiscount, '0', 2) <= 0) {
            return $returnedSubtotal;
        }

        // Defensive: zero subtotal, shouldn't happen but don't div/0.
        if (bccomp($orderSubtotal, '0', 2) <= 0) {
            return $returnedSubtotal;
        }

        // Pro-rated discount. Compute at scale=6 then round to 2 to
        // avoid losing cents on the division.
        $ratio = bcdiv($returnedSubtotal, $orderSubtotal, 6);
        $proRated = bcmul($orderDiscount, $ratio, 6);
        $proRated = $this->roundHalfUp($proRated, 2);

        // Belt-and-braces: pro-rated discount can't exceed the order's
        // actual discount.
        if (bccomp($proRated, $orderDiscount, 2) > 0) {
            $proRated = $orderDiscount;
        }

        $refund = bcsub($returnedSubtotal, $proRated, 2);

        // Can't refund negative, but with the clamp above this can't
        // happen unless returnedSubtotal < pro_rated, which itself can
        // only happen if line_subtotals exceed orderSubtotal (also
        // can't happen with normal items). Defensive anyway:
        if (bccomp($refund, '0', 2) < 0) {
            return '0.00';
        }

        return $refund;
    }

    /**
     * Round a bcmath decimal string half-up to the target scale.
     * bcmath truncates by default; we want banker-style rounding.
     */
    private function roundHalfUp(string $value, int $scale): string
    {
        $pad = $scale + 1;
        // Pad to scale+1 digits, then add 0.5*10^-pad and truncate.
        $padded = bcadd($value, '0', $pad);
        $bump = '0.' . str_repeat('0', $scale) . '5';
        return bcadd($padded, $bump, $scale);
    }
}
