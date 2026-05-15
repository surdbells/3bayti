<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityRepository;

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
}
