<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\GiftCard;

use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
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

    /**
     * Cards that are due for recipient delivery (used by the
     * gift-cards:dispatch-scheduled cron).
     *
     * A card is due when:
     *   - status IN (active, partially_used) — it has been activated
     *     and is spendable (we don't deliver pending/voided/expired), AND
     *   - at least one channel is pending:
     *       (recipient_email IS NOT NULL AND email_delivered_at IS NULL) OR
     *       (recipient_phone IS NOT NULL AND sms_delivered_at  IS NULL), AND
     *   - scheduled_delivery_at IS NULL (send now) OR <= now (its time
     *     has come).
     *
     * Ordered oldest-first so a back-pressured queue drains fairly.
     *
     * @return list<GiftCard>
     */
    public function findDueForDelivery(DateTimeImmutable $now, int $limit = 100): array
    {
        /** @var list<GiftCard> $results */
        $results = $this->createQueryBuilder('g')
            ->where("g.status IN ('active', 'partially_used')")
            ->andWhere(
                '(g.recipientEmail IS NOT NULL AND g.emailDeliveredAt IS NULL) '
                . 'OR (g.recipientPhone IS NOT NULL AND g.smsDeliveredAt IS NULL)'
            )
            ->andWhere('g.scheduledDeliveryAt IS NULL OR g.scheduledDeliveryAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('g.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
        return $results;
    }
}
