<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\TryOn;

use DateTimeImmutable;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<TryOnJob>
 */
class TryOnJobRepository extends EntityRepository
{
    /**
     * Atomically claim the next queued job for the worker and flip it to
     * 'processing' (incrementing attempts) in the SAME statement, so two
     * overlapping cron runs can never grab the same row (FOR UPDATE SKIP
     * LOCKED). Returns the claimed id, or null when the queue is empty.
     */
    public function claimNextQueuedId(): ?int
    {
        $sql = <<<'SQL'
            UPDATE tryon_jobs
            SET status = 'processing', attempts = attempts + 1, started_at = now(), updated_at = now()
            WHERE id = (
                SELECT id FROM tryon_jobs
                WHERE status = 'queued'
                ORDER BY id ASC
                FOR UPDATE SKIP LOCKED
                LIMIT 1
            )
            RETURNING id
        SQL;

        $id = $this->getEntityManager()->getConnection()->fetchOne($sql);
        return $id === false || $id === null ? null : (int) $id;
    }

    public function findByReference(string $reference): ?TryOnJob
    {
        return $this->findOneBy(['jobReference' => $reference]);
    }

    /**
     * Fail jobs stuck in 'processing' longer than the given window (the worker
     * crashed mid-generation, or the image API hung past its timeout), so a
     * never-finishing job never blocks the customer's poll forever. Returns the
     * number of rows reaped.
     */
    public function failStuckProcessing(int $olderThanSeconds): int
    {
        $sql = <<<'SQL'
            UPDATE tryon_jobs
            SET status = 'failed',
                error_sample = COALESCE(error_sample, 'Timed out while processing.'),
                finished_at = now(),
                updated_at = now()
            WHERE status = 'processing'
              AND started_at < now() - make_interval(secs => :secs)
        SQL;

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            $sql,
            ['secs' => max(1, $olderThanSeconds)],
        );
    }

    /**
     * Succeeded jobs whose generated image is older than the retention cutoff
     * and still has a stored file — the TTL sweep deletes the file and clears
     * the reference.
     *
     * @return list<TryOnJob>
     */
    public function findExpiredWithResult(DateTimeImmutable $before, int $limit = 200): array
    {
        /** @var list<TryOnJob> $rows */
        $rows = $this->createQueryBuilder('j')
            ->where('j.status = :s')
            ->andWhere('j.finishedAt < :before')
            ->andWhere('j.resultImagePath IS NOT NULL')
            ->setParameter('s', TryOnJob::STATUS_SUCCEEDED)
            ->setParameter('before', $before)
            ->orderBy('j.finishedAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Terminal jobs (succeeded/failed) whose raw source photo was never deleted
     * — e.g. a worker OOM-killed / restarted mid-generation so the processor's
     * finally never ran, then reaped to 'failed' by failStuckProcessing() (which
     * only flips status). Their photo must still be deleted to honour the
     * "raw photo removed as soon as processing finishes" privacy guarantee.
     *
     * @return list<TryOnJob>
     */
    public function findTerminalWithSourceFile(int $limit = 200): array
    {
        /** @var list<TryOnJob> $rows */
        $rows = $this->createQueryBuilder('j')
            ->where('j.status IN (:terminal)')
            ->andWhere("j.sourceImagePath <> ''")
            ->setParameter('terminal', [TryOnJob::STATUS_SUCCEEDED, TryOnJob::STATUS_FAILED])
            ->orderBy('j.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * All jobs for a user that still reference a stored file (source or
     * result), for the account-deletion cleanup hook.
     *
     * @return list<TryOnJob>
     */
    public function findWithFilesForUser(int $userId): array
    {
        /** @var list<TryOnJob> $rows */
        $rows = $this->createQueryBuilder('j')
            ->where('j.userId = :uid')
            ->andWhere("(j.resultImagePath IS NOT NULL OR j.sourceImagePath <> '')")
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** Count a user's jobs created since a cutoff (a coarse per-day cap). */
    public function countForUserSince(int $userId, DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.userId = :uid')
            ->andWhere('j.createdAt >= :since')
            ->setParameter('uid', $userId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
