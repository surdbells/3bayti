<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin;

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
 * GET /v3/admin/analytics?days=30
 *
 * Platform-wide analytics dashboard for admin users.
 * Returns KPI totals + monthly series matching the portal admin
 * dashboard component's expected shape:
 *
 *   total_products       int   — live product count
 *   total_orders         int   — orders in window
 *   products_sold        int   — items sold in window
 *   return_orders        int   — returned/refunded orders in window
 *   total_products_stats int[] — 12-month product listing counts
 *   total_orders_stats   int[] — 12-month order counts
 *   products_sold_stats  int[] — 12-month items sold
 *   return_orders_stats  int[] — 12-month return counts
 *   data                 array — 20 most recent orders (lightweight)
 *
 * Authorization: admin role enforced by route group middleware.
 */
final class GetAdminPlatformAnalyticsController
{
    use Responder;

    /**
     * Order statuses that represent a completed/paid sale. Mirrors the
     * Order::STATUS_* set used elsewhere in the codebase. Anything outside
     * this set (pending_payment, failed, cancelled, refunded) is NOT counted
     * toward order/units KPIs.
     *
     * @var list<string>
     */
    private const SALE_STATUSES = [
        \Bayti\Api\Domain\Order\Order::STATUS_PAID,
        \Bayti\Api\Domain\Order\Order::STATUS_FULFILLING,
        \Bayti\Api\Domain\Order\Order::STATUS_SHIPPED,
        \Bayti\Api\Domain\Order\Order::STATUS_DELIVERED,
    ];

    /** Lowercase DB status → title-case label the badge expects. */
    private const STATUS_LABELS = [
        'pending_payment' => 'Pending Payment',
        'paid'            => 'Paid',
        'fulfilling'      => 'Fulfilling',
        'shipped'         => 'Shipped',
        'delivered'       => 'Delivered',
        'cancelled'       => 'Cancelled',
        'refunded'        => 'Refunded',
        'failed'          => 'Failed',
    ];

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        /** @var array<string, mixed> $query */
        $query   = $request->getQueryParams();
        $days    = $this->parseWindowDays($query['days'] ?? null);
        $conn    = $this->em->getConnection();
        $now     = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $since   = $now->modify("-{$days} days")->format('Y-m-d H:i:s');
        $year    = (int) $now->format('Y');

        // Completed/paid status set as an inline SQL placeholder list, e.g.
        // "?,?,?,?" — bound positionally alongside the other params.
        $saleStatuses     = self::SALE_STATUSES;
        $salePlaceholders = implode(',', array_fill(0, count($saleStatuses), '?'));

