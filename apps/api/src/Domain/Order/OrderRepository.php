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
     * @return array{0: list<Order>, 1: int} Tuple of [orders, total_count].
     */
    public function paginatedForUser(User $user, int $limit, int $offset): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        // Total count: simple, no joins
        $total = (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        if ($total === 0) {
            return [[], 0];
        }

        // Page of order ids first (paginate against the orders table only;
        // joining items into a paginated query would multiply rows and break
        // limit/offset semantics).
        $idResult = $this->createQueryBuilder('o')
            ->select('o.id')
            ->where('o.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getScalarResult();

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

        $result = $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->andWhere('o.createdAt < :cutoff')
            ->setParameter('status', Order::STATUS_PENDING_PAYMENT)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('o.createdAt', 'ASC')
            ->setMaxResults($batchLimit)
            ->getQuery()
            ->getResult();

        /** @var list<Order> $result */
        return $result;
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
     * Admin-scoped paginated order list. Supports cross-cutting
     * filters that the customer + vendor surfaces don't expose:
     *   - status: any of Order::STATUS_* values, or null for all
     *   - userId: filter to a single customer
     *   - vendorId: filter to orders containing at least one item
     *     from a specific vendor
     *
     * Used by M3.1.7-D admin orders surface.
     *
     * @return array{0: list<Order>, 1: int}
     */
    public function paginatedForAdmin(
        int $limit,
        int $offset,
        ?string $statusFilter = null,
        ?int $userIdFilter = null,
        ?int $vendorIdFilter = null,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $until = null,
    ): array {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        // Count query
        $totalQb = $this->createQueryBuilder('o');
        if ($vendorIdFilter !== null) {
            $totalQb->select('COUNT(DISTINCT o.id)')
                ->innerJoin('o.items', 'i')
                ->where('i.vendor = :vendor')
                ->setParameter('vendor', $vendorIdFilter);
        } else {
            $totalQb->select('COUNT(o.id)');
        }
        if ($statusFilter !== null) {
            $totalQb->andWhere('o.status = :status')->setParameter('status', $statusFilter);
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
        if ($vendorIdFilter !== null) {
            $idQb->select('DISTINCT o.id, o.createdAt')
                ->innerJoin('o.items', 'i')
                ->where('i.vendor = :vendor')
                ->setParameter('vendor', $vendorIdFilter);
        } else {
            $idQb->select('o.id');
        }
        if ($statusFilter !== null) {
            $idQb->andWhere('o.status = :status')->setParameter('status', $statusFilter);
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
            ->select('o', 'i', 'u')
            ->leftJoin('o.items', 'i')
            ->leftJoin('o.user', 'u')
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
