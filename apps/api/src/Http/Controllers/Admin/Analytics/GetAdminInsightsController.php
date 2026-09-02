<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Analytics;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/insights
 *
 * Period-over-period dashboard insight: revenue / orders / units / new
 * customers for a window, each with the equally-long immediately-preceding
 * window (for ▲/▼ deltas), a daily revenue series (sparkline), the order
 * status breakdown, and an "at risk" count (unpaid + stuck-in-fulfilment).
 *
 * The window is selected via (in precedence order):
 *   - from=YYYY-MM-DD&to=YYYY-MM-DD  → a custom date range (both days inclusive,
 *     span clamped to 366 days)
 *   - period=current_month           → 1st of the current month 00:00 → now
 *   - days=N                         → the last N days (default 30, 1..365;
 *     the UI offers 7/30/90)
 */
final class GetAdminInsightsController
{
    use Responder;

    /** Order statuses that count as a committed sale. */
    private const SALE = "'paid', 'fulfilling', 'shipped', 'delivered'";

    /**
     * Exclude synthetic gift-card PURCHASE orders, they aren't product sales
     * (no order_items, so they'd inflate the order count but not units). Mirrors
     * GetAdminPlatformAnalyticsController so the dashboard's two order KPIs agree.
     * Requires the orders table to be referenced/aliased as `orders`.
     */
    private const NO_GIFT_CARDS =
        'AND NOT EXISTS (SELECT 1 FROM gift_cards gc WHERE gc.purchase_order_reference = orders.order_reference)';

    /** An order sitting in 'fulfilling' longer than this needs attention. */
    private const STUCK_DAYS = 3;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $conn = $this->em->getConnection();
        $now = new \DateTimeImmutable('now');
        $fmt = static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d H:i:sP');

        // Resolve the current window [start, end) from days= / period= / from&to.
        [$curStartDt, $curEndDt, $mode] = $this->resolveWindow($request->getQueryParams(), $now);

        // Prior window of the same length, immediately before, for ▲/▼ deltas.
        $lenSeconds  = max(1, $curEndDt->getTimestamp() - $curStartDt->getTimestamp());
        $prevStartDt = $curStartDt->modify("-{$lenSeconds} seconds");

        $curStart  = $fmt($curStartDt);
        $curEnd    = $fmt($curEndDt);
        $prevStart = $fmt($prevStartDt);
        $prevEnd   = $curStart;

        // Whole days spanned, for the response caption.
        $days = max(1, (int) ceil($lenSeconds / 86400));

        $sale = self::SALE;
        $noGiftCards = self::NO_GIFT_CARDS;
        $revenue = static fn (string $s, string $e): float => (float) $conn->fetchOne(
            "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status IN ($sale) AND created_at >= :s AND created_at < :e $noGiftCards",
            ['s' => $s, 'e' => $e],
        );
        $ordersCount = static fn (string $s, string $e): int => (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM orders WHERE status IN ($sale) AND created_at >= :s AND created_at < :e $noGiftCards",
            ['s' => $s, 'e' => $e],
        );
        $units = static fn (string $s, string $e): int => (int) $conn->fetchOne(
            "SELECT COUNT(oi.id) FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE o.status IN ($sale) AND o.created_at >= :s AND o.created_at < :e",
            ['s' => $s, 'e' => $e],
        );
        $newCustomers = static fn (string $s, string $e): int => (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM users WHERE created_at >= :s AND created_at < :e',
            ['s' => $s, 'e' => $e],
        );

        $kpis = [
            'revenue'       => ['value' => $revenue($curStart, $curEnd),       'prev' => $revenue($prevStart, $prevEnd)],
            'orders'        => ['value' => $ordersCount($curStart, $curEnd),   'prev' => $ordersCount($prevStart, $prevEnd)],
            'units_sold'    => ['value' => $units($curStart, $curEnd),         'prev' => $units($prevStart, $prevEnd)],
            'new_customers' => ['value' => $newCustomers($curStart, $curEnd),  'prev' => $newCustomers($prevStart, $prevEnd)],
        ];

