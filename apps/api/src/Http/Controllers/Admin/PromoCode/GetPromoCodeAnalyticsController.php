<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\PromoCode;

use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\PromoCodeSerializer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/promo-codes/{id}/analytics   (coupons.view)
 *
 * Admin usage report for ANY promo code (platform-wide or vendor-owned) —
 * the admin analog of VendorCouponAnalyticsController, but resolved by id
 * alone (no vendor scoping). Computed from promo_redemptions.
 *
 * Default (no ?period): a bundle for the report screen —
 *   { coupon, stats, usage_over_time }
 * ?period=usage_log&page=&per_page= → the paginated redemption log
 *   { data, pagination }
 * ?days_back=N tunes the over-time window (default 30, max 365).
 */
final class GetPromoCodeAnalyticsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly PromoCodeSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $idRaw = $args['id'] ?? '';
        if (!ctype_digit((string) $idRaw)) {
            throw HttpException::notFound('Promo code not found.');
        }
        $couponId = (int) $idRaw;

        /** @var PromoCodeRepository $repo */
        $repo = $this->em->getRepository(PromoCode::class);
        $coupon = $repo->find($couponId);
        if ($coupon === null) {
            throw HttpException::notFound('Promo code not found.');
        }

        $conn = $this->em->getConnection();
        $period = (string) ($request->getQueryParams()['period'] ?? '');

        if ($period === 'usage_log') {
            return $this->ok($this->usageLog($conn, $couponId, $request));
        }

        // Default: the report bundle — code + lifetime stats + daily series.
        return $this->ok([
            'data' => [
                'coupon' => $this->serializer->adminShape($coupon),
                'stats' => $this->couponStats($conn, $couponId),
                'usage_over_time' => $this->usageOverTime($conn, $couponId, $request),
            ],
        ]);
    }

    /** This code's lifetime totals. */
    private function couponStats(Connection $conn, int $couponId): array
    {
        $row = $conn->fetchAssociative(
            'SELECT
                COUNT(*)                          AS total_uses,
                COALESCE(SUM(discount_amount), 0) AS total_discount_given,
                COUNT(DISTINCT user_id)           AS unique_customers
             FROM promo_redemptions
             WHERE promo_code_id = ?',
            [$couponId],
        ) ?: [];

        $revenue = $conn->fetchOne(
            'SELECT COALESCE(SUM(o.total), 0)
             FROM promo_redemptions r
             JOIN orders o ON o.id = r.order_id
             WHERE r.promo_code_id = ?',
            [$couponId],
        );

        return [
            'total_uses' => (int) ($row['total_uses'] ?? 0),
            'total_discount_given' => round((float) ($row['total_discount_given'] ?? 0), 2),
            'unique_customers' => (int) ($row['unique_customers'] ?? 0),
            'total_revenue_generated' => round((float) $revenue, 2),
        ];
    }

    /** Daily redemption counts + discount for the last N days (default 30). */
    private function usageOverTime(Connection $conn, int $couponId, ServerRequestInterface $request): array
    {
        $daysBack = max(1, min(365, (int) ($request->getQueryParams()['days_back'] ?? 30)));
        $rows = $conn->fetchAllAssociative(
            "SELECT to_char(date_trunc('day', redeemed_at), 'YYYY-MM-DD') AS day,
                    COUNT(*) AS uses,
                    COALESCE(SUM(discount_amount), 0) AS discount
             FROM promo_redemptions
             WHERE promo_code_id = ?
               AND redeemed_at >= (NOW() - (? || ' days')::interval)
             GROUP BY 1 ORDER BY 1 ASC",
            [$couponId, $daysBack],
        );
        return array_map(static fn (array $r): array => [
            'day' => $r['day'],
            'uses' => (int) $r['uses'],
            'discount' => round((float) $r['discount'], 2),
        ], $rows);
    }

    /** Paginated redemption log for this code (most recent first). */
    private function usageLog(Connection $conn, int $couponId, ServerRequestInterface $request): array
    {
        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($q['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $total = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM promo_redemptions WHERE promo_code_id = ?',
            [$couponId],
        );

        $rows = $conn->fetchAllAssociative(
            "SELECT r.id,
                    to_char(r.redeemed_at, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS redeemed_at,
                    COALESCE(r.discount_amount, 0) AS discount_amount,
                    r.order_id,
                    o.order_reference,
                    o.total AS order_total_after,
                    (o.total + COALESCE(r.discount_amount, 0)) AS order_total_before,
                    NULLIF(TRIM(CONCAT(COALESCE(cust.first_name, ''), ' ', COALESCE(cust.last_name, ''))), '') AS customer_name,
                    cust.email AS customer_email
             FROM promo_redemptions r
             LEFT JOIN orders o ON o.id = r.order_id
             LEFT JOIN users cust ON cust.id = r.user_id
             WHERE r.promo_code_id = ?
             ORDER BY r.redeemed_at DESC
             LIMIT ? OFFSET ?",
            [$couponId, $perPage, $offset],
        );

        return [
            'data' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'redeemed_at' => $r['redeemed_at'],
                'customer_name' => $r['customer_name'] ?? null,
                'customer_email' => $r['customer_email'] ?? null,
                'discount_amount' => round((float) $r['discount_amount'], 2),
                'order_id' => $r['order_id'] !== null ? (int) $r['order_id'] : null,
                'order_reference' => $r['order_reference'] ?? null,
                'order_total_before' => $r['order_total_before'] !== null ? round((float) $r['order_total_before'], 2) : null,
                'order_total_after' => $r['order_total_after'] !== null ? round((float) $r['order_total_after'], 2) : null,
            ], $rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }
}
