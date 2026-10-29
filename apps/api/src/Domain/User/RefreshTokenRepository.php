<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
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
     * End ONE signed-in device's session: every token of its family.
     * Used by /v3/auth/logout.
     *
     * Ending the family (not just the presented token) matters because an
     * already-rotated token is still re-issuable (RefreshController): if
     * only the current token were revoked, an older token of the same
     * device could bring the session back. Other devices are untouched.
     *
     * Rows written before family_id existed fall back to their own jti,
     * the same fallback RefreshToken::getFamilyId() applies.
     *
     * Returns the number of rows changed (see endSessions()).
     */
    public function revokeFamily(string $familyId, string $reason): int
    {
        return $this->endSessions(
            't.familyId = :scope OR (t.familyId IS NULL AND t.jti = :scope)',
            $familyId,
            $reason,
        );
    }

    /**
     * End EVERY session of a user, on every device. Used only by the
     * explicit /v3/auth/logout-all action. Password change/reset, admin
     * resets and account merges no longer call this: a customer stays
     * signed in until they log out themselves.
     *
     * Returns the number of rows changed (see endSessions()).
     */
    public function revokeAllForUser(User $user, string $reason): int
    {
        return $this->endSessions('t.user = :scope', $user, $reason);
    }

    /**
     * Re-point every refresh token of $from to $to (any state). Used by
     * the phone-claim account merge: devices signed in to the merged-away
     * account keep their session and continue as the surviving account,
     * because RefreshController re-issues for the ROW's user, not the
     * JWT's sub claim. Returns the number of rows moved.
     *
     * The rows are locked first (see lockRows()): a refresh of one of
     * them that is mid-flight commits before the UPDATE runs, so the row
     * it inserted (still on $from) is moved too instead of being left on
     * the retired account, which would sign that device out.
     */
    public function reassignUser(User $from, User $to): int
    {
        $em = $this->getEntityManager();

        return (int) $em->getConnection()->transactional(
            static function () use ($em, $from, $to): int {
                self::lockRows($em, 't.user = :scope', $from, activeOrRotatedOnly: false);

                return (int) $em->createQuery(
                    'UPDATE ' . RefreshToken::class . ' t
                     SET t.user = :to
                     WHERE t.user = :from'
                )
                    ->setParameter('to', $to)
                    ->setParameter('from', $from)
                    ->execute();
            },
        );
    }

    /**
     * Make every token matched by $scopeDql non-re-issuable, as two bulk
     * UPDATEs in one transaction:
     *
     *   1. tokens already revoked by ROTATION get their reason overwritten
     *      with $reason (their original revoked_at is kept), because a
     *      'rotated' token would otherwise still be re-issued;
     *   2. still-active tokens get revoked_at = now and $reason.
     *
     * Tokens already ended for another reason are left as they are. The
     * rotated tokens are retired FIRST so that, even outside a
     * transaction, a failure part-way can never leave an old token
     * re-issuable while the current one is dead.
     *
     * Both run after lockRows(), so a refresh of one of these tokens
     * that is mid-flight either finishes first (and the UPDATEs, each
     * with a fresh READ COMMITTED snapshot, also see the row it rotated
     * and the row it inserted) or waits and then finds its token ended
     * (RefreshController re-checks under the same lock).
     *
     * :now is bound as datetimetz_immutable explicitly: the inferred type
     * would drop the UTC offset and let the session time zone shift it.
     * DQL UPDATEs bypass entity lifecycle hooks; RefreshToken has none.
     *
     * Returns the total number of rows changed by both statements.
     */
    private function endSessions(string $scopeDql, User|string $scope, string $reason): int
    {
        $em = $this->getEntityManager();

        return (int) $em->getConnection()->transactional(
            static function () use ($em, $scopeDql, $scope, $reason): int {
                self::lockRows($em, $scopeDql, $scope, activeOrRotatedOnly: true);

                $retired = $em->createQuery(
                    'UPDATE ' . RefreshToken::class . ' t
                     SET t.revokedReason = :reason
                     WHERE (' . $scopeDql . ') AND t.revokedReason = :rotated'
                )
                    ->setParameter('reason', $reason)
                    ->setParameter('rotated', RefreshToken::REASON_ROTATED)
                    ->setParameter('scope', $scope)
                    ->execute();

                $revoked = $em->createQuery(
                    'UPDATE ' . RefreshToken::class . ' t
                     SET t.revokedAt = :now, t.revokedReason = :reason
                     WHERE (' . $scopeDql . ') AND t.revokedAt IS NULL'
                )
                    ->setParameter('now', new DateTimeImmutable(), Types::DATETIMETZ_IMMUTABLE)
                    ->setParameter('reason', $reason)
                    ->setParameter('scope', $scope)
                    ->execute();

                return (int) $retired + (int) $revoked;
            },
        );
    }

    /**
     * SELECT ... FOR UPDATE the rows a session write is about to change,
     * inside the caller's transaction. RefreshController locks the token
     * it rotates the same way, so the two serialise per row: whichever
     * comes second waits for the first to commit and then acts on the
     * committed state. Without it, a concurrent refresh could undo a
     * logout or slip a new live row past it (READ COMMITTED, no locks).
     *
     * $activeOrRotatedOnly limits the lock to the rows that can still be
     * re-issued (the only ones a refresh can rotate). ORDER BY id gives
     * every writer the same lock order, so two of them (say logout and
     * logout-all on the same user) can't deadlock.
     */
    private static function lockRows(
        EntityManagerInterface $em,
        string $scopeDql,
        User|string $scope,
        bool $activeOrRotatedOnly,
    ): void {
        $query = $em->createQuery(
            'SELECT t.id FROM ' . RefreshToken::class . ' t
             WHERE (' . $scopeDql . ')'
            . ($activeOrRotatedOnly ? ' AND (t.revokedAt IS NULL OR t.revokedReason = :rotated)' : '')
            . ' ORDER BY t.id ASC'
        )
            ->setParameter('scope', $scope);
        if ($activeOrRotatedOnly) {
            $query->setParameter('rotated', RefreshToken::REASON_ROTATED);
        }
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE)->getScalarResult();
    }

    /**
     * Cleanup job, delete tokens that expired more than `daysOld`
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
