<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * Repository for Order.
 *
 * @extends EntityRepository<Order>
 */
class OrderRepository extends EntityRepository
{
    /**
     * Find an order by its v3 order_reference.
     *
     * Used by the webhook handler to look up the order being notified
     * about. order_reference is UNIQUE so this is a single-row lookup.
     */
    public function findByOrderReference(string $orderReference): ?Order
    {
        $result = $this->findOneBy(['orderReference' => $orderReference]);
        /** @var Order|null $result */
        return $result;
    }

    /**
     * Find an order by its legacy_order_id (compat lookup for the
     * M3.1.6h migration step and any code that resolves orders by
     * their legacy id during the transition).
     */
    public function findByLegacyOrderId(int $legacyOrderId): ?Order
    {
        $result = $this->findOneBy(['legacyOrderId' => $legacyOrderId]);
        /** @var Order|null $result */
        return $result;
    }

    /**
     * Find an order by its primary key, eager-loading items + their
     * vendor + their product, plus addresses. Used by the GetOrder
     * controller to avoid N+1 in serialisation.
     *
     * Returns null for unknown id OR an order not belonging to the
     * given user (caller surfaces both as 404 to avoid leaking the
     * existence of other users' orders).
     */
    public function findForUser(int $orderId, User $user): ?Order
    {
        $result = $this->createQueryBuilder('o')
            ->select('o', 'i', 'p', 'v', 'a')
            ->leftJoin('o.items', 'i')
            ->leftJoin('i.product', 'p')
            ->leftJoin('i.vendor', 'v')
            ->leftJoin('o.addresses', 'a')
            ->where('o.id = :id')
            ->andWhere('o.user = :user')
            ->setParameter('id', $orderId)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        /** @var Order|null $result */
        return $result;
    }

