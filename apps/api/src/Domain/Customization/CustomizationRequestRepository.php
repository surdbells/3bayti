<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Customization;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<CustomizationRequest>
 */
class CustomizationRequestRepository extends EntityRepository
{
    public function save(CustomizationRequest $request): void
    {
        $em = $this->getEntityManager();
        $em->persist($request);
        $em->flush();
    }

    public function findById(int $id): ?CustomizationRequest
    {
        return $this->find($id);
    }

    /**
     * Resolve a request from the synthetic payment order's reference — used
     * by the Noon webhook's paid branch to call markPaid(). Mirrors
     * GiftCardRepository::findByPurchaseOrderReference.
     */
    public function findByPaymentOrderReference(string $reference): ?CustomizationRequest
    {
        return $this->findOneBy(['paymentOrderReference' => $reference]);
    }

    /**
     * Dup guard: does this customer already have an in-flight (non-terminal)
     * customization request for this product?
     */
    public function hasActiveForProductAndCustomer(int $productId, int $customerId): bool
    {
        $qb = $this->createQueryBuilder('cr')
            ->select('COUNT(cr.id)')
            ->where('IDENTITY(cr.product) = :productId')
            ->andWhere('IDENTITY(cr.customer) = :customerId')
            ->andWhere('cr.status IN (:active)')
            ->setParameter('productId', $productId)
            ->setParameter('customerId', $customerId)
            ->setParameter('active', CustomizationRequest::ACTIVE_STATUSES);

        return ((int) $qb->getQuery()->getSingleScalarResult()) > 0;
    }

    /**
     * Customer's own customization requests, newest first.
     *
     * @param array{status?: string, limit?: int, offset?: int} $filters
     *
     * @return array{items: list<CustomizationRequest>, total: int}
     */
    public function findForCustomerPaginated(User $customer, array $filters = []): array
    {
        $customerId = $customer->getId();
        if ($customerId === null) {
            return ['items' => [], 'total' => 0];
        }

        $qb = $this->createQueryBuilder('cr')
            ->where('IDENTITY(cr.customer) = :customerId')
            ->setParameter('customerId', $customerId);

        if (!empty($filters['status'])) {
            $qb->andWhere('cr.status = :status')
               ->setParameter('status', $filters['status']);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(cr.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $qb->orderBy('cr.requestedAt', 'DESC')
           ->addOrderBy('cr.id', 'DESC')
           ->setMaxResults($filters['limit'] ?? 20)
           ->setFirstResult($filters['offset'] ?? 0);

        /** @var list<CustomizationRequest> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Vendor's incoming customization requests (their products only),
     * newest first. Accepts the caller's full set of owned vendor ids so a
     * user owning multiple stores sees them all. Authorization is the
     * caller's job (pass only vendor ids the user owns).
     *
     * @param list<int> $vendorIds
     * @param array{status?: string, limit?: int, offset?: int} $filters
     *
     * @return array{items: list<CustomizationRequest>, total: int}
     */
    public function findForVendorPaginated(array $vendorIds, array $filters = []): array
    {
        if ($vendorIds === []) {
            return ['items' => [], 'total' => 0];
        }

        $qb = $this->createQueryBuilder('cr')
            ->where('IDENTITY(cr.vendor) IN (:vendorIds)')
            ->setParameter('vendorIds', $vendorIds);

        if (!empty($filters['status'])) {
            $qb->andWhere('cr.status = :status')
               ->setParameter('status', $filters['status']);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(cr.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $qb->orderBy('cr.requestedAt', 'DESC')
           ->addOrderBy('cr.id', 'DESC')
           ->setMaxResults($filters['limit'] ?? 20)
           ->setFirstResult($filters['offset'] ?? 0);

        /** @var list<CustomizationRequest> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
