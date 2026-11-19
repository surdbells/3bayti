<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<CashbackRule>
 */
class CashbackRuleRepository extends EntityRepository
{
    public function save(CashbackRule $rule): void
    {
        $em = $this->getEntityManager();
        $em->persist($rule);
        $em->flush();
    }

    /**
     * Rules switched on and inside their date window at $at, targets
     * fetch-joined (one query). Ordered by priority DESC, id ASC.
     *
     * @return list<CashbackRule>
     */
    public function findLive(DateTimeImmutable $at): array
    {
        /** @var list<CashbackRule> $rows */
        $rows = $this->createQueryBuilder('r')
            ->leftJoin('r.targets', 't')->addSelect('t')
            ->where('r.isActive = true')
            ->andWhere('r.validFrom IS NULL OR r.validFrom <= :at')
            ->andWhere('r.validUntil IS NULL OR r.validUntil >= :at')
            ->setParameter('at', $at)
            ->orderBy('r.priority', 'DESC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
        return $rows;
    }

    /**
     * Take a row lock on the given rules (inside a caller-owned transaction)
     * so two concurrent accruals can't both spend the last of a budget.
     *
     * @param list<int> $ids
     */
    public function lockByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->createQueryBuilder('r')
            ->select('r.id')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getScalarResult();
    }

    /**
     * Amount committed per rule. Each line counts at its share of what the
     * platform still pays (or already lost) on its award:
     *
     *     line.amount × (award.amount − reversed_amount + uncollected_amount) / award.amount
     *
     *   - pending / issued with no clawback: the full line (release-time
     *     amount once issued);
     *   - clawed back (partly or fully): the reversed part frees budget only
     *     when it was actually COLLECTED from the card; the written-off part
     *     (the customer had already spent it) stays spent until recovered;
     *   - cancelled awards free everything.
     *
     * @param list<int> $ruleIds
     * @return array<int, string> rule id => spent
     */
    public function spentByRuleIds(array $ruleIds): array
    {
        if ($ruleIds === []) {
            return [];
        }
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT l.rule_id,
                    SUM(CASE WHEN a.amount > 0
                             THEN l.amount * (a.amount - a.reversed_amount + a.uncollected_amount) / a.amount
                             ELSE 0 END) AS spent
               FROM cashback_award_lines l
               JOIN cashback_awards a ON a.id = l.award_id
              WHERE l.rule_id IN (:ids)
                AND a.status IN ('pending', 'issued', 'reversed')
              GROUP BY l.rule_id",
            ['ids' => array_map('intval', $ruleIds)],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $out = [];
        foreach ($rows as $row) {
            $spent = bcadd((string) ($row['spent'] ?? '0'), '0', 2);
            $out[(int) $row['rule_id']] = bccomp($spent, '0.00', 2) < 0 ? '0.00' : $spent;
        }
        return $out;
    }

    /**
     * Number of (non-cancelled) awards each rule has contributed to.
     *
     * @param list<int> $ruleIds
     * @return array<int, int>
     */
    public function awardCountsByRuleIds(array $ruleIds): array
    {
        if ($ruleIds === []) {
            return [];
        }
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(l.rule) AS rule_id, COUNT(DISTINCT a.id) AS n')
            ->from(CashbackAwardLine::class, 'l')
            ->join('l.award', 'a')
            ->where('IDENTITY(l.rule) IN (:ids)')
            ->andWhere('a.status IN (:st)')
            ->setParameter('ids', $ruleIds)
            ->setParameter('st', [CashbackAward::STATUS_PENDING, CashbackAward::STATUS_ISSUED, CashbackAward::STATUS_REVERSED])
            ->groupBy('l.rule')
            ->getQuery()
            ->getScalarResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['rule_id']] = (int) $row['n'];
        }
        return $out;
    }

    /** Has the rule ever contributed to any award (any status)? */
    public function isUsed(CashbackRule $rule): bool
    {
        $n = (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(CashbackAwardLine::class, 'l')
            ->where('l.rule = :r')
            ->setParameter('r', $rule)
            ->getQuery()
            ->getSingleScalarResult();
        return $n > 0;
    }

    /**
     * Admin list.
     *
     * @param array{status?: string, scope?: string, search?: string, limit: int, offset: int} $filters
     * @return array{items: list<CashbackRule>, total: int}
     */
    public function findFilteredPaginated(array $filters, DateTimeImmutable $now): array
    {
        $qb = $this->createQueryBuilder('r');
        $status = $filters['status'] ?? null;
        if ($status === 'active') {
            $qb->andWhere('r.isActive = true')
                ->andWhere('r.validFrom IS NULL OR r.validFrom <= :now')
                ->andWhere('r.validUntil IS NULL OR r.validUntil >= :now')
                ->setParameter('now', $now);
        } elseif ($status === 'inactive') {
            $qb->andWhere('r.isActive = false');
        } elseif ($status === 'scheduled') {
            $qb->andWhere('r.isActive = true')->andWhere('r.validFrom > :now')->setParameter('now', $now);
        } elseif ($status === 'expired') {
            $qb->andWhere('r.validUntil < :now')->setParameter('now', $now);
        }
        if (isset($filters['scope'])) {
            $qb->andWhere('r.scope = :scope')->setParameter('scope', $filters['scope']);
        }
        if (isset($filters['search'])) {
            $qb->andWhere('LOWER(r.name) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower(addcslashes($filters['search'], '%_')) . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        /** @var list<CashbackRule> $items */
        $items = $qb->orderBy('r.isActive', 'DESC')
            ->addOrderBy('r.priority', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult($filters['offset'])
            ->setMaxResults($filters['limit'])
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