    /**
     * Paginated list of a user's orders, most recent first.
     *
     * Eager-loads items + their snapshotted product details (no
     * vendor join here — the listing UI doesn't show vendor; the
     * detail page does).
     *
     * A single Order::STATUS_* value may be passed to scope the list to
     * that status (used by the mobile My-Orders status chips). Null or an
     * empty string means "all statuses". The same filter is applied to
     * both the count and the id-page query so pagination stays consistent.
     *
     * An optional $typeFilter scopes by order TYPE:
     *   - 'product'   → only orders WITHOUT a linked gift card
     *                   (NOT EXISTS on gift_cards.purchase_order_reference)
     *   - 'gift_card' → only synthetic gift-card PURCHASE orders
     *                   (EXISTS on the same back-reference)
     *   - null / other → no type filter.
     * It reuses the exact gift-card back-reference subquery shape as the
     * admin exclusion (excludeGiftCardPurchases) and composes with the
     * status filter. Applied to both count + id-page for consistency.
     *
     * @return array{0: list<Order>, 1: int} Tuple of [orders, total_count].
     */
    public function paginatedForUser(
        User $user,
        int $limit,
        int $offset,
        ?string $statusFilter = null,
        ?string $typeFilter = null,
    ): array {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $status = ($statusFilter !== null && $statusFilter !== '') ? $statusFilter : null;
        $type = ($typeFilter !== null && $typeFilter !== '') ? $typeFilter : null;

        // Total count: simple, no joins
        $totalQb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.user = :user')
            ->setParameter('user', $user);
        if ($status !== null) {
            $totalQb->andWhere('o.status = :status')->setParameter('status', $status);
        }
        $this->applyTypeFilter($totalQb, $type);
        $total = (int) $totalQb->getQuery()->getSingleScalarResult();

        if ($total === 0) {
            return [[], 0];
        }

        // Page of order ids first (paginate against the orders table only;
        // joining items into a paginated query would multiply rows and break
        // limit/offset semantics).
        $idQb = $this->createQueryBuilder('o')
            ->select('o.id')
            ->where('o.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        if ($status !== null) {
            $idQb->andWhere('o.status = :status')->setParameter('status', $status);
        }
        $this->applyTypeFilter($idQb, $type);
        $idResult = $idQb->getQuery()->getScalarResult();

        $ids = array_map(static fn(array $row): int => (int) $row['id'], $idResult);

        if ($ids === []) {
            return [[], $total];
        }

        // Now hydrate the orders + items in one query
        $orders = $this->createQueryBuilder('o')
            ->select('o', 'i')
            ->leftJoin('o.items', 'i')
            ->where('o.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        /** @var list<Order> $orders */
        return [$orders, $total];
    }

    public function save(Order $order): void
    {
        $em = $this->getEntityManager();
        $em->persist($order);
        $em->flush();
    }

    /**
     * Find orders stuck in pending_payment past the given cutoff,
     * up to a limit. Used by the M3.1.7-B reconciliation cron.
     *
     * The query is intentionally narrow:
     *   - status = 'pending_payment' (we only want truly-stuck orders;
     *     a paid order needs no reconciliation)
     *   - created_at < cutoff (we want orders old enough that the
     *     webhook delivery window should have passed)
     *   - PRODUCT orders only — synthetic gift-card PURCHASE orders are
     *     excluded (NOT EXISTS on gift_cards.purchase_order_reference,
     *     reusing excludeGiftCardPurchases). ReconcilePendingOrdersCommand
     *     marks orders paid/failed but does NOT activate gift cards, so a
     *     gift-card funding order must not be reconciled here — it stays on
     *     its own poll/complete-payment (webhook + activate) path.
     *   - ordered by created_at ASC (oldest first; reconcile the
     *     most-painful-to-the-customer ones first)
     *
     * Returns Order entities with their PaymentTransaction relation
     * NOT eagerly loaded — the caller fetches the transaction's
     * provider_order_ref on-demand to keep this query lean even when
     * the table has many pending rows.
     *
     * @param int $batchLimit max rows to return (caller caps cron load)
     * @return list<Order>
     */
    public function findStuckPendingPayment(\DateTimeImmutable $cutoff, int $batchLimit): array
    {
        $batchLimit = max(1, min($batchLimit, 500));

        $qb = $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->andWhere('o.createdAt < :cutoff')
            ->setParameter('status', Order::STATUS_PENDING_PAYMENT)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('o.createdAt', 'ASC')
            ->setMaxResults($batchLimit);

        // Product orders only — never reconcile gift-card funding orders here.
        $this->excludeGiftCardPurchases($qb);

        $result = $qb->getQuery()->getResult();

        /** @var list<Order> $result */
        return $result;
    }

    /**
     * Find delivered orders eligible for a post-delivery review-prompt
     * PUSH (marketing follow-up).
     *
     * Eligibility:
     *   - status = 'delivered'
     *   - delivered_at IS NOT NULL AND delivered_at <= (now - 2 days)
     *     (give the customer time to actually receive + use the item
     *     before asking for a review)
     *   - NO prior notification_logs row with template='order.review_prompt'
     *     AND channel='push' for this order (channel-scoped idempotency —
     *     exactly one review prompt per order)
     *
     * The opt-out check (users.marketing_push_opt_out) is enforced at
     * dispatch time inside PushNotificationService::orderReviewPrompt,
     * NOT here — same split as the cart-abandonment finder: the finder
     * answers "is this order eligible to consider?", dispatch answers
     * "will this user receive it?".
     *
     * Ordered oldest-delivered first so the longest-waiting customers
     * are prompted first. LIMIT bounds the cron batch.
     *
     * NOTE: the prior-prompt guard is a RAW SQL NOT EXISTS against
     * notification_logs because the NotificationLog *entity* is not
     * mapped for the channel column (the foundation generalized the
     * table but not the entity). A DQL reference to nl.channel would
     * fail metadata validation, so we resolve eligible ids with a
     * native query, then hydrate via DQL.
     *
     * @return list<Order>
     */
    public function findDeliveredForFollowUp(
        \DateTimeImmutable $deliveredBefore,
        int $batchLimit,
    ): array {
        $batchLimit = max(1, min($batchLimit, 500));

        $sql = <<<'SQL'
            SELECT o.id
            FROM orders o
            WHERE o.status = :status
              AND o.delivered_at IS NOT NULL
              AND o.delivered_at <= :before
              AND NOT EXISTS (
                  SELECT 1 FROM notification_logs nl
                  WHERE nl.order_id = o.id
                    AND nl.template = :tpl
                    AND nl.channel = :ch
              )
            ORDER BY o.delivered_at ASC
            LIMIT :lim
        SQL;

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'status' => Order::STATUS_DELIVERED,
                'before' => $deliveredBefore->format('Y-m-d H:i:sP'),
                'tpl' => 'order.review_prompt',
                'ch' => 'push',
                'lim' => $batchLimit,
            ],
            [
                'status' => \Doctrine\DBAL\ParameterType::STRING,
                'before' => \Doctrine\DBAL\ParameterType::STRING,
                'tpl' => \Doctrine\DBAL\ParameterType::STRING,
                'ch' => \Doctrine\DBAL\ParameterType::STRING,
                'lim' => \Doctrine\DBAL\ParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        if ($ids === []) {
            return [];
        }

        $orders = $this->createQueryBuilder('o')
            ->where('o.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('o.deliveredAt', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var list<Order> $orders */
        return $orders;
    }

    /**
     * Persist an order with its items and addresses as one unit.
     */
    public function saveWithEverything(Order $order): void
    {
        $em = $this->getEntityManager();
        $em->persist($order);
        foreach ($order->getItems() as $item) {
            $em->persist($item);
        }
        foreach ($order->getAddresses() as $address) {
            $em->persist($address);
        }
        $em->flush();
    }

    /**
     * Exclude synthetic gift-card *purchase* orders from an admin-scoped
     * order query.
     *
     * Gift-card purchases create a synthetic Order with no line items
     * (InitiateCheckoutController::initiateGiftCardPurchase), linked back to
     * the funded card via gift_cards.purchase_order_reference =
     * orders.order_reference. Those orders are an internal payment vehicle —
     * they must never appear on the admin Orders list, the logistics board,
     * or analytics recent-sales.
     *
     * We exclude them via the INVERSE relation (a NOT EXISTS against
     * GiftCard.purchaseOrderReference) rather than a zero-items heuristic:
     * a real order could legitimately be emptied, and a zero-items check
     * would also swallow those. Matching on the gift-card back-reference is
     * exact.
     *
     * @param string $alias the Order alias used in the query builder ('o').
     */
    private function excludeGiftCardPurchases(QueryBuilder $qb, string $alias = 'o'): void
    {
        $qb->andWhere(
            'NOT EXISTS ('
            . 'SELECT 1 FROM \\Bayti\\Api\\Domain\\GiftCard\\GiftCard gc '
            . "WHERE gc.purchaseOrderReference = {$alias}.orderReference"
            . ')'
        );
    }

    /**
     * Apply the customer-facing order-TYPE filter, reusing the exact
     * gift-card back-reference subquery shape as excludeGiftCardPurchases:
     *
     *   'gift_card' → EXISTS     (only synthetic gift-card purchase orders)
     *   'product'   → NOT EXISTS (only orders with no linked gift card —
     *                             i.e. real product orders)
     *   null / other → no filter (all orders).
     *
     * The 'product' branch delegates to excludeGiftCardPurchases so the
     * two stay in lock-step. The subquery is uncorrelated-to-status, so
     * this composes cleanly with the status filter.
     *
     * @param string $alias the Order alias used in the query builder ('o').
     */
    private function applyTypeFilter(QueryBuilder $qb, ?string $type, string $alias = 'o'): void
    {
        if ($type === 'gift_card') {
            $qb->andWhere(
                'EXISTS ('
                . 'SELECT 1 FROM \\Bayti\\Api\\Domain\\GiftCard\\GiftCard gc '
                . "WHERE gc.purchaseOrderReference = {$alias}.orderReference"
                . ')'
            );
        } elseif ($type === 'product') {
            $this->excludeGiftCardPurchases($qb, $alias);
        }
    }

    /**
     * Decide how the admin Orders & Sales query treats gift-card purchase
     * orders. An explicit type filter wins (product = exclude, gift_card =
     * only gift cards); with no type filter the includeGiftCards flag decides
     * whether they're shown alongside product orders. Applied to both the
     * count and id-page builders so they stay consistent.
     */
    private function applyAdminGiftCardScope(QueryBuilder $qb, ?string $typeFilter, bool $includeGiftCards): void
    {
        if ($typeFilter !== null) {
            $this->applyTypeFilter($qb, $typeFilter);
            return;
        }
        if (!$includeGiftCards) {
            $this->excludeGiftCardPurchases($qb);
        }
    }

    /**
     * Admin-scoped paginated order list. Supports cross-cutting
     * filters that the customer + vendor surfaces don't expose:
     *   - status: a single Order::STATUS_* value, a list of values
     *     (matched with IN — used by the logistics board to scope to the
     *     fulfilment set), or null for all
     *   - userId: filter to a single customer
     *   - vendorId: filter to orders containing at least one item
     *     from a specific vendor
     *   - productId: filter to orders containing a specific product
     *     (shares the o.items join with vendorId; gift-card orders have
     *     no items so a product filter naturally excludes them)
     *   - type: 'product' (exclude gift-card orders) / 'gift_card' (only
     *     gift-card sales) — drives the "Product or Gift Card" filter on
     *     the merged Orders & Sales page. Overrides includeGiftCards.
     *
     * Synthetic gift-card purchase orders are excluded by default (see
     * excludeGiftCardPurchases) so they never pollute the logistics board.
     * Pass $includeGiftCards = true to surface them as sales in the merged
     * admin Orders & Sales list — the serializer synthesizes a "Gift Card"
     * line for them. Note a vendorId filter still excludes gift-card orders
     * regardless (they have no vendor items to join on).
     *
     * Used by M3.1.7-D admin orders surface.
     *
     * @param string|list<string>|null $statusFilter
     * @return array{0: list<Order>, 1: int}
     */
    public function paginatedForAdmin(
        int $limit,
        int $offset,
        string|array|null $statusFilter = null,
        ?int $userIdFilter = null,
        ?int $vendorIdFilter = null,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $until = null,
        bool $includeGiftCards = false,
        ?int $productIdFilter = null,
        ?string $typeFilter = null,
    ): array {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        // Normalise the status filter into a clean list (drops empties).
        // A single string stays a 1-element list; null/[] means "no filter".
        $statusList = is_array($statusFilter)
            ? array_values(array_filter($statusFilter, static fn ($s): bool => is_string($s) && $s !== ''))
            : ($statusFilter !== null && $statusFilter !== '' ? [$statusFilter] : []);

        // A vendor OR product filter narrows on order_items, so both share a
        // single inner join on o.items (adding two would double-count / conflict
        // on the alias). Either present forces DISTINCT o.id.
        $needsItemsJoin = $vendorIdFilter !== null || $productIdFilter !== null;

        // Count query
        $totalQb = $this->createQueryBuilder('o');
        if ($needsItemsJoin) {
            $totalQb->select('COUNT(DISTINCT o.id)')->innerJoin('o.items', 'i');
            if ($vendorIdFilter !== null) {
                $totalQb->andWhere('i.vendor = :vendor')->setParameter('vendor', $vendorIdFilter);
            }
            if ($productIdFilter !== null) {
                $totalQb->andWhere('i.product = :product')->setParameter('product', $productIdFilter);
            }
        } else {
            $totalQb->select('COUNT(o.id)');
        }
        $this->applyAdminGiftCardScope($totalQb, $typeFilter, $includeGiftCards);
        if ($statusList !== []) {
            $totalQb->andWhere('o.status IN (:statuses)')->setParameter('statuses', $statusList);
        }
        if ($userIdFilter !== null) {
            $totalQb->andWhere('o.user = :user')->setParameter('user', $userIdFilter);
        }
        if ($since !== null) {
            $totalQb->andWhere('o.createdAt >= :since')->setParameter('since', $since);
        }
        if ($until !== null) {
            $totalQb->andWhere('o.createdAt <= :until')->setParameter('until', $until);
        }
        $total = (int) $totalQb->getQuery()->getSingleScalarResult();

        if ($total === 0) {
            return [[], 0];
        }

        // ID page
        $idQb = $this->createQueryBuilder('o');
        if ($needsItemsJoin) {
            $idQb->select('DISTINCT o.id, o.createdAt')->innerJoin('o.items', 'i');
            if ($vendorIdFilter !== null) {
                $idQb->andWhere('i.vendor = :vendor')->setParameter('vendor', $vendorIdFilter);
            }
            if ($productIdFilter !== null) {
                $idQb->andWhere('i.product = :product')->setParameter('product', $productIdFilter);
            }
        } else {
            $idQb->select('o.id');
        }
        $this->applyAdminGiftCardScope($idQb, $typeFilter, $includeGiftCards);
        if ($statusList !== []) {
            $idQb->andWhere('o.status IN (:statuses)')->setParameter('statuses', $statusList);
        }
        if ($userIdFilter !== null) {
            $idQb->andWhere('o.user = :user')->setParameter('user', $userIdFilter);
        }
        if ($since !== null) {
            $idQb->andWhere('o.createdAt >= :since')->setParameter('since', $since);
        }
        if ($until !== null) {
            $idQb->andWhere('o.createdAt <= :until')->setParameter('until', $until);
        }
        $idQb->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $idRows = $idQb->getQuery()->getScalarResult();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $idRows);
        if ($ids === []) {
            return [[], $total];
        }

        $orders = $this->createQueryBuilder('o')
            ->select('o', 'i', 'u', 'a')
            ->leftJoin('o.items', 'i')
            ->leftJoin('o.user', 'u')
            ->leftJoin('o.addresses', 'a')
            ->where('o.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        /** @var list<Order> $orders */
        return [$orders, $total];
    }

    /**
     * Find any order by id without scope restrictions. Admin-only
     * use. Eagerly loads items + addresses.
     */
    public function findByIdForAdmin(int $orderId): ?Order
    {
        $result = $this->createQueryBuilder('o')
            ->select('o', 'i', 'a')
            ->leftJoin('o.items', 'i')
            ->leftJoin('o.addresses', 'a')
            ->where('o.id = :oid')
            ->setParameter('oid', $orderId)
            ->getQuery()
            ->getOneOrNullResult();

        /** @var Order|null $result */
        return $result;
    }

    /**
     * Paginated list of orders that have AT LEAST ONE item belonging
     * to any of the given vendor ids. Most recent first.
     *
     * Used by GET /v3/vendor/orders. Vendor scope is enforced HERE
     * at fetch time, NOT in the controller, so even if controller
     * code drifts, the vendor isolation invariant holds.
     *
     * Items from OTHER vendors are NOT filtered out at this stage
     * (we return the whole Order entity); the controller's response
     * serializer is responsible for filtering. This split keeps the
     * domain query simple and lets a multi-vendor order be returned
     * even if only one of its items belongs to the requesting vendor.
     *
     * @param list<int> $vendorIds   Empty array → empty result
     * @param ?string $statusFilter  Restrict to a specific Order.status
     *                               value. Null = no status filter.
     * @return array{0: list<Order>, 1: int} Tuple of [orders, total_count].
     */
    /**
     * Apply the vendor-order list filters (status, free-text search, and
     * created-at date range) to a query builder that already joins items
     * as `i`. Search matches order reference, customer email/name, and
     * product name snapshot. Shared by the count + id-page queries so they
     * stay in lock-step.
     */
    private function applyVendorOrderFilters(
        QueryBuilder $qb,
        ?string $status,
        ?string $search,
        ?string $dateFrom,
        ?string $dateTo,
    ): void {
        // Vendors NEVER see orders still awaiting payment OR whose payment
        // failed — neither is a real sale with a fulfilment obligation. Applied
        // unconditionally (not just kept out of the status-filter whitelist),
        // so the default unfiltered list hides them too.
        $qb->andWhere('o.status NOT IN (:nonSaleStatuses)')
            ->setParameter('nonSaleStatuses', [Order::STATUS_PENDING_PAYMENT, Order::STATUS_FAILED]);

        if ($status !== null && $status !== '') {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%' . strtolower(trim($search)) . '%';
            $qb->leftJoin('o.user', 'u')
                ->andWhere(
                    '(LOWER(o.orderReference) LIKE :q '
                    . 'OR LOWER(i.productNameSnapshot) LIKE :q '
                    . 'OR LOWER(u.email) LIKE :q '
                    . 'OR LOWER(u.firstName) LIKE :q '
                    . 'OR LOWER(u.lastName) LIKE :q)'
                )
                ->setParameter('q', $term);
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $qb->andWhere('o.createdAt >= :dateFrom')
                ->setParameter('dateFrom', new \DateTimeImmutable($dateFrom . ' 00:00:00'));
        }

        if ($dateTo !== null && $dateTo !== '') {
            $qb->andWhere('o.createdAt <= :dateTo')
                ->setParameter('dateTo', new \DateTimeImmutable($dateTo . ' 23:59:59'));
        }
    }

    public function paginatedForVendorIds(
        array $vendorIds,
        int $limit,
        int $offset,
        ?string $statusFilter = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        if ($vendorIds === []) {
            return [[], 0];
        }
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        // Count distinct orders that have items in the vendor set.
        // DISTINCT against the join multiplication.
        $totalQb = $this->createQueryBuilder('o')
            ->select('COUNT(DISTINCT o.id)')
            ->innerJoin('o.items', 'i')
            ->where('i.vendor IN (:vendors)')
            ->setParameter('vendors', $vendorIds);
        $this->applyVendorOrderFilters($totalQb, $statusFilter, $search, $dateFrom, $dateTo);
        $total = (int) $totalQb->getQuery()->getSingleScalarResult();

        if ($total === 0) {
            return [[], 0];
        }

        // Page of distinct order ids first — paginate against orders only.
        $idQb = $this->createQueryBuilder('o')
            ->select('DISTINCT o.id, o.createdAt')
            ->innerJoin('o.items', 'i')
            ->where('i.vendor IN (:vendors)')
            ->setParameter('vendors', $vendorIds)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        $this->applyVendorOrderFilters($idQb, $statusFilter, $search, $dateFrom, $dateTo);
        $idRows = $idQb->getQuery()->getScalarResult();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $idRows);
        if ($ids === []) {
            return [[], $total];
        }

        // Hydrate the orders with all their items (incl. items from OTHER
        // vendors — the controller filters those out at serialisation).
        $orders = $this->createQueryBuilder('o')
            ->select('o', 'i')
            ->leftJoin('o.items', 'i')
            ->where('o.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        /** @var list<Order> $orders */
        return [$orders, $total];
    }

    /**
     * Find a single order by id, gated on the requesting vendor having
     * at least one item in it.
     *
     * Returns NULL if the order doesn't exist OR if it exists but
     * contains no items from the given vendor set. Callers MUST treat
     * a null result as 404 (not 403) to avoid leaking the existence
     * of orders the caller can't see.
     *
     * Returns the full Order with all items (incl. other vendors').
     * Controller serializer filters to caller's items.
     *
     * @param list<int> $vendorIds
     */
    public function findForVendorIds(int $orderId, array $vendorIds): ?Order
    {
        if ($vendorIds === []) {
            return null;
        }

        // Single query: order id matches AND it has at least one item
        // in the vendor set. The leftJoin on items is so hydration
        // returns the full collection (we then re-fetch separately for
        // simplicity).
        $hasItem = (int) $this->createQueryBuilder('o')
            ->select('COUNT(i.id)')
            ->innerJoin('o.items', 'i')
            ->where('o.id = :oid')
            ->andWhere('i.vendor IN (:vendors)')
            ->setParameter('oid', $orderId)
            ->setParameter('vendors', $vendorIds)
            ->getQuery()
            ->getSingleScalarResult();

        if ($hasItem === 0) {
            return null;
        }

        $result = $this->createQueryBuilder('o')
            ->select('o', 'i', 'a')
            ->leftJoin('o.items', 'i')
            ->leftJoin('o.addresses', 'a')
            ->where('o.id = :oid')
            ->setParameter('oid', $orderId)
            ->getQuery()
            ->getOneOrNullResult();

        /** @var Order|null $result */
        return $result;
    }
}