        // ── KPI totals ─────────────────────────────────────────────
        $totalProducts = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM products WHERE status = 'active'"
        );

        // Restrict order/units KPIs to completed/paid statuses so they reflect
        // actual sales, not pending_payment/failed/cancelled/refunded noise.
        $totalOrders = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM orders
             WHERE created_at >= ?
               AND status IN ($salePlaceholders)
               AND NOT EXISTS (
                 SELECT 1 FROM gift_cards gc
                 WHERE gc.purchase_order_reference = orders.order_reference
               )",
            [$since, ...$saleStatuses]
        );

        $productsSold = (int) ($conn->fetchOne(
            "SELECT COALESCE(SUM(oi.quantity), 0)
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.created_at >= ?
               AND o.status IN ($salePlaceholders)",
            [$since, ...$saleStatuses]
        ) ?: 0);

        $returnOrders = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM orders
             WHERE status IN ('refunded','cancelled')
               AND created_at >= ?",
            [$since]
        );

        // ── 12-month series (Jan–Dec of current year) ───────────────
        $ordersBy12Month = array_fill(0, 12, 0);
        $soldBy12Month   = array_fill(0, 12, 0);
        $returnBy12Month = array_fill(0, 12, 0);
        $productBy12Month = array_fill(0, 12, 0);

        $orderRows = $conn->fetchAllAssociative(
            "SELECT
               EXTRACT(MONTH FROM created_at)::int - 1 AS m,
               COUNT(*)                                  AS cnt,
               COALESCE(SUM(CASE WHEN status IN ('refunded','cancelled') THEN 1 ELSE 0 END), 0) AS returns
             FROM orders
             WHERE EXTRACT(YEAR FROM created_at) = ?
               AND NOT EXISTS (
                 SELECT 1 FROM gift_cards gc
                 WHERE gc.purchase_order_reference = orders.order_reference
               )
             GROUP BY m
             ORDER BY m",
            [$year]
        );
        foreach ($orderRows as $row) {
            $m = (int) $row['m'];
            if ($m >= 0 && $m < 12) {
                $ordersBy12Month[$m] = (int) $row['cnt'];
                $returnBy12Month[$m] = (int) $row['returns'];
            }
        }

        $soldRows = $conn->fetchAllAssociative(
            "SELECT
               EXTRACT(MONTH FROM o.created_at)::int - 1 AS m,
               COALESCE(SUM(oi.quantity), 0)              AS qty
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE EXTRACT(YEAR FROM o.created_at) = ?
             GROUP BY m
             ORDER BY m",
            [$year]
        );
        foreach ($soldRows as $row) {
            $m = (int) $row['m'];
            if ($m >= 0 && $m < 12) {
                $soldBy12Month[$m] = (int) $row['qty'];
            }
        }

        $productRows = $conn->fetchAllAssociative(
            "SELECT
               EXTRACT(MONTH FROM created_at)::int - 1 AS m,
               COUNT(*)                                  AS cnt
             FROM products
             WHERE EXTRACT(YEAR FROM created_at) = ?
               AND status = 'active'
             GROUP BY m
             ORDER BY m",
            [$year]
        );
        foreach ($productRows as $row) {
            $m = (int) $row['m'];
            if ($m >= 0 && $m < 12) {
                $productBy12Month[$m] = (int) $row['cnt'];
            }
        }

        // ── Recent orders (paginated, optional ?status= filter) ─────
        // Each row is per-order. An order can have many line items, so we
        // surface the DOMINANT line — the item with the greatest quantity
        // (ties broken by highest subtotal) — as the representative
        // product_name + store_name, and sum the quantity across ALL items
        // for the order's total units. This keeps one row per order while
        // still giving a meaningful product/store at a glance.
        [$page, $limit] = $this->parsePagination($query);
        $offset         = $page * $limit;

        $statusFilter = $this->parseStatusFilter($query['status'] ?? null);

        // Build WHERE clause + bound params shared by count + page queries.
        // Gift-card *purchase* orders (synthetic, no line items, linked via
        // gift_cards.purchase_order_reference) must never appear as recent
        // sales, so the exclusion is ALWAYS present — the optional status
        // filter is ANDed on top.
        $giftCardExclusion =
            'NOT EXISTS ('
            . 'SELECT 1 FROM gift_cards gc '
            . 'WHERE gc.purchase_order_reference = o.order_reference'
            . ')';
        $where  = "WHERE {$giftCardExclusion}";
        $params = [];
        if ($statusFilter !== null) {
            $where   .= ' AND o.status = ?';
            $params[] = $statusFilter;
        }

        $recentTotal = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM orders o {$where}",
            $params
        );

        // Dominant line item per order via a LATERAL pick (Postgres).
        $recentRows = $conn->fetchAllAssociative(
            "SELECT
               o.id,
               o.order_reference                   AS order_ref,
               o.status,
               o.total                             AS total_price,
               o.currency,
               o.created_at                        AS added,
               u.first_name || ' ' || u.last_name  AS customer_name,
               u.email                             AS customer_email,
               COALESCE(dom.product_name_snapshot, '—') AS product_name,
               COALESCE(v.name, '—')               AS store_name,
               COALESCE(q.total_qty, 0)::int       AS quantity
             FROM orders o
             LEFT JOIN users u ON u.id = o.user_id
             LEFT JOIN LATERAL (
               SELECT oi.product_name_snapshot, oi.vendor_id
               FROM order_items oi
               WHERE oi.order_id = o.id
               ORDER BY oi.quantity DESC, oi.subtotal DESC
               LIMIT 1
             ) dom ON true
             LEFT JOIN vendors v ON v.id = dom.vendor_id
             LEFT JOIN LATERAL (
               SELECT COALESCE(SUM(oi.quantity), 0) AS total_qty
               FROM order_items oi
               WHERE oi.order_id = o.id
             ) q ON true
             {$where}
             ORDER BY o.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        // Normalize status to the title-case label the badge expects — done
        // in exactly ONE place so the template binds against a stable form.
        foreach ($recentRows as &$row) {
            $row['status'] = self::STATUS_LABELS[$row['status']] ?? ucfirst((string) $row['status']);
        }
        unset($row);

        // ── Top-selling products (by units sold in window) ──────────
        // Parity with legacy admin/common/top-selling.php — a ranked
        // product list for the admin dashboard.
        $topProducts = $conn->fetchAllAssociative(
            "SELECT
               p.id,
               p.name,
               p.slug,
               p.primary_image_url                              AS image,
               p.price                                          AS price,
               v.name                                           AS store_name,
               cat.name                                         AS category_name,
               COALESCE(SUM(oi.quantity), 0)::int               AS units_sold,
               COUNT(DISTINCT oi.order_id)::int                 AS orders_count,
               COALESCE(SUM(oi.unit_price * oi.quantity), 0)    AS revenue
             FROM order_items oi
             JOIN products p        ON p.id = oi.product_id
             JOIN orders o          ON o.id = oi.order_id
             LEFT JOIN vendors v    ON v.id = p.vendor_id
             LEFT JOIN categories cat ON cat.id = p.category_id
             WHERE o.created_at >= ?
               AND o.status IN ($salePlaceholders)
             GROUP BY p.id, p.name, p.slug, p.primary_image_url, p.price, v.name, cat.name
             ORDER BY units_sold DESC, revenue DESC
             LIMIT 8",
            [$since, ...$saleStatuses]
        );

        return $this->ok([
            'response_code'       => 200,
            'status'              => 'success',
            'message'             => count($recentRows),
            'total_products'      => $totalProducts,
            'total_orders'        => $totalOrders,
            'products_sold'       => $productsSold,
            'return_orders'       => $returnOrders,
            'total_products_stats'=> array_values($productBy12Month),
            'total_orders_stats'  => array_values($ordersBy12Month),
            'products_sold_stats' => array_values($soldBy12Month),
            'return_orders_stats' => array_values($returnBy12Month),
            'top_products'        => $topProducts,
            'data'                => $recentRows,
            // Pagination metadata for the recent-orders list (server-driven).
            'recent_total'        => $recentTotal,
            'page'                => $page,
            'limit'               => $limit,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0:int,1:int} [pageIndex (0-based), limit]
     */
    private function parsePagination(array $query): array
    {
        $page = 0;
        if (isset($query['page']) && (is_string($query['page']) || is_int($query['page']))) {
            $page = max(0, (int) ((string) $query['page']));
        }

        $limit = 10;
        if (isset($query['limit']) && (is_string($query['limit']) || is_int($query['limit']))) {
            $limit = (int) ((string) $query['limit']);
        }
        $limit = max(1, min(100, $limit));

        return [$page, $limit];
    }

    /**
     * Validate the optional ?status= filter against the known status set.
     * Returns null when absent/blank/unknown (i.e. "all statuses").
     */
    private function parseStatusFilter(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $val = strtolower(trim($raw));
        if ($val === '' || $val === 'all') {
            return null;
        }
        return array_key_exists($val, self::STATUS_LABELS) ? $val : null;
    }

    private function parseWindowDays(mixed $raw): int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return 30;
        }
        $days = (int) ((string) $raw);
        return max(7, min(365, $days));
    }
}
