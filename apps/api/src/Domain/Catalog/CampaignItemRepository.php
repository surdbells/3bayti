<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<CampaignItem> */
class CampaignItemRepository extends EntityRepository
{
    public function save(CampaignItem $item): void
    {
        $em = $this->getEntityManager();
        $em->persist($item);
        $em->flush();
    }

    public function delete(CampaignItem $item): void
    {
        $em = $this->getEntityManager();
        $em->remove($item);
        $em->flush();
    }
}
