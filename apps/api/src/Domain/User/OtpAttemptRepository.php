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
     * Find the OTP row by its MessageCentral verificationId.
     * /v3/auth/confirm uses this to look up the row before
     * delegating verification to MessageCentral.
     */
    public function findByVerificationId(string $verificationId): ?OtpAttempt
    {
        return $this->createQueryBuilder('o')
            ->where('o.verificationId = :vid')
            ->setParameter('vid', $verificationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find the most recent usable (not consumed, not expired) OTP
     * for a phone+purpose pair. Used to detect "user already has a
     * pending OTP" cases — we can either return the existing row or
     * issue a new one depending on policy.
     */
    public function findLatestUsable(string $phone, string $purpose): ?OtpAttempt
    {
        $now = new DateTimeImmutable();
        return $this->createQueryBuilder('o')
            ->where('o.phone = :phone')
            ->andWhere('o.purpose = :purpose')
            ->andWhere('o.consumedAt IS NULL')
            ->andWhere('o.expiresAt > :now')
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
