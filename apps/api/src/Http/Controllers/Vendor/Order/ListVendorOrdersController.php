<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Order;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
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
 * GET /v3/vendor/orders?limit=10&offset=0&status=fulfilling
 *
 * Paginated list of orders that have at least one item from any of
 * the vendor stores owned by the authenticated user.
 *
 * Routing: under VendorAuthMiddleware → guarantees user is a vendor.
 *
 * Pagination
 * ----------
 * limit: 1-100 (default 10)
 * offset: 0+
 * status: optional filter on Order.status (paid, fulfilling, shipped, delivered, etc.)
 *
 * Cross-vendor isolation
 * ----------------------
 * Vendor A canNOT see Vendor B's orders. The repository's
 * paginatedForVendorIds restricts to orders containing at least
 * one item from any of the requesting user's vendor ids. Returned
 * orders MAY contain items from OTHER vendors (multi-vendor cart);
 * the serializer filters those out in listShape.
 *
 * Returns the order list ordered by created_at DESC.
 */
final class ListVendorOrdersController
{
    use Responder;

    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 100;

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

        /** @var VendorRepository $vendors */
        $vendors = $this->em->getRepository(Vendor::class);
        $vendorIds = $vendors->findIdsByOwnerUser($user);

        $query = $request->getQueryParams();
        $limit = $this->clampLimit($query['limit'] ?? null);
        $offset = $this->clampOffset($query['offset'] ?? null);
        $status = $this->parseStatus($query['status'] ?? null);
        $search = $this->parseSearch($query['search'] ?? null);
        $dateFrom = $this->parseDate($query['date_from'] ?? null);
        $dateTo = $this->parseDate($query['date_to'] ?? null);

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        [$list, $total] = $orders->paginatedForVendorIds($vendorIds, $limit, $offset, $status, $search, $dateFrom, $dateTo);

        $vendorIdSet = array_flip($vendorIds);
        $items = array_map(
            fn (Order $o): array => $this->vendorListShape($o, $vendorIdSet),
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

    /**
     * List-shape with items filtered to those belonging to the
     * requesting vendor. Items from other vendors are stripped at
     * serialisation time (the order entity still has them, but the
     * caller doesn't see them).
     *
     * @param array<int, int> $vendorIdSet Flipped vendor ids for O(1) membership check.
     * @return array<string, mixed>
     */
    private function vendorListShape(Order $order, array $vendorIdSet): array
    {
        $shape = $this->serializer->listShape($order);

        $shape['items'] = array_values(array_filter(
            $shape['items'],
            static function (array $item) use ($vendorIdSet): bool {
                $vid = $item['vendor_id'] ?? null;
                return is_int($vid) && isset($vendorIdSet[$vid]);
            },
        ));

        return $shape;
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

    private function parseStatus(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            return null;
        }
        $valid = [
            Order::STATUS_PAID,
            Order::STATUS_FULFILLING,
            Order::STATUS_SHIPPED,
            Order::STATUS_DELIVERED,
            Order::STATUS_CANCELLED,
            Order::STATUS_REFUNDED,
        ];
        // pending_payment + failed are NOT vendor-filterable — vendors never
        // see unpaid or failed-payment orders (no fulfilment obligation); the
        // repository also excludes them from the default list unconditionally.
        return in_array($raw, $valid, true) ? $raw : null;
    }

    private function parseSearch(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        return $trimmed === '' ? null : mb_substr($trimmed, 0, 100);
    }

    /**
     * Accept an ISO date (YYYY-MM-DD) for the created-at range filter;
     * anything malformed is ignored rather than erroring the request.
     */
    private function parseDate(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $d !== false ? $d->format('Y-m-d') : null;
    }
}
