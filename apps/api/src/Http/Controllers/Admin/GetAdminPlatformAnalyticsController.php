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

        // ── KPI totals ─────────────────────────────────────────────
        $totalProducts = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM products WHERE status = 'active'"
        );

        $totalOrders = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM orders WHERE created_at >= ?",
            [$since]
        );

        $productsSold = (int) ($conn->fetchOne(
            "SELECT COALESCE(SUM(oi.quantity), 0)
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.created_at >= ?",
            [$since]
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

        // ── Recent orders (20 most recent) ──────────────────────────
        $recentRows = $conn->fetchAllAssociative(
            "SELECT
               o.id,
               o.order_reference,
               o.status,
               o.total,
               o.currency,
               o.created_at,
               u.first_name || ' ' || u.last_name AS customer_name,
               u.email                             AS customer_email
             FROM orders o
             LEFT JOIN users u ON u.id = o.user_id
             ORDER BY o.created_at DESC
             LIMIT 20"
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
            'data'                => $recentRows,
        ]);
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
