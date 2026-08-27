<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<OrderReturnRequest>
 */
class OrderReturnRequestRepository extends EntityRepository
{
    public function save(OrderReturnRequest $request): void
    {
        $em = $this->getEntityManager();
        $em->persist($request);
        $em->flush();
    }

    public function findById(int $id): ?OrderReturnRequest
    {
        return $this->find($id);
    }

    /**
     * Customer-facing lookup for "my returns for this order".
     *
     * @return list<OrderReturnRequest>
     */
    public function findForCustomerByOrder(User $customer, int $orderId): array
    {
        $customerId = $customer->getId();
        if ($customerId === null) {
            return [];
        }

        $qb = $this->createQueryBuilder('rr')
            ->where('rr.customer = :customerId')
            ->andWhere('IDENTITY(rr.order) = :orderId')
            ->orderBy('rr.requestedAt', 'DESC')
            ->setParameter('customerId', $customerId)
            ->setParameter('orderId', $orderId);

        /** @var list<OrderReturnRequest> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Admin-facing lookup: every return request associated with the
     * given order id, regardless of customer. Used by GetAdminOrderController
     * (M3.2.X.18-H) to embed a returns summary inline in the order
     * detail response, avoids a second round-trip for ops.
     *
     * @return list<OrderReturnRequest>
     */
    public function findAllByOrder(int $orderId): array
    {
        $qb = $this->createQueryBuilder('rr')
            ->where('IDENTITY(rr.order) = :orderId')
            ->orderBy('rr.requestedAt', 'DESC')
            ->setParameter('orderId', $orderId);

        /** @var list<OrderReturnRequest> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Eligibility check: does this customer already have an
     * in-flight return request that overlaps the requested
     * OrderItems? Used by ReturnRequestEligibilityService to
     * prevent duplicate-request submission.
     *
     * "In-flight" = not in TERMINAL_STATUSES.
     *
     * @param list<int> $orderItemIds
     */
    public function hasOverlappingPendingForOrderItems(array $orderItemIds): bool
    {
        if ($orderItemIds === []) {
            return false;
        }

        $qb = $this->createQueryBuilder('rr')
            ->select('COUNT(rri.id)')
            ->innerJoin('rr.items', 'rri')
            ->where('IDENTITY(rri.orderItem) IN (:itemIds)')
            ->andWhere('rr.status NOT IN (:terminal)')
            ->setParameter('itemIds', $orderItemIds)
            ->setParameter('terminal', OrderReturnRequest::TERMINAL_STATUSES);

        return ((int) $qb->getQuery()->getSingleScalarResult()) > 0;
    }

    /**
     * Admin "all returns" paginated list with filter set.
     *
     * Supported filters:
     *   - status (string)
     *   - reason (string)
     *   - customerId (int)
     *   - vendorId (int) , joins through items
     *   - orderId (int)
     *   - since (DateTimeImmutable)
     *   - until (DateTimeImmutable)
     *   - limit (int, default 20)
     *   - offset (int, default 0)
     *
     * @param array{
     *   status?: string,
     *   reason?: string,
     *   customerId?: int,
     *   vendorId?: int,
     *   orderId?: int,
     *   since?: \DateTimeImmutable,
     *   until?: \DateTimeImmutable,
     *   limit?: int,
     *   offset?: int,
     * } $filters
     *
     * @return array{items: list<OrderReturnRequest>, total: int}
     */
    public function findFilteredPaginatedForAdmin(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('rr');

        if (isset($filters['vendorId'])) {
            // Filter to returns containing items from this vendor.
            // DISTINCT because a single request may have multiple
            // items from the same vendor.
            $qb->innerJoin('rr.items', 'rri_filter')
               ->andWhere('IDENTITY(rri_filter.vendor) = :vendorId')
               ->setParameter('vendorId', $filters['vendorId'])
               ->distinct();
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('rr.status = :status')
               ->setParameter('status', $filters['status']);
        }
        if (!empty($filters['reason'])) {
            $qb->andWhere('rr.reason = :reason')
               ->setParameter('reason', $filters['reason']);
        }
        if (isset($filters['customerId'])) {
            $qb->andWhere('IDENTITY(rr.customer) = :customerId')
               ->setParameter('customerId', $filters['customerId']);
        }
        if (isset($filters['orderId'])) {
            $qb->andWhere('IDENTITY(rr.order) = :orderId')
               ->setParameter('orderId', $filters['orderId']);
        }
        if (isset($filters['since'])) {
            $qb->andWhere('rr.requestedAt >= :since')
               ->setParameter('since', $filters['since']);
        }
        if (isset($filters['until'])) {
            $qb->andWhere('rr.requestedAt <= :until')
               ->setParameter('until', $filters['until']);
        }

        // Count BEFORE applying limit/offset.
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(DISTINCT rr.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Newest requests first, matches "review queue" UX.
        $qb->orderBy('rr.requestedAt', 'DESC')
           ->addOrderBy('rr.id', 'DESC');

        $qb->setMaxResults($filters['limit'] ?? 20)
           ->setFirstResult($filters['offset'] ?? 0);

        /** @var list<OrderReturnRequest> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Vendor "incoming returns" paginated list, filtered to returns
     * that contain at least one item the vendor sold. Vendor-side
     * authorization happens by caller passing the vendor's ID;
     * controller verifies the calling user owns that vendor.
     *
     * @param array{
     *   status?: string,
     *   limit?: int,
     *   offset?: int,
     * } $filters
     *
     * @return array{items: list<OrderReturnRequest>, total: int}
     */
    public function findForVendorPaginated(int $vendorId, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('rr')
            ->innerJoin('rr.items', 'rri_vendor')
            ->where('IDENTITY(rri_vendor.vendor) = :vendorId')
            ->setParameter('vendorId', $vendorId)
            ->distinct();

        if (!empty($filters['status'])) {
            $qb->andWhere('rr.status = :status')
               ->setParameter('status', $filters['status']);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(DISTINCT rr.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $qb->orderBy('rr.requestedAt', 'DESC')
           ->addOrderBy('rr.id', 'DESC');

        $qb->setMaxResults($filters['limit'] ?? 20)
           ->setFirstResult($filters['offset'] ?? 0);

        /** @var list<OrderReturnRequest> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
