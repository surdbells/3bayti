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
 * GET /v3/admin/hotlinks/analytics   (hotlinks.view)
 *
 * Store-hotlink performance: clicks, conversions + revenue (a click's user
 * ordering within the attribution window — the same event-window join the AI
 * revenue panel uses), the top-performing links, and a daily click series.
 * The window is selected exactly like the AI/insights panels (from&to →
 * period=current_month → days=N) so the portal reuses its date controls.
 */
final class GetAdminHotlinkAnalyticsController
{
    use Responder;

    /** Committed-sale statuses, matching the other analytics panels. */
    private const SALE = "'paid', 'fulfilling', 'shipped', 'delivered'";

    /** How long after a hotlink click an order still counts as attributed. */
    private const ATTRIBUTION_DAYS = 7;

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
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $conn = $this->em->getConnection();
        $now = new \DateTimeImmutable('now');
        $fmt = static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d H:i:sP');

        [$startDt, $endDt, $mode] = $this->resolveWindow($request->getQueryParams(), $now);
        $start = $fmt($startDt);
        $end = $fmt($endDt);
        $days = max(1, (int) ceil(max(1, $endDt->getTimestamp() - $startDt->getTimestamp()) / 86400));
        $sale = self::SALE;
        $winDays = self::ATTRIBUTION_DAYS;

        // ── Totals ────────────────────────────────────────────────────────────
        $hotlinksTotal = (int) $conn->fetchOne('SELECT COUNT(*) FROM hotlinks');
        $clicks = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM hotlink_clicks WHERE created_at >= :s AND created_at < :e',
            ['s' => $start, 'e' => $end],
        );
        $uniqueClickers = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT user_id) FROM hotlink_clicks WHERE created_at >= :s AND created_at < :e AND user_id IS NOT NULL',
            ['s' => $start, 'e' => $end],
        );
        $uniqueSessions = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT session_id) FROM hotlink_clicks WHERE created_at >= :s AND created_at < :e AND session_id IS NOT NULL',
            ['s' => $start, 'e' => $end],
        );

        // ── Attributed orders + revenue ────────────────────────────────────────
        // Anchored on the CLICK falling inside the window (order up to N days
        // after), so this reconciles with both `clicks` above and the per-link
        // conversions below — all three count the same window-of-clicks
        // population. (An order-anchored window would silently disagree with the
        // top_links panel at the boundaries.)
        $attributed = $conn->fetchAssociative(
            "WITH attributed AS (
                 SELECT DISTINCT o.id, o.total
                 FROM orders o
                 JOIN hotlink_clicks c ON c.user_id = o.user_id
                      AND o.created_at >= c.created_at
                      AND o.created_at <= c.created_at + INTERVAL '{$winDays} days'
                 WHERE o.status IN ($sale) AND c.created_at >= :s AND c.created_at < :e
             )
             SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue FROM attributed",
            ['s' => $start, 'e' => $end],
        );
        $attributedOrders = (int) ($attributed['orders'] ?? 0);
        $attributedRevenue = (float) ($attributed['revenue'] ?? 0);

        // ── Top links (by clicks in window, with per-link conversions) ──────────
        $topRows = $conn->fetchAllAssociative(
            "SELECT h.code, h.target_type, h.target_slug,
                 (SELECT COUNT(*) FROM hotlink_clicks c
                    WHERE c.hotlink_id = h.id AND c.created_at >= :s AND c.created_at < :e) AS clicks,
                 (SELECT COUNT(DISTINCT o.id) FROM hotlink_clicks c3
                    JOIN orders o ON o.user_id = c3.user_id
                         AND o.created_at >= c3.created_at
                         AND o.created_at <= c3.created_at + INTERVAL '{$winDays} days'
                    WHERE c3.hotlink_id = h.id AND o.status IN ($sale)
                      AND c3.created_at >= :s AND c3.created_at < :e) AS conversions
             FROM hotlinks h
             WHERE EXISTS (SELECT 1 FROM hotlink_clicks c2
                 WHERE c2.hotlink_id = h.id AND c2.created_at >= :s AND c2.created_at < :e)
             ORDER BY clicks DESC, h.id ASC
             LIMIT 12",
            ['s' => $start, 'e' => $end],
        );
        $topLinks = array_map(
            static fn (array $r): array => [
                'code' => (string) $r['code'],
                'target_type' => (string) $r['target_type'],
                'target_slug' => (string) $r['target_slug'],
                'clicks' => (int) $r['clicks'],
                'conversions' => (int) $r['conversions'],
            ],
            $topRows,
        );

        // ── Daily click series (gap-filled, oldest→newest) ──────────────────────
        $seriesRows = $conn->fetchAllAssociative(
            "SELECT to_char(date_trunc('day', created_at), 'YYYY-MM-DD') AS d, COUNT(*) AS v
             FROM hotlink_clicks WHERE created_at >= :s AND created_at < :e GROUP BY d",
            ['s' => $start, 'e' => $end],
        );
        $byDay = [];
        foreach ($seriesRows as $r) {
            $byDay[(string) $r['d']] = (int) $r['v'];
        }
        $series = [];
        $cursor = $startDt->setTime(0, 0, 0);
        for ($guard = 0; $cursor < $endDt && $guard < 400; $guard++) {
            $series[] = $byDay[$cursor->format('Y-m-d')] ?? 0;
            $cursor = $cursor->modify('+1 day');
        }

        $rate = static fn (int $num, int $den): float => $den > 0 ? round($num / $den, 4) : 0.0;

        return $this->ok([
            'range_days' => $days,
            'range_mode' => $mode,
            'range_start' => $startDt->format('Y-m-d'),
            'range_end' => $endDt->modify('-1 second')->format('Y-m-d'),
            'attribution_window_days' => self::ATTRIBUTION_DAYS,
            'totals' => [
                'hotlinks_total' => $hotlinksTotal,
                'clicks' => $clicks,
                'unique_clickers' => $uniqueClickers,
                'unique_sessions' => $uniqueSessions,
                'attributed_orders' => $attributedOrders,
                'attributed_revenue' => $attributedRevenue,
            ],
            'rates' => [
                'conversions_per_click' => $rate($attributedOrders, $clicks),
            ],
            'top_links' => $topLinks,
            'click_series' => $series,
        ]);
    }

    /**
     * @param array<string,mixed> $params
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string}
     */
    private function resolveWindow(array $params, \DateTimeImmutable $now): array
    {
        $from = isset($params['from']) ? $this->parseDate((string) $params['from']) : null;
        $to = isset($params['to']) ? $this->parseDate((string) $params['to']) : null;
        if ($from !== null && $to !== null) {
            if ($to < $from) {
                [$from, $to] = [$to, $from];
            }
            $start = $from->setTime(0, 0, 0);
            $end = $to->setTime(0, 0, 0)->modify('+1 day');
            $maxEnd = $start->modify('+366 days');
            if ($end > $maxEnd) {
                $end = $maxEnd;
            }
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

        $daysParam = (int) ($params['days'] ?? 30);
        $daysParam = max(1, min(365, $daysParam));
        return [$now->modify("-{$daysParam} days"), $now, 'days'];
    }

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
