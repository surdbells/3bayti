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
 * GET /v3/admin/insights?days=N
 *
 * Period-over-period dashboard insight: revenue / orders / units / new
 * customers for the last N days each with the prior N-day window (for
 * ▲/▼ deltas), a daily revenue series (sparkline), the order status
 * breakdown, and an "at risk" count (unpaid + stuck-in-fulfilment).
 * N is clamped to 1..365 (the UI offers 7/30/90).
 */
final class GetAdminInsightsController
{
    use Responder;

    /** Order statuses that count as a committed sale. */
    private const SALE = "'paid', 'fulfilling', 'shipped', 'delivered'";

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

        $days = (int) ($request->getQueryParams()['days'] ?? 30);
        $days = max(1, min(365, $days));

        $conn = $this->em->getConnection();
        $now = new \DateTimeImmutable('now');
        $fmt = static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d H:i:sP');

        $curStart  = $fmt($now->modify("-{$days} days"));
        $curEnd    = $fmt($now);
        $prevStart = $fmt($now->modify('-' . ($days * 2) . ' days'));
        $prevEnd   = $curStart;

        $sale = self::SALE;
        $revenue = static fn (string $s, string $e): float => (float) $conn->fetchOne(
            "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status IN ($sale) AND created_at >= :s AND created_at < :e",
            ['s' => $s, 'e' => $e],
        );
        $ordersCount = static fn (string $s, string $e): int => (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM orders WHERE status IN ($sale) AND created_at >= :s AND created_at < :e",
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
             FROM orders WHERE status IN ($sale) AND created_at >= :s AND created_at < :e
             GROUP BY d",
            ['s' => $curStart, 'e' => $curEnd],
        );
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(string) $r['d']] = (float) $r['v'];
        }
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $now->modify("-{$i} days")->format('Y-m-d');
            $series[] = $byDay[$day] ?? 0.0;
        }

        // Order status breakdown over the window.
        $statusRows = $conn->fetchAllAssociative(
            'SELECT status, COUNT(*) AS c FROM orders WHERE created_at >= :s AND created_at < :e GROUP BY status ORDER BY c DESC',
            ['s' => $curStart, 'e' => $curEnd],
        );
        $salesByStatus = array_map(
            static fn (array $r): array => ['status' => (string) $r['status'], 'count' => (int) $r['c']],
            $statusRows,
        );

        // At-risk (not window-bound — these need attention whenever they exist).
        $pendingPayment = (int) $conn->fetchOne("SELECT COUNT(*) FROM orders WHERE status = 'pending_payment'");
        $stuck = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM orders WHERE status = 'fulfilling' AND created_at < :cut",
            ['cut' => $fmt($now->modify('-' . self::STUCK_DAYS . ' days'))],
        );

        return $this->ok([
            'range_days'      => $days,
            'kpis'            => $kpis,
            'revenue_series'  => $series,
            'sales_by_status' => $salesByStatus,
            'at_risk'         => ['pending_payment' => $pendingPayment, 'stuck_fulfilling' => $stuck],
        ]);
    }
}
