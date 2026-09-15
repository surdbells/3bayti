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
 * GET /v3/admin/ai/analytics — the Ain concierge BI panel.
 *
 * Reads the ai_interactions + ai_events ledger to answer BOTH "how is Ain being
 * used?" and, crucially, "how much revenue did it generate?":
 *   - volume (queries, unique users/sessions) + a daily sparkline
 *   - funnel counts (opened → recommendations shown → product clicked → added to
 *     cart) with click-through + add-to-cart rates
 *   - top intents (product type / occasion / colour) from the parsed intent JSONB
 *   - most-recommended products (unnested from result_product_ids)
 *   - AI-assisted orders + revenue via an attribution join: an order counts as
 *     Ain-assisted when it contains a product the same user added to cart from
 *     Ain (ai_product_added_to_cart) within the attribution window before it.
 *
 * The window is selected exactly like GetAdminInsightsController (from&to →
 * period=current_month → days=N), so the portal can reuse its date controls.
 */
final class GetAdminAiAnalyticsController
{
    use Responder;

    /** Committed-sale statuses, matching GetAdminInsightsController. */
    private const SALE = "'paid', 'fulfilling', 'shipped', 'delivered'";

    /** How long after an Ain add-to-cart an order still counts as AI-assisted. */
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
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $conn = $this->em->getConnection();
        $now = new \DateTimeImmutable('now');
        $fmt = static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d H:i:sP');

        [$startDt, $endDt, $mode] = $this->resolveWindow($request->getQueryParams(), $now);
        $start = $fmt($startDt);
        $end = $fmt($endDt);
        $days = max(1, (int) ceil(max(1, $endDt->getTimestamp() - $startDt->getTimestamp()) / 86400));

