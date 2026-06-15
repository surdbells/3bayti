<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

use Bayti\Api\Domain\Order\OrderItem;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<Conversation>
 */
class ConversationRepository extends EntityRepository
{
    public function save(Conversation $conversation): void
    {
        $em = $this->getEntityManager();
        $em->persist($conversation);
        $em->flush();
    }

    /** Persist without flushing — for batching inside an existing UoW. */
    public function add(Conversation $conversation): void
    {
        $this->getEntityManager()->persist($conversation);
    }

    /** The single conversation for an order item, if one exists. */
    public function findByOrderItem(OrderItem|int $orderItem): ?Conversation
    {
        $id = $orderItem instanceof OrderItem ? $orderItem->getId() : $orderItem;
        return $this->createQueryBuilder('c')
            ->where('c.orderItem = :oi')->setParameter('oi', $id)
            ->getQuery()->getOneOrNullResult();
    }

    public function findByUuid(string $uuid): ?Conversation
    {
        return $this->createQueryBuilder('c')
            ->where('c.uuid = :u')->setParameter('u', $uuid)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * A vendor's conversation list (across all stores they own), newest
     * activity first.
     *
     * @param list<int> $vendorIds
     * @return array{items: list<Conversation>, total: int, unread: int}
     */
    public function findForVendor(array $vendorIds, int $limit, int $offset): array
    {
        if ($vendorIds === []) {
            return ['items' => [], 'total' => 0, 'unread' => 0];
        }
        $qb = $this->createQueryBuilder('c')
            ->where('c.vendor IN (:vids)')
            ->setParameter('vids', $vendorIds, ArrayParameterType::INTEGER);

        $total = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
        $unread = (int) (clone $qb)->select('COALESCE(SUM(c.vendorUnreadCount), 0)')
            ->getQuery()->getSingleScalarResult();

        $items = $qb->orderBy('c.lastMessageAt', 'DESC')->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)->setFirstResult($offset)
            ->getQuery()->getResult();

        return ['items' => $items, 'total' => $total, 'unread' => $unread];
    }

    /**
     * A customer's conversation list, newest activity first.
     *
     * @return array{items: list<Conversation>, total: int, unread: int}
     */
    public function findForCustomer(int $customerId, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.customer = :cid')->setParameter('cid', $customerId);

        $total = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
        $unread = (int) (clone $qb)->select('COALESCE(SUM(c.customerUnreadCount), 0)')
            ->getQuery()->getSingleScalarResult();

        $items = $qb->orderBy('c.lastMessageAt', 'DESC')->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)->setFirstResult($offset)
            ->getQuery()->getResult();

        return ['items' => $items, 'total' => $total, 'unread' => $unread];
    }

    /**
     * The distinct vendors a customer has any conversation with, ordered
     * by name. Backs the store-grouped legacy inbox (read-messages).
     *
     * @return list<\Bayti\Api\Domain\Catalog\Vendor>
     */
    public function findDistinctVendorsForCustomer(int $customerId): array
    {
        return $this->createQueryBuilder('c')
            ->select('DISTINCT v')
            ->join('c.vendor', 'v')
            ->where('c.customer = :cid')->setParameter('cid', $customerId)
            ->orderBy('v.name', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Total unread messages for a customer across all their conversations.
     * Cheap aggregate for badge polling (no row hydration).
     */
    public function unreadCountForCustomer(int $customerId): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COALESCE(SUM(c.customerUnreadCount), 0)')
            ->where('c.customer = :cid')->setParameter('cid', $customerId)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Total unread messages for a vendor across all their stores.
     *
     * @param list<int> $vendorIds
     */
    public function unreadCountForVendor(array $vendorIds): int
    {
        if ($vendorIds === []) {
            return 0;
        }
        return (int) $this->createQueryBuilder('c')
            ->select('COALESCE(SUM(c.vendorUnreadCount), 0)')
            ->where('c.vendor IN (:vids)')
            ->setParameter('vids', $vendorIds, ArrayParameterType::INTEGER)
            ->getQuery()->getSingleScalarResult();
    }
}
