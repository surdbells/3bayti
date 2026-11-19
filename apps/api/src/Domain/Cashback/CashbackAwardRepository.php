<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<CashbackAward>
 */
class CashbackAwardRepository extends EntityRepository
{
    public function save(CashbackAward $award): void
    {
        $em = $this->getEntityManager();
        $em->persist($award);
        $em->flush();
    }

    public function findOneByOrder(Order $order): ?CashbackAward
    {
        /** @var CashbackAward|null $a */
        $a = $this->findOneBy(['order' => $order]);
        return $a;
    }

    /**
     * Awards for several orders at once (order id => award), for list views.
     *
     * @param list<int> $orderIds
     * @return array<int, CashbackAward>
     */
    public function findByOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        /** @var list<CashbackAward> $rows */
        $rows = $this->createQueryBuilder('a')
            ->where('IDENTITY(a.order) IN (:ids)')
            ->setParameter('ids', $orderIds)
            ->getQuery()
            ->getResult();
        $out = [];
        foreach ($rows as $a) {
            $oid = $a->getOrder()->getId();
            if ($oid !== null) {
                $out[$oid] = $a;
            }
        }
        return $out;
    }

    /**
     * Re-read the award with a row lock (SELECT … FOR UPDATE). Must be called
     * inside a transaction. This is what makes release single-shot: a cron
     * run racing an admin "release now" serialises here and the loser sees
     * status=issued.
     */
    public function lockById(int $id): ?CashbackAward
    {
        $em = $this->getEntityManager();
        /** @var CashbackAward|null $a */
        $a = $em->find(CashbackAward::class, $id, LockMode::PESSIMISTIC_WRITE);
        if ($a !== null) {
            $em->refresh($a);
        }
        return $a;
    }

    /**
     * Pending awards the release cron should look at: the order was delivered
     * and its hold elapsed, OR the order ended cancelled/refunded/failed (those
     * get cancelled).
     *
     * @return list<int>
     */
    public function findDueIds(DateTimeImmutable $now, int $limit): array
    {
        $sql = <<<'SQL'
            SELECT a.id
              FROM cashback_awards a
              JOIN orders o ON o.id = a.order_id
             WHERE a.status = 'pending'
               AND (
                    (o.status = 'delivered'
                     AND o.delivered_at IS NOT NULL
                     AND o.delivered_at + (a.hold_days * INTERVAL '1 day') <= CAST(:now AS TIMESTAMPTZ)
                     AND NOT EXISTS (
                         SELECT 1 FROM order_return_requests r
                          WHERE r.order_id = o.id
                            AND r.status IN ('pending', 'approved', 'picked_up')
                     ))
                 OR o.status IN ('cancelled', 'refunded', 'failed')
               )
             ORDER BY a.id ASC
             LIMIT :lim
            SQL;
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, [
            'now' => $now->format('Y-m-d H:i:sP'),
            'lim' => max(1, $limit),
        ]);
        return array_map('intval', $rows);
    }

    /**
     * Per-rule award counts for one customer (pending/issued/reversed awards,
     * excluding the given order), for the per-customer limit.
     *
     * @param list<int> $ruleIds
     * @return array<int, int>
     */
    public function customerAwardCounts(int $userId, array $ruleIds, ?int $excludeOrderId = null): array
    {
        if ($ruleIds === []) {
            return [];
        }
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(l.rule) AS rule_id, COUNT(DISTINCT a.id) AS n')
            ->from(CashbackAwardLine::class, 'l')
            ->join('l.award', 'a')
            ->where('IDENTITY(a.user) = :uid')
            ->andWhere('IDENTITY(l.rule) IN (:ids)')
            ->andWhere('a.status IN (:st)')
            ->setParameter('uid', $userId)
            ->setParameter('ids', $ruleIds)
            ->setParameter('st', [CashbackAward::STATUS_PENDING, CashbackAward::STATUS_ISSUED, CashbackAward::STATUS_REVERSED])
            ->groupBy('l.rule');
        if ($excludeOrderId !== null) {
            $qb->andWhere('IDENTITY(a.order) <> :oid')->setParameter('oid', $excludeOrderId);
        }
        $out = [];
        foreach ($qb->getQuery()->getScalarResult() as $row) {
            $out[(int) $row['rule_id']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Admin ledger.
     *
     * @param array{status?: string, rule_id?: int, user_id?: int, order_reference?: string, date_from?: DateTimeImmutable, date_to?: DateTimeImmutable, limit: int, offset: int} $filters
     * @return array{items: list<CashbackAward>, total: int}
     */
    public function findFilteredPaginated(array $filters): array
    {
        $qb = $this->createQueryBuilder('a')
            ->join('a.order', 'o')->addSelect('o')
            ->join('a.user', 'u')->addSelect('u');
        if (isset($filters['status'])) {
            $qb->andWhere('a.status = :st')->setParameter('st', $filters['status']);
        }
        if (isset($filters['rule_id'])) {
            $qb->andWhere('EXISTS (SELECT 1 FROM ' . CashbackAwardLine::class . ' l2 WHERE l2.award = a AND IDENTITY(l2.rule) = :rid)')
                ->setParameter('rid', $filters['rule_id']);
        }
        if (isset($filters['user_id'])) {
            $qb->andWhere('IDENTITY(a.user) = :uid')->setParameter('uid', $filters['user_id']);
        }
        if (isset($filters['order_reference'])) {
            $qb->andWhere('UPPER(o.orderReference) LIKE :ref')
                ->setParameter('ref', '%' . strtoupper(addcslashes($filters['order_reference'], '%_')) . '%');
        }
        if (isset($filters['date_from'])) {
            $qb->andWhere('a.createdAt >= :df')->setParameter('df', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $qb->andWhere('a.createdAt <= :dt')->setParameter('dt', $filters['date_to']);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        /** @var list<CashbackAward> $items */
        $items = $qb->orderBy('a.id', 'DESC')
            ->setFirstResult($filters['offset'])
            ->setMaxResults($filters['limit'])
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array{items: list<CashbackAward>, total: int}
     */
    public function findForUserPaginated(User $user, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('a')
            ->join('a.order', 'o')->addSelect('o')
            ->where('a.user = :u')
            ->setParameter('u', $user);
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        /** @var list<CashbackAward> $items */
        $items = $qb->orderBy('a.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
        return ['items' => $items, 'total' => $total];
    }

    /**
     * Count + sum (and clawed-back sum) per status, optionally for one user.
     *
     * @return array<string, array{count: int, amount: string, reversed: string}>
     */
    public function totalsByStatus(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.status AS status, COUNT(a.id) AS n, SUM(a.amount) AS amt, SUM(a.reversedAmount) AS rev')
            ->groupBy('a.status');
        if ($user !== null) {
            $qb->where('a.user = :u')->setParameter('u', $user);
        }
        $out = [];
        foreach (CashbackAward::STATUSES as $s) {
            $out[$s] = ['count' => 0, 'amount' => '0.00', 'reversed' => '0.00'];
        }
        foreach ($qb->getQuery()->getScalarResult() as $row) {
            $out[(string) $row['status']] = [
                'count' => (int) $row['n'],
                'amount' => bcadd((string) ($row['amt'] ?? '0'), '0', 2),
                'reversed' => bcadd((string) ($row['rev'] ?? '0'), '0', 2),
            ];
        }
        return $out;
    }

    /**
     * Cashback gift-card money: redeemed (customer debits against orders) and
     * the outstanding spendable balance.
     *
     * @return array{redeemed: string, outstanding: string}
     */
    public function giftCardMoney(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        // Net of order-tagged credits: GiftCardOrderReversal gives the spend
        // of a failed/cancelled/refunded order back to the card as a CREDIT
        // row tagged with that order, so that money was never really spent.
        $redeemed = $conn->fetchOne(
            "SELECT COALESCE(SUM(CASE WHEN t.type = 'debit' THEN t.amount ELSE -t.amount END), 0)
               FROM gift_card_transactions t
               JOIN gift_cards g ON g.id = t.gift_card_id
              WHERE g.source = 'cashback'
                AND t.type IN ('debit', 'credit')
                AND (t.order_reference IS NOT NULL OR t.order_id IS NOT NULL)",
        );
        $outstanding = $conn->fetchOne(
            "SELECT COALESCE(SUM(g.balance), 0) FROM gift_cards g
              WHERE g.source = 'cashback' AND g.status IN ('active', 'partially_used')
                AND (g.expires_at IS NULL OR g.expires_at > now())",
        );
        return [
            'redeemed' => bcadd((string) ($redeemed ?: '0'), '0', 2),
            'outstanding' => bcadd((string) ($outstanding ?: '0'), '0', 2),
        ];
    }

    /**
     * Did checkout debit any CASHBACK gift card for this order? (Ledger
     * check behind orders.used_cashback_credit.)
     */
    public function orderSpentCashbackCredit(Order $order): bool
    {
        $n = $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM gift_card_transactions t
               JOIN gift_cards g ON g.id = t.gift_card_id
              WHERE g.source = 'cashback' AND t.type = 'debit'
                AND (t.order_id = :oid OR t.order_reference = :ref)",
            ['oid' => (int) $order->getId(), 'ref' => $order->getOrderReference()],
        );
        return (int) $n > 0;
    }

    /** Gift-card spend of this order already given back (order-tagged CREDIT rows). */
    public function giftCardRestoredForOrder(Order $order): string
    {
        $v = $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) FROM gift_card_transactions
              WHERE type = 'credit' AND (order_id = :oid OR order_reference = :ref)",
            ['oid' => (int) $order->getId(), 'ref' => $order->getOrderReference()],
        );
        return bcadd((string) ($v ?: '0'), '0', 2);
    }

    /**
     * The customer's paid PRODUCT orders (item-less gift-card / customization
     * orders don't count) placed before $orderId (all of them when null).
     * 0 = this is their first order.
     */
    public function countEarlierPaidProductOrders(User $user, ?int $orderId): int
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->where('o.user = :u')
            ->andWhere('o.paidAt IS NOT NULL')
            ->andWhere('o.items IS NOT EMPTY')
            ->setParameter('u', $user);
        if ($orderId !== null) {
            $qb->andWhere('o.id < :oid')->setParameter('oid', $orderId);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
