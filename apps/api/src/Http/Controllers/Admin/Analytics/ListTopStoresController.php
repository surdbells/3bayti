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
 * GET /v3/admin/top-stores
 *
 * The 10 highest-performing stores ranked by units sold, every
 * order_items row whose parent order reached a real sale state
 * (paid/fulfilling/shipped/delivered; cancelled/refunded/failed/
 * pending_payment are excluded). Feeds the admin "Top performers"
 * carousel: rank + store identity + sales_count + revenue.
 */
final class ListTopStoresController
{
    use Responder;

    private const LIMIT = 10;

    /** Order statuses that count as a completed/committed sale. */
    private const SALE_STATUSES = "'paid', 'fulfilling', 'shipped', 'delivered'";

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

        $sql = '
            SELECT v.id, v.name, v.slug, v.logo_url,
                   COUNT(oi.id)                  AS sales_count,
                   COALESCE(SUM(oi.subtotal), 0) AS revenue
            FROM order_items oi
            JOIN orders o  ON o.id = oi.order_id
            JOIN vendors v ON v.id = oi.vendor_id
            WHERE o.status IN (' . self::SALE_STATUSES . ')
            GROUP BY v.id, v.name, v.slug, v.logo_url
            ORDER BY sales_count DESC, revenue DESC
            LIMIT ' . self::LIMIT;

        $rows = $this->em->getConnection()->fetchAllAssociative($sql);

        $data = [];
        foreach ($rows as $i => $r) {
            $data[] = [
                'rank'        => $i + 1,
                'id'          => (int) $r['id'],
                'name'        => (string) $r['name'],
                'slug'        => (string) $r['slug'],
                'logo_url'    => $r['logo_url'] !== null ? (string) $r['logo_url'] : null,
                'sales_count' => (int) $r['sales_count'],
                'revenue'     => (float) $r['revenue'],
            ];
        }

        return $this->ok(['data' => $data]);
    }
}
