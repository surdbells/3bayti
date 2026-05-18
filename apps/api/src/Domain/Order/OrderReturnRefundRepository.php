<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<OrderReturnRefund>
 */
class OrderReturnRefundRepository extends EntityRepository
{
    public function save(OrderReturnRefund $refund): void
    {
        $em = $this->getEntityManager();
        $em->persist($refund);
        $em->flush();
    }
}
