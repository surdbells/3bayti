<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Coupon;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
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
 * Vendor coupon detail + analytics.
 *
 *   GET /v3/vendor/coupons/{id}            single coupon (detail())
 *   GET /v3/vendor/coupons/{id}/analytics  usage analytics (analytics())
 *
 * Analytics is computed from promo_redemptions for the coupon, scoped to
 * the authenticated vendor's own coupon. The ?period param selects the
 * shape the portal's analytics screen consumes:
 *   - overview      → store-wide coupon KPIs (active coupons + this
 *                     coupon's redemptions/discount/revenue)
 *   - coupon_stats  → this coupon's totals (uses, discount, revenue,
 *                     unique customers)  [default]
 *   - usage_over_time → daily redemption counts for the last N days
 */
final class VendorCouponAnalyticsController
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

    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$coupon] = $this->resolveCoupon($request, (int) ($args['id'] ?? 0));
        return $this->ok(['data' => $this->serializer->adminShape($coupon)]);
    }

    public function analytics(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$coupon, $vendorId] = $this->resolveCoupon($request, (int) ($args['id'] ?? 0));
        $couponId = (int) $coupon->getId();
        $period = (string) ($request->getQueryParams()['period'] ?? 'coupon_stats');
        $conn = $this->em->getConnection();

        return match ($period) {
            'overview'         => $this->ok(['data' => $this->overview($conn, $vendorId, $couponId)]),
            'usage_over_time'  => $this->ok(['data' => $this->usageOverTime($conn, $couponId, $request)]),
            'usage_log'        => $this->ok($this->usageLog($conn, $couponId, $request)),
            'top_coupons'      => $this->ok(['data' => $this->topCoupons($conn, $vendorId, $request)]),
            'live_count'       => $this->ok(['data' => ['times_used' => $this->liveCount($conn, $couponId)]]),
            default            => $this->ok(['data' => $this->couponStats($conn, $couponId)]),
        };
    }

    // ── analytics shapes ─────────────────────────────────────────────

    /** This coupon's lifetime totals. */
    private function couponStats(Connection $conn, int $couponId): array
    {
        $row = $conn->fetchAssociative(
            "SELECT
                COUNT(*)                          AS total_uses,
                COALESCE(SUM(discount_amount), 0) AS total_discount_given,
                COUNT(DISTINCT user_id)           AS unique_customers
             FROM promo_redemptions
             WHERE promo_code_id = ?",
            [$couponId]
        ) ?: [];

        $revenue = $conn->fetchOne(
            "SELECT COALESCE(SUM(o.total), 0)
             FROM promo_redemptions r
             JOIN orders o ON o.id = r.order_id
             WHERE r.promo_code_id = ?",
            [$couponId]
        );

        return [
            'total_uses'              => (int) ($row['total_uses'] ?? 0),
            'total_discount_given'    => round((float) ($row['total_discount_given'] ?? 0), 2),
            'unique_customers'        => (int) ($row['unique_customers'] ?? 0),
            'total_revenue_generated' => round((float) $revenue, 2),
        ];
    }

    /** Store-wide coupon KPIs (active coupons + this coupon's figures). */
    private function overview(Connection $conn, int $vendorId, int $couponId): array
    {
        $activeCoupons = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM promo_codes WHERE vendor_id = ? AND is_active = TRUE",
            [$vendorId]
        );
        $stats = $this->couponStats($conn, $couponId);

        return [
            'active_coupons'              => $activeCoupons,
            'total_redemptions'           => $stats['total_uses'],
            'total_discount_given'        => $stats['total_discount_given'],
            'total_revenue_with_coupons'  => $stats['total_revenue_generated'],
        ];
    }

    /** Daily redemption counts for the last N days (default 30). */
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
            [$couponId, $daysBack]
        );
        return array_map(static fn (array $r): array => [
            'day'      => $r['day'],
            'uses'     => (int) $r['uses'],
            'discount' => round((float) $r['discount'], 2),
        ], $rows);
    }

    /** Paginated redemption log for this coupon (most recent first). */
    private function usageLog(Connection $conn, int $couponId, ServerRequestInterface $request): array
    {
        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($q['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $total = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM promo_redemptions WHERE promo_code_id = ?',
            [$couponId]
        );

        $rows = $conn->fetchAllAssociative(
            "SELECT r.id,
                    to_char(r.redeemed_at, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS redeemed_at,
                    COALESCE(r.discount_amount, 0) AS discount_amount,
                    r.order_id,
                    o.order_reference
             FROM promo_redemptions r
             LEFT JOIN orders o ON o.id = r.order_id
             WHERE r.promo_code_id = ?
             ORDER BY r.redeemed_at DESC
             LIMIT ? OFFSET ?",
            [$couponId, $perPage, $offset]
        );

        return [
            'data' => array_map(static fn (array $r): array => [
                'id'              => (int) $r['id'],
                'redeemed_at'     => $r['redeemed_at'],
                'discount_amount' => round((float) $r['discount_amount'], 2),
                'order_id'        => $r['order_id'] !== null ? (int) $r['order_id'] : null,
                'order_reference' => $r['order_reference'] ?? null,
            ], $rows),
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Top coupons across the vendor's store (by uses or discount). Scoped
     * to the vendor, so the :id in the path is not used for this period.
     *
     * @return list<array<string, mixed>>
     */
    private function topCoupons(Connection $conn, int $vendorId, ServerRequestInterface $request): array
    {
        $q = $request->getQueryParams();
        $limit = max(1, min(50, (int) ($q['limit'] ?? 5)));
        $sortBy = ((string) ($q['sort_by'] ?? 'uses')) === 'discount' ? 'discount' : 'uses';

        $rows = $conn->fetchAllAssociative(
            "SELECT pc.id, pc.code, pc.name,
                    COUNT(r.id) AS uses,
                    COALESCE(SUM(r.discount_amount), 0) AS discount
             FROM promo_codes pc
             LEFT JOIN promo_redemptions r ON r.promo_code_id = pc.id
             WHERE pc.vendor_id = ?
             GROUP BY pc.id, pc.code, pc.name
             ORDER BY {$sortBy} DESC
             LIMIT ?",
            [$vendorId, $limit]
        );

        return array_map(static fn (array $r): array => [
            'id'       => (int) $r['id'],
            'code'     => $r['code'],
            'name'     => $r['name'],
            'uses'     => (int) $r['uses'],
            'discount' => round((float) $r['discount'], 2),
        ], $rows);
    }

    /** Current redemption count for this coupon (live counter). */
    private function liveCount(Connection $conn, int $couponId): int
    {
        return (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM promo_redemptions WHERE promo_code_id = ?',
            [$couponId]
        );
    }

    // ── resolution ───────────────────────────────────────────────────

    /**
     * @return array{0: PromoCode, 1: int} [coupon, vendorId]
     */
    private function resolveCoupon(ServerRequestInterface $request, int $couponId): array
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }
        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendors = $vendorRepo->findByOwnerUser($user);
        if ($vendors === []) {
            throw HttpException::forbidden('No approved vendor account found.');
        }
        $vendorId = (int) $vendors[0]->getId();

        /** @var PromoCodeRepository $repo */
        $repo = $this->em->getRepository(PromoCode::class);
        $coupon = $repo->findByIdAndVendor($couponId, $vendorId);
        if ($coupon === null) {
            throw HttpException::notFound('Coupon not found.');
        }
        return [$coupon, $vendorId];
    }
}
