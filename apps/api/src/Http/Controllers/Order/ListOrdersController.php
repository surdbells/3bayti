<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Order;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\OrderSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/orders?limit=10&offset=0
 *
 * Paginated list of the authenticated user's orders. Replaces the
 * legacy customer/read-orders + customer/read_orders_listing
 * endpoints (mobile's my-orders.page binds order.id / order.date /
 * order.status / order.total / order.items[]).
 *
 * Pagination
 * ----------
 * limit: 1-50 (default 10; matches mobile's initial.limit = 10)
 * offset: 0+ (default 0)
 *
 * Returns the order list ordered by created_at DESC (newest first;
 * matches mobile's display expectation).
 */
final class ListOrdersController
{
    use Responder;

    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OrderSerializer $serializer,
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

        $query = $request->getQueryParams();
        $limit = $this->clampLimit($query['limit'] ?? null);
        $offset = $this->clampOffset($query['offset'] ?? null);

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        [$list, $total] = $orders->paginatedForUser($user, $limit, $offset);

        $items = array_map(
            fn (Order $o): array => $this->serializer->listShape($o),
            $list,
        );

        return $this->ok([
            'orders' => $items,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($items),
                'total' => $total,
            ],
        ]);
    }

    private function clampLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        $n = (int) $raw;
        if ($n < 1) {
            return self::DEFAULT_LIMIT;
        }
        return min($n, self::MAX_LIMIT);
    }

    private function clampOffset(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        $n = (int) $raw;
        return max(0, $n);
    }
}