        // ── Volume ────────────────────────────────────────────────────────────
        $queries = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM ai_interactions WHERE created_at >= :s AND created_at < :e',
            ['s' => $start, 'e' => $end],
        );
        $uniqueUsers = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT user_id) FROM ai_interactions WHERE created_at >= :s AND created_at < :e AND user_id IS NOT NULL',
            ['s' => $start, 'e' => $end],
        );
        $uniqueSessions = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT session_id) FROM ai_interactions WHERE created_at >= :s AND created_at < :e AND session_id IS NOT NULL',
            ['s' => $start, 'e' => $end],
        );

        // ── Funnel (event counts) ─────────────────────────────────────────────
        $eventRows = $conn->fetchAllAssociative(
            'SELECT event, COUNT(*) AS c FROM ai_events WHERE created_at >= :s AND created_at < :e GROUP BY event',
            ['s' => $start, 'e' => $end],
        );
        $byEvent = [];
        foreach ($eventRows as $r) {
            $byEvent[(string) $r['event']] = (int) $r['c'];
        }
        $opened = $byEvent['ai_opened'] ?? 0;
        $recommended = $byEvent['ai_product_recommendation_displayed'] ?? 0;
        $clicks = $byEvent['ai_product_clicked'] ?? 0;
        $addToCart = $byEvent['ai_product_added_to_cart'] ?? 0;

        $rate = static fn (int $num, int $den): float => $den > 0 ? round($num / $den, 4) : 0.0;

        // ── Top intents ───────────────────────────────────────────────────────
        $topProductTypes = $this->rankLabels($conn, $start, $end, "intent->>'product_type'", scalar: true);
        $topOccasions = $this->rankLabels($conn, $start, $end, "intent->'occasions'", scalar: false);
        $topColours = $this->rankLabels($conn, $start, $end, "intent->'colours'", scalar: false);

        // ── Most-recommended products ─────────────────────────────────────────
        $mostRecommended = $conn->fetchAllAssociative(
            "SELECT p.id AS product_id, p.name, p.slug, x.c AS count
             FROM (
                 SELECT (pid)::bigint AS product_id, COUNT(*) AS c
                 FROM ai_interactions ai
                 CROSS JOIN LATERAL jsonb_array_elements_text(
                     CASE WHEN jsonb_typeof(ai.result_product_ids) = 'array' THEN ai.result_product_ids ELSE '[]'::jsonb END
                 ) AS pid
                 WHERE ai.created_at >= :s AND ai.created_at < :e AND pid ~ '^[0-9]+$'
                 GROUP BY (pid)::bigint
             ) x
             JOIN products p ON p.id = x.product_id
             ORDER BY x.c DESC, p.id ASC
             LIMIT 12",
            ['s' => $start, 'e' => $end],
        );
        $mostRecommended = array_map(
            static fn (array $r): array => [
                'product_id' => (int) $r['product_id'],
                'name' => (string) $r['name'],
                'slug' => (string) $r['slug'],
                'count' => (int) $r['count'],
            ],
            $mostRecommended,
        );

        // ── AI-assisted orders + revenue (attribution window) ─────────────────
        $sale = self::SALE;
        $winDays = self::ATTRIBUTION_DAYS;
        $assisted = $conn->fetchAssociative(
            "WITH attributed AS (
                 SELECT DISTINCT o.id, o.total
                 FROM orders o
                 JOIN order_items oi ON oi.order_id = o.id
                 JOIN ai_events e ON e.event = 'ai_product_added_to_cart'
                      AND e.user_id = o.user_id
                      AND e.product_id = oi.product_id
                      AND e.created_at <= o.created_at
                      AND e.created_at >= o.created_at - INTERVAL '{$winDays} days'
                 WHERE o.status IN ($sale) AND o.created_at >= :s AND o.created_at < :e
             )
             SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue FROM attributed",
            ['s' => $start, 'e' => $end],
        );
        $assistedOrders = (int) ($assisted['orders'] ?? 0);
        $assistedRevenue = (float) ($assisted['revenue'] ?? 0);

        // ── Daily query-volume series (gap-filled, oldest→newest) ─────────────
        $seriesRows = $conn->fetchAllAssociative(
            "SELECT to_char(date_trunc('day', created_at), 'YYYY-MM-DD') AS d, COUNT(*) AS v
             FROM ai_interactions WHERE created_at >= :s AND created_at < :e GROUP BY d",
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

        return $this->ok([
            'range_days' => $days,
            'range_mode' => $mode,
            'range_start' => $startDt->format('Y-m-d'),
            'range_end' => $endDt->modify('-1 second')->format('Y-m-d'),
            'attribution_window_days' => self::ATTRIBUTION_DAYS,
            'totals' => [
                'queries' => $queries,
                'unique_users' => $uniqueUsers,
                'unique_sessions' => $uniqueSessions,
                'opened' => $opened,
                'recommendations_shown' => $recommended,
                'product_clicks' => $clicks,
                'add_to_cart' => $addToCart,
                'ai_assisted_orders' => $assistedOrders,
                'ai_assisted_revenue' => $assistedRevenue,
            ],
            'rates' => [
                // clicks per query, and per recommendation shown, so the panel can
                // show engagement against both denominators.
                'click_through_per_query' => $rate($clicks, $queries),
                'add_to_cart_per_query' => $rate($addToCart, $queries),
                'click_through_per_recommendation' => $rate($clicks, $recommended),
            ],
            'top_product_types' => $topProductTypes,
            'top_occasions' => $topOccasions,
            'top_colours' => $topColours,
            'most_recommended' => $mostRecommended,
            'query_series' => $series,
        ]);
    }

    /**
     * Top-N labels (with counts) from an intent JSONB expression. `scalar` picks
     * a plain text field (product_type); otherwise the expression is a JSON array
     * (occasions/colours) and is unnested. Guarded so a non-array value is skipped
     * rather than erroring.
     *
     * @return list<array{label: string, count: int}>
     */
    private function rankLabels(
        \Doctrine\DBAL\Connection $conn,
        string $start,
        string $end,
        string $expr,
        bool $scalar,
    ): array {
        if ($scalar) {
            $sql = "SELECT {$expr} AS label, COUNT(*) AS c
                    FROM ai_interactions
                    WHERE created_at >= :s AND created_at < :e
                      AND {$expr} IS NOT NULL AND {$expr} <> ''
                    GROUP BY label ORDER BY c DESC, label ASC LIMIT 10";
        } else {
            $sql = "SELECT val AS label, COUNT(*) AS c
                    FROM ai_interactions ai
                    CROSS JOIN LATERAL jsonb_array_elements_text(
                        CASE WHEN jsonb_typeof({$expr}) = 'array' THEN {$expr} ELSE '[]'::jsonb END
                    ) AS val
                    WHERE ai.created_at >= :s AND ai.created_at < :e AND val <> ''
                    GROUP BY val ORDER BY c DESC, val ASC LIMIT 10";
        }

        $rows = $conn->fetchAllAssociative($sql, ['s' => $start, 'e' => $end]);
        return array_map(
            static fn (array $r): array => ['label' => (string) $r['label'], 'count' => (int) $r['c']],
            $rows,
        );
    }

    /**
     * Resolve the window [start, end) from the query params. Copied from
     * GetAdminInsightsController so the AI panel shares the exact date semantics
     * (from&to → period=current_month → days=N).
     *
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
