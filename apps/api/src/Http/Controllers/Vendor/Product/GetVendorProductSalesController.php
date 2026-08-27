<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Product;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/products/{id}/sales
 *
 * Per-product sales for the authenticated vendor's own product, summary
 * KPIs, a daily units/revenue series, and recent orders containing the
 * product. Computed from order_items joined to orders, counting only
 * paid-and-beyond statuses (paid, fulfilling, shipped, delivered) as
 * realised sales. Owner-scoped; foreign/unknown product → 404.
 *
 * Query: days_back (series window, default 90), limit (recent orders,
 * default 10).
 */
final class GetVendorProductSalesController
{
    use Responder;

    /** Order statuses that count as a realised sale. */
    private const SALE_STATUSES = ['paid', 'fulfilling', 'shipped', 'delivered'];

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $ownedIds = $vendorRepo->findIdsByOwnerUser($user);
        if ($ownedIds === []) {
            throw HttpException::forbidden('No approved vendor account.');
        }

        $productId = (int) ($args['id'] ?? 0);

        // Verify the product belongs to one of the user's stores.
        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $product = null;
        foreach ($ownedIds as $vendorId) {
            $product = $productRepo->findOneByIdForVendor($productId, $vendorId);
            if ($product !== null) {
                break;
            }
        }
        if ($product === null) {
            throw HttpException::notFound('Product not found.');
        }
        $vendorId = (int) $product->getVendor()->getId();

        $q = $request->getQueryParams();
        $daysBack = max(1, min(365, (int) ($q['days_back'] ?? 90)));
        $limit = max(1, min(50, (int) ($q['limit'] ?? 10)));

        $conn = $this->em->getConnection();
        $statusList = "'" . implode("','", self::SALE_STATUSES) . "'";

        return $this->ok(['data' => [
            'product' => [
                'id'   => $product->getId(),
                'slug' => $product->getSlug(),
                'name' => $product->getName(),
            ],
            'summary'       => $this->summary($conn, $productId, $vendorId, $statusList),
            'over_time'     => $this->overTime($conn, $productId, $vendorId, $statusList, $daysBack),
            'recent_orders' => $this->recentOrders($conn, $productId, $vendorId, $statusList, $limit),
        ]]);
    }

    /** @return array<string, mixed> */
    private function summary(Connection $conn, int $productId, int $vendorId, string $statusList): array
    {
        $row = $conn->fetchAssociative(
            "SELECT
                COALESCE(SUM(oi.quantity), 0)                    AS units_sold,
                COALESCE(SUM(oi.quantity * oi.unit_price), 0)    AS revenue,
                COUNT(DISTINCT oi.order_id)                      AS order_count
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = ? AND oi.vendor_id = ?
               AND o.status IN ({$statusList})",
            [$productId, $vendorId]
        ) ?: [];

        $units = (int) ($row['units_sold'] ?? 0);
        $revenue = round((float) ($row['revenue'] ?? 0), 2);
        $orders = (int) ($row['order_count'] ?? 0);

        return [
            'units_sold'      => $units,
            'revenue'         => $revenue,
            'revenue_formatted' => 'AED ' . number_format($revenue, 2),
            'order_count'     => $orders,
            'avg_order_value' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function overTime(Connection $conn, int $productId, int $vendorId, string $statusList, int $daysBack): array
    {
        $rows = $conn->fetchAllAssociative(
            "SELECT to_char(date_trunc('day', o.created_at), 'YYYY-MM-DD') AS day,
                    COALESCE(SUM(oi.quantity), 0)                 AS units,
                    COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS revenue
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = ? AND oi.vendor_id = ?
               AND o.status IN ({$statusList})
               AND o.created_at >= (NOW() - (? || ' days')::interval)
             GROUP BY 1 ORDER BY 1 ASC",
            [$productId, $vendorId, $daysBack]
        );
        return array_map(static fn (array $r): array => [
            'day'     => $r['day'],
            'units'   => (int) $r['units'],
            'revenue' => round((float) $r['revenue'], 2),
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function recentOrders(Connection $conn, int $productId, int $vendorId, string $statusList, int $limit): array
    {
        $rows = $conn->fetchAllAssociative(
            "SELECT o.order_reference,
                    o.status,
                    to_char(o.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS created_at,
                    SUM(oi.quantity)                 AS quantity,
                    SUM(oi.quantity * oi.unit_price) AS line_total
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = ? AND oi.vendor_id = ?
               AND o.status IN ({$statusList})
             GROUP BY o.id, o.order_reference, o.status, o.created_at
             ORDER BY o.created_at DESC
             LIMIT {$limit}",
            [$productId, $vendorId]
        );
        return array_map(static fn (array $r): array => [
            'order_reference' => $r['order_reference'],
            'status'          => $r['status'],
            'created_at'      => $r['created_at'],
            'quantity'        => (int) $r['quantity'],
            'line_total'      => round((float) $r['line_total'], 2),
        ], $rows);
    }
}
