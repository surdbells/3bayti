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
 * GET /v3/admin/top-customers
 *
 * The 10 highest-spending customers ranked by number of purchases —
 * orders that reached a real sale state (paid/fulfilling/shipped/
 * delivered). Feeds the admin "Top performers" carousel: rank +
 * customer identity + purchases_count + spend.
 */
final class ListTopCustomersController
{
    use Responder;

    private const LIMIT = 10;

    /** Order statuses that count as a completed/committed purchase. */
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
            SELECT u.id, u.first_name, u.last_name, u.avatar_url,
                   COUNT(o.id)                AS purchases_count,
                   COALESCE(SUM(o.total), 0)  AS spend
            FROM orders o
            JOIN users u ON u.id = o.user_id
            WHERE o.status IN (' . self::SALE_STATUSES . ')
            GROUP BY u.id, u.first_name, u.last_name, u.avatar_url
            ORDER BY purchases_count DESC, spend DESC
            LIMIT ' . self::LIMIT;

        $rows = $this->em->getConnection()->fetchAllAssociative($sql);

        $data = [];
        foreach ($rows as $i => $r) {
            $first = (string) ($r['first_name'] ?? '');
            $last  = (string) ($r['last_name'] ?? '');
            $data[] = [
                'rank'            => $i + 1,
                'id'              => (int) $r['id'],
                'first_name'      => $first,
                'last_name'       => $last,
                'name'            => trim($first . ' ' . $last),
                'avatar_url'      => $r['avatar_url'] !== null ? (string) $r['avatar_url'] : null,
                'purchases_count' => (int) $r['purchases_count'],
                'spend'           => (float) $r['spend'],
            ];
        }

        return $this->ok(['data' => $data]);
    }
}