        // Daily revenue series (gap-filled to exactly $days points, oldest→newest).
        $rows = $conn->fetchAllAssociative(
            "SELECT to_char(date_trunc('day', created_at), 'YYYY-MM-DD') AS d, COALESCE(SUM(total), 0) AS v
             FROM orders WHERE status IN ($sale) AND created_at >= :s AND created_at < :e $noGiftCards
             GROUP BY d",
            ['s' => $curStart, 'e' => $curEnd],
        );
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(string) $r['d']] = (float) $r['v'];
        }
        // One gap-filled point per calendar day in the window (oldest→newest).
        // Guarded at 400 iterations (the custom-range span is clamped to 366).
        $series = [];
        $cursor = $curStartDt->setTime(0, 0, 0);
        for ($guard = 0; $cursor < $curEndDt && $guard < 400; $guard++) {
            $series[] = $byDay[$cursor->format('Y-m-d')] ?? 0.0;
            $cursor = $cursor->modify('+1 day');
        }

        // Order status breakdown over the window.
        $statusRows = $conn->fetchAllAssociative(
            "SELECT status, COUNT(*) AS c FROM orders WHERE created_at >= :s AND created_at < :e $noGiftCards GROUP BY status ORDER BY c DESC",
            ['s' => $curStart, 'e' => $curEnd],
        );
        $salesByStatus = array_map(
            static fn (array $r): array => ['status' => (string) $r['status'], 'count' => (int) $r['c']],
            $statusRows,
        );

        // At-risk (not window-bound, these need attention whenever they exist).
        $pendingPayment = (int) $conn->fetchOne("SELECT COUNT(*) FROM orders WHERE status = 'pending_payment'");
        $stuck = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM orders WHERE status = 'fulfilling' AND created_at < :cut",
            ['cut' => $fmt($now->modify('-' . self::STUCK_DAYS . ' days'))],
        );

        return $this->ok([
            'range_days'      => $days,
            'range_mode'      => $mode,
            'range_start'     => $curStartDt->format('Y-m-d'),
            // end is exclusive (start of the day after the last included day);
            // report the last INCLUDED calendar day for display.
            'range_end'       => $curEndDt->modify('-1 second')->format('Y-m-d'),
            'kpis'            => $kpis,
            'revenue_series'  => $series,
            'sales_by_status' => $salesByStatus,
            'at_risk'         => ['pending_payment' => $pendingPayment, 'stuck_fulfilling' => $stuck],
        ]);
    }

    /**
     * Resolve the current window [start, end) from the query params.
     * Precedence: from&to (custom) → period=current_month → days=N.
     *
     * @param array<string,mixed> $params
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string}
     *         [start (inclusive), end (exclusive), mode]
     */
    private function resolveWindow(array $params, \DateTimeImmutable $now): array
    {
        $from = isset($params['from']) ? $this->parseDate((string) $params['from']) : null;
        $to   = isset($params['to']) ? $this->parseDate((string) $params['to']) : null;
        if ($from !== null && $to !== null) {
            if ($to < $from) {
                [$from, $to] = [$to, $from]; // tolerate a swapped range
            }
            $start = $from->setTime(0, 0, 0);
            // Exclusive end = start of the day after `to`, so `to` is included.
            $end = $to->setTime(0, 0, 0)->modify('+1 day');
            // Clamp the span to 366 days to keep the query + series bounded.
            $maxEnd = $start->modify('+366 days');
            if ($end > $maxEnd) {
                $end = $maxEnd;
            }
            // Never let a future end run past "now" for the current bucket.
            if ($end > $now) {
                $end = $now;
            }
            if ($end <= $start) {
                $end = $start->modify('+1 day');
            }
            return [$start, $end, 'custom'];
        }

        $period = isset($params['period']) ? (string) $params['period'] : '';
        if ($period === 'current_month' || $period === 'month') {
            $start = $now->setTime(0, 0, 0)->modify('first day of this month');
            return [$start, $now, 'current_month'];
        }

        $days = (int) ($params['days'] ?? 30);
        $days = max(1, min(365, $days));
        return [$now->modify("-{$days} days"), $now, 'days'];
    }

    /** Parse a strict YYYY-MM-DD date (midnight), or null if invalid. */
    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false ? $d : null;
    }
}
