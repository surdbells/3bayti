<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Order;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
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
 * Filtering
 * ---------
 * status: optional single Order::STATUS_* value (pending_payment / paid /
 * fulfilling / shipped / delivered / cancelled / refunded / failed). Drives
 * the mobile My-Orders status chips. Applied SERVER-SIDE so it composes
 * correctly with limit/offset infinite-scroll. An unknown / empty value is
 * ignored (treated as "all").
 *
 * type: optional order-type filter, "product" (only orders with a real
 * product line; excludes gift-card purchases) or "gift_card" ("card" is
 * accepted as an alias; only synthetic gift-card purchase orders). Applied
 * SERVER-SIDE and composes with `status` + pagination. Absent / any other
 * value is ignored (treated as "all").
 *
 * Returns the order list ordered by created_at DESC (newest first;
 * matches mobile's display expectation).
 */
final class ListOrdersController
{
    use Responder;

    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    /**
     * Statuses the customer My-Orders surface is allowed to filter on.
     * Anything else (incl. '' / 'all') is normalised to no filter.
     */
    private const ALLOWED_STATUSES = [
        Order::STATUS_PENDING_PAYMENT,
        Order::STATUS_PAID,
        Order::STATUS_FULFILLING,
        Order::STATUS_SHIPPED,
        Order::STATUS_DELIVERED,
        Order::STATUS_CANCELLED,
        Order::STATUS_REFUNDED,
        Order::STATUS_FAILED,
    ];

    /** Order-type filter values (normalised); null = all. */
    public const TYPE_PRODUCT = 'product';
    public const TYPE_GIFT_CARD = 'gift_card';

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
        $status = $this->normalizeStatus($query['status'] ?? null);
        $type = $this->normalizeType($query['type'] ?? null);

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        [$list, $total] = $orders->paginatedForUser($user, $limit, $offset, $status, $type);

        // Prefetch the gift-card map for THIS page in one query, so
        // synthesizing the "Gift Card" line for gift-card purchase
        // orders (those with zero real items) never triggers an N+1.
        // Keyed by order_reference == gift_cards.purchase_order_reference.
        // Skip the gift-card lookup entirely for an empty page, no orders
        // means no lines to synthesize, and it avoids a needless query.
        $giftCardMap = [];
        if ($list !== []) {
            $refs = array_map(static fn (Order $o): string => $o->getOrderReference(), $list);
            /** @var GiftCardRepository $giftCards */
            $giftCards = $this->em->getRepository(GiftCard::class);
            $giftCardMap = $giftCards->findByPurchaseOrderReferences($refs);
        }

        $items = array_map(
            fn (Order $o): array => $this->serializer->listShape(
                $o,
                null,
                $giftCardMap[$o->getOrderReference()] ?? null,
            ),
            $list,
        );

        return $this->ok([
            'orders' => $items,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($items),
                'total' => $total,
                'status' => $status,
                'type' => $type,
            ],
        ]);
    }

    /**
     * Normalise the raw ?type= query value. "product" and "gift_card"
     * pass through; "card" is an accepted alias for "gift_card". Any
     * other / empty value becomes null ("all"), so a bad client param
     * degrades to an unfiltered list rather than an error.
     */
    private function normalizeType(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return match ($raw) {
            self::TYPE_PRODUCT => self::TYPE_PRODUCT,
            self::TYPE_GIFT_CARD, 'card' => self::TYPE_GIFT_CARD,
            default => null,
        };
    }

    /**
     * Normalise the raw ?status= query value to a known Order status, or
     * null for "all". Unknown / empty / 'all' values become null so a bad
     * client param degrades to an unfiltered list rather than an error.
     */
    private function normalizeStatus(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return in_array($raw, self::ALLOWED_STATUSES, true) ? $raw : null;
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
