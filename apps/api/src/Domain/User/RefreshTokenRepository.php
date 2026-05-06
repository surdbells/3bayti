<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use DateTimeImmutable;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for RefreshToken entities.
 *
 * @extends EntityRepository<RefreshToken>
 */
class RefreshTokenRepository extends EntityRepository
{
    /**
     * Look up a refresh token by its jti claim. The auth /v3/auth/refresh
     * endpoint uses this on every request.
     */
    public function findByJti(string $jti): ?RefreshToken
    {
        return $this->createQueryBuilder('t')
            ->where('t.jti = :jti')
            ->setParameter('jti', $jti)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * All currently-active refresh tokens for a user. Used for the
     * "active sessions" UI and for logout-everywhere.
     *
     * @return RefreshToken[]
     */
    public function findActiveForUser(User $user): array
    {
        $now = new DateTimeImmutable();
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.revokedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->orderBy('t.issuedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Revoke every active refresh token for a user. Used by
     * /v3/auth/logout-all and as a side effect of password change.
     *
     * Returns the number of tokens revoked. Done as a single bulk
     * UPDATE for efficiency — Doctrine doesn't fire entity lifecycle
     * hooks for DQL UPDATE, but RefreshToken has none anyway.
     */
    public function revokeAllForUser(User $user, string $reason): int
    {
        $now = new DateTimeImmutable();
        return (int) $this->getEntityManager()
            ->createQuery(
                'UPDATE ' . RefreshToken::class . ' t
                 SET t.revokedAt = :now, t.revokedReason = :reason
                 WHERE t.user = :user AND t.revokedAt IS NULL'
            )
            ->setParameter('now', $now)
            ->setParameter('reason', $reason)
            ->setParameter('user', $user)
            ->execute();
    }

    /**
     * Cleanup job — delete tokens that expired more than `daysOld`
     * days ago. Run nightly via a cron in M5; for M1 we just have
     * the method ready.
     *
     * Tokens that have been revoked but are still within their TTL
     * are kept for the audit trail; only ones that have actually
     * expired AND are old are purged.
     */
    public function purgeExpiredOlderThan(int $daysOld): int
    {
        $cutoff = (new DateTimeImmutable())->modify(sprintf('-%d days', $daysOld));
        return (int) $this->getEntityManager()
            ->createQuery(
                'DELETE FROM ' . RefreshToken::class . ' t
                 WHERE t.expiresAt < :cutoff'
            )
            ->setParameter('cutoff', $cutoff)
            ->execute();
    }

    public function save(RefreshToken $token, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($token);
        if ($flush) {
            $em->flush();
        }
    }
}
