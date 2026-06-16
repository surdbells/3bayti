<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityRepository;
use DateTimeImmutable;

/** @extends EntityRepository<CampaignItem> */
class CampaignItemRepository extends EntityRepository implements FlashCampaignItemFinder
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

    /**
     * Live, stock-tracking flash-campaign items referencing a product.
     * Mirrors {@see Campaign::isLiveAt()} in DQL (active + within window)
     * and only returns items whose stockRemaining is set.
     *
     * @return CampaignItem[]
     */
    public function findActiveFlashItemsForProduct(int $productId, DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('ci')
            ->join('ci.campaign', 'c')
            ->where('IDENTITY(ci.product) = :productId')
            ->andWhere('c.type = :flash')
            ->andWhere('c.isActive = true')
            ->andWhere('c.startsAt <= :now')
            ->andWhere('c.endsAt >= :now')
            ->andWhere('ci.stockRemaining IS NOT NULL')
            ->setParameter('productId', $productId)
            ->setParameter('flash', Campaign::TYPE_FLASH)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
