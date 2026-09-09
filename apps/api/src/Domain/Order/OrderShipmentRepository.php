<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\Catalog\Vendor;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<OrderShipment>
 */
class OrderShipmentRepository extends EntityRepository
{
    public function save(OrderShipment $shipment): void
    {
        $em = $this->getEntityManager();
        $em->persist($shipment);
        $em->flush();
    }

    /** The shipment for one vendor's slice of an order, if it exists. */
    public function findForOrderAndVendor(Order $order, Vendor $vendor): ?OrderShipment
    {
        return $this->findOneBy(['order' => $order, 'vendor' => $vendor]);
    }

    /** @return OrderShipment[] */
    public function findForOrder(Order $order): array
    {
        return $this->findBy(['order' => $order]);
    }

    /** Look a shipment up by the provider's own id (OTO `otoId`), for the webhook. */
    public function findByProviderOrderId(string $providerOrderId): ?OrderShipment
    {
        return $this->findOneBy(['providerOrderId' => $providerOrderId]);
    }
}
