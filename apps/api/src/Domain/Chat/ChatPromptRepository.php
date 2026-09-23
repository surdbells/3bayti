<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<ChatPrompt>
 */
class ChatPromptRepository extends EntityRepository
{
    /**
     * Resolve a tapped prompt for sending — active prompts only, restricted to
     * the caller's audience so a customer can't send a vendor template (or
     * vice versa). Returns null if not found / inactive / wrong audience.
     */
    public function findActiveForAudience(int $id, string $audience): ?ChatPrompt
    {
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.category', 'c')
            ->where('p.id = :id')
            ->andWhere('p.isActive = true')
            ->andWhere('c.isActive = true')
            ->andWhere('c.audience = :audience')
            ->setParameter('id', $id)
            ->setParameter('audience', $audience)
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
