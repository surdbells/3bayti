<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use DateTimeImmutable;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for OtpAttempt entities.
 *
 * @extends EntityRepository<OtpAttempt>
 */
class OtpAttemptRepository extends EntityRepository
{
    /**
     * Find the most recent usable (not consumed, not exhausted, not
     * expired) OTP for a phone+purpose pair. Verify endpoints use
     * this to locate which OTP the presented code should match.
     *
     * Multiple OTPs can exist for the same phone/purpose if the user
     * spammed send-otp. We always check against the newest.
     */
    public function findLatestUsable(string $phone, string $purpose): ?OtpAttempt
    {
        $now = new DateTimeImmutable();
        return $this->createQueryBuilder('o')
            ->where('o.phone = :phone')
            ->andWhere('o.purpose = :purpose')
            ->andWhere('o.consumedAt IS NULL')
            ->andWhere('o.expiresAt > :now')
            ->andWhere('o.verifyAttempts < o.maxVerifyAttempts')
            ->setParameter('phone', $phone)
            ->setParameter('purpose', $purpose)
            ->setParameter('now', $now)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Count send-otp requests for a phone in the last N seconds.
     * Used by the rate-limiter (M1.5) as a cheap fallback when
     * Redis is unavailable; primary rate-limiting lives in Redis.
     */
    public function countRecentSendsForPhone(string $phone, int $withinSeconds): int
    {
        $cutoff = (new DateTimeImmutable())->modify("-{$withinSeconds} seconds");
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.phone = :phone')
            ->andWhere('o.createdAt > :cutoff')
            ->setParameter('phone', $phone)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Cleanup job — delete OTP rows that expired more than `daysOld`
     * days ago. The audit value of these rows decays after ~30 days;
     * we don't need to keep them forever.
     */
    public function purgeExpiredOlderThan(int $daysOld): int
    {
        $cutoff = (new DateTimeImmutable())->modify(sprintf('-%d days', $daysOld));
        return (int) $this->getEntityManager()
            ->createQuery(
                'DELETE FROM ' . OtpAttempt::class . ' o
                 WHERE o.expiresAt < :cutoff'
            )
            ->setParameter('cutoff', $cutoff)
            ->execute();
    }

    public function save(OtpAttempt $attempt, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($attempt);
        if ($flush) {
            $em->flush();
        }
    }
}
