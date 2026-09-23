<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<ChatPromptCategory>
 */
class ChatPromptCategoryRepository extends EntityRepository
{
    /**
     * Active categories for an audience ('customer' | 'vendor'), each with its
     * active prompts eager-loaded, ordered for display. Drives the picker.
     *
     * @return list<ChatPromptCategory>
     */
    public function findActiveForAudience(string $audience): array
    {
        $qb = $this->createQueryBuilder('c')
            ->addSelect('p')
            ->leftJoin('c.prompts', 'p', 'WITH', 'p.isActive = true')
            ->where('c.audience = :audience')
            ->andWhere('c.isActive = true')
            ->orderBy('c.sortOrder', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->addOrderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setParameter('audience', $audience);

        /** @var list<ChatPromptCategory> */
        return $qb->getQuery()->getResult();
    }
}
