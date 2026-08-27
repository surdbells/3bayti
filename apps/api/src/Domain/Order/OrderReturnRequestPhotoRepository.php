<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<OrderReturnRequestPhoto>
 */
class OrderReturnRequestPhotoRepository extends EntityRepository
{
    public function save(OrderReturnRequestPhoto $photo): void
    {
        $em = $this->getEntityManager();
        $em->persist($photo);
        $em->flush();
    }

    /**
     * Find a photo by its id, only if it belongs to the named
     * return-request. Used by the photo-serve endpoint to enforce
     * that the photoId in the URL actually belongs to the returnId
     * in the URL, defense against id-only enumeration.
     */
    public function findByIdAndRequest(int $photoId, int $returnRequestId): ?OrderReturnRequestPhoto
    {
        return $this->findOneBy([
            'id' => $photoId,
            'returnRequest' => $returnRequestId,
        ]);
    }
}
