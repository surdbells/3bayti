<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\GiftCard;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<GiftCard> */
class GiftCardRepository extends EntityRepository
{
    public function save(GiftCard $card): void
    {
        $em = $this->getEntityManager();
        $em->persist($card);
        $em->flush();
    }

    /** Find by raw 16-char code (no hyphens). */
    public function findByCode(string $code): ?GiftCard
    {
        return $this->findOneBy(['code' => strtoupper(str_replace('-', '', $code))]);
    }

    /** Find by purchase order reference (used in payment webhook). */
    public function findByPurchaseOrderReference(string $ref): ?GiftCard
    {
        return $this->findOneBy(['purchaseOrderReference' => $ref]);
    }

    /**
     * All cards where user is the buyer OR the assigned recipient.
     * @return list<GiftCard>
     */
    public function findByUser(User $user): array
    {
        /** @var list<GiftCard> $results */
        $results = $this->createQueryBuilder('g')
            ->where('g.buyerUser = :uid OR g.recipientUser = :uid')
            ->setParameter('uid', $user->getId())
            ->orderBy('g.id', 'DESC')
            ->getQuery()
            ->getResult();
        return $results;
    }

    /**
     * All spendable cards for a user (active or partially_used, not expired).
     * Used at checkout to show available balance.
     * @return list<GiftCard>
     */
    public function findSpendableByUser(User $user): array
    {
        /** @var list<GiftCard> $results */
        $results = $this->createQueryBuilder('g')
            ->where('g.recipientUser = :uid OR g.buyerUser = :uid')
            ->andWhere("g.status IN ('active', 'partially_used')")
            ->andWhere('(g.expiresAt IS NULL OR g.expiresAt > CURRENT_TIMESTAMP())')
            ->andWhere('g.balance > 0')
            ->setParameter('uid', $user->getId())
            ->orderBy('g.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
        return $results;
    }
}
