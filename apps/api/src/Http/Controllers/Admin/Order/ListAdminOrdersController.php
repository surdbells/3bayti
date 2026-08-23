<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
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
 * GET /v3/admin/orders?limit=10&offset=0&status=&user_id=&vendor_id=
 *
 * Admin cross-vendor orders view. Optional filters narrow the scope.
 * Returns the full unfiltered order shape (no per-vendor item
 * filtering — admins see everything).
 *
 * Q5=A audit: emits ACTION_VIEWED with the filter set as context.
 * Does NOT log per-order subject_ids in the list view because (a)
 * it would balloon the audit table and (b) the filter set + actor
 * lets ops reconstruct what the admin saw with a query.
 *
 * Routing: under AdminAuthMiddleware → guarantees user is admin.
 */
final class ListAdminOrdersController
{
    use Responder;

    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 100;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OrderSerializer $serializer,
        private readonly AuditEmitter $audit,
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
        $status = $this->parseStatus($query['status'] ?? null);
        $userIdFilter = $this->parsePositiveInt($query['user_id'] ?? null);
        $vendorIdFilter = $this->parsePositiveInt($query['vendor_id'] ?? null);
        $since = $this->parseDate($query['since'] ?? null, false);
        $until = $this->parseDate($query['until'] ?? null, true);

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        [$list, $total] = $orders->paginatedForAdmin(
            $limit,
            $offset,
            $status,
            $userIdFilter,
            $vendorIdFilter,
            $since,
            $until,
            includeGiftCards: true,
        );

        // Audit the listing access. Subject is the admin User
        // themselves — list views don't have a single subject entity,
        // so the audit row records "admin X queried the orders list
        // with these filters." We use the actor as subject to keep
        // the audit row valid (subjectId requires int).
        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $user,
            context: [
                'context' => 'admin_orders_list',
                'filters' => array_filter([
                    'limit' => $limit,
                    'offset' => $offset,
                    'status' => $status,
                    'user_id' => $userIdFilter,
                    'vendor_id' => $vendorIdFilter,
                ], static fn ($v) => $v !== null),
                'result_count' => count($list),
                'total' => $total,
            ],
        );

        // Prefetch the gift-card map for THIS page in one query so
        // synthesizing the "Gift Card" line for gift-card purchase orders
        // (those with zero real items) is N+1-free — mirrors the customer
        // ListOrdersController. Keyed by order_reference ==
        // gift_cards.purchase_order_reference. Skipped for an empty page.
        $giftCardMap = [];
        if ($list !== []) {
            $refs = array_map(static fn (Order $o): string => $o->getOrderReference(), $list);
            /** @var GiftCardRepository $giftCards */
            $giftCards = $this->em->getRepository(GiftCard::class);
            $giftCardMap = $giftCards->findByPurchaseOrderReferences($refs);
        }

        $items = array_map(
            fn (Order $o): array => $this->serializer->adminListShape(
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
            ],
        ]);
    }

    private function clampLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        $n = (int) $raw;
        return $n < 1 ? self::DEFAULT_LIMIT : min($n, self::MAX_LIMIT);
    }

    private function clampOffset(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        return max(0, (int) $raw);
    }

    /**
     * Parse the status filter. Accepts either a single status value or a
     * comma-separated list (CSV) of statuses — the logistics board sends the
     * fulfilment set ("shipped,delivered") this way. Each value is validated
     * against the canonical Order::STATUS_* set; unknown values are dropped.
     *
     * Return contract (kept backward-compatible):
     *   - no/empty/all-invalid input → null (no status filter)
     *   - exactly one valid value    → that value as a string
     *   - multiple valid values      → a de-duplicated list<string>
     *
     * Returning a bare string for the single-value case preserves the prior
     * generic /admin/orders behaviour for every existing caller.
     *
     * @return string|list<string>|null
     */
    private function parseStatus(mixed $raw): string|array|null
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $valid = [
            Order::STATUS_PENDING_PAYMENT,
            Order::STATUS_PAID,
            Order::STATUS_FULFILLING,
            Order::STATUS_SHIPPED,
            Order::STATUS_DELIVERED,
            Order::STATUS_CANCELLED,
            Order::STATUS_REFUNDED,
            Order::STATUS_FAILED,
        ];

        $candidates = array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $s): bool => $s !== '',
        );
        $accepted = array_values(array_unique(array_filter(
            $candidates,
            static fn (string $s): bool => in_array($s, $valid, true),
        )));

        if ($accepted === []) {
            return null;
        }
        return count($accepted) === 1 ? $accepted[0] : $accepted;
    }

    private function parsePositiveInt(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $n = (int) $raw;
        return $n > 0 ? $n : null;
    }

    /**
     * Parse a date filter value into a DateTimeImmutable, or null. A bare
     * date (YYYY-MM-DD) is snapped to the start of the day for the lower
     * bound and the end of the day for the upper bound, so an inclusive
     * [since, until] range covers whole days.
     */
    private function parseDate(mixed $raw, bool $endOfDay): ?\DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        try {
            $dt = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $dt = $endOfDay ? $dt->setTime(23, 59, 59) : $dt->setTime(0, 0, 0);
        }
        return $dt;
    }
}
