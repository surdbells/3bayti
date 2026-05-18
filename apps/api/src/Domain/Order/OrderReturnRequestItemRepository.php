<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<OrderReturnRequestItem>
 *
 * Minimal repo. Items are normally accessed through the parent
 * OrderReturnRequest's items collection (Doctrine fetches the
 * association lazily). Direct repo queries are reserved for the
 * vendor-side queries that filter by vendor_id without needing to
 * materialize the parent — covered by the parent repo's
 * findForVendorPaginated method.
 */
class OrderReturnRequestItemRepository extends EntityRepository
{
    public function save(OrderReturnRequestItem $item): void
    {
        $em = $this->getEntityManager();
        $em->persist($item);
        $em->flush();
    }
}
