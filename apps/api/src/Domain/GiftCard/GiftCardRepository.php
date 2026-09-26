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
     * Batch lookup of gift cards by their purchase-order references,
     * keyed by reference. Used by the orders LIST serialization to
     * resolve, in ONE query, which of the page's orders are gift-card
     * purchases (avoids an N+1 lookup per order).
     *
     * References with no matching card simply don't appear in the map.
     * A blank input returns an empty map. If two cards somehow share a
     * reference (the column is UNIQUE, so this shouldn't happen) the
     * last one wins, acceptable for the display-only use here.
     *
     * @param list<string> $refs
     * @return array<string, GiftCard> keyed by purchaseOrderReference
     */
    public function findByPurchaseOrderReferences(array $refs): array
    {
        $refs = array_values(array_unique(array_filter($refs, static fn ($r): bool => $r !== '')));
        if ($refs === []) {
            return [];
        }

        /** @var list<GiftCard> $cards */
        $cards = $this->createQueryBuilder('g')
            ->where('g.purchaseOrderReference IN (:refs)')
            ->setParameter('refs', $refs)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($cards as $card) {
            $ref = $card->getPurchaseOrderReference();
            if ($ref !== null) {
                $map[$ref] = $card;
            }
        }
        return $map;
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
     * The user's WALLET: cards they may actually spend (active or
     * partially_used, unexpired, with balance).
     *
     * Ownership mirrors GiftCard::isSpendableBy():
     *   - cards assigned to them (they redeemed the code, including a buyer
     *     who claimed back a card they had bought as a gift), OR
     *   - cards they bought for THEMSELVES: no recipient was designated
     *     (no name / email / phone) and nobody has claimed them.
     *
     * A card bought as a gift for someone else therefore never shows up in
     * the buyer's spendable balance, the recipient's to spend, unless the
     * buyer explicitly redeems it back to their own account.
     *
     * @return list<GiftCard>
     */
    public function findSpendableByUser(User $user): array
    {
        /** @var list<GiftCard> $results */
        $results = $this->createQueryBuilder('g')
            ->where(
                'g.recipientUser = :uid'
                . ' OR (g.buyerUser = :uid AND g.recipientUser IS NULL'
                . ' AND g.recipientName IS NULL AND g.recipientEmail IS NULL AND g.recipientPhone IS NULL)'
            )
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
     *   - status IN (active, partially_used), it has been activated
     *     and is spendable (we don't deliver pending/voided/expired), AND
     *   - at least one DELIVERABLE channel is pending:
     *       (recipient_email IS NOT NULL AND email_delivered_at IS NULL) OR
     *       (recipient_phone IS NOT NULL AND sms_delivered_at  IS NULL), AND
     *   - scheduled_delivery_at IS NULL (send now) OR <= now (its time
     *     has come).
     *
     * SMS gating ($smsEnabled): when no real SMS provider is wired
     * (NullSmsSender), deliverSms() can never mark sms_delivered_at, so a
     * card with a recipient phone would satisfy the pending-channel test on
     * EVERY run forever — the dispatcher re-fetches it each cycle and
     * "processes" it without ever delivering anything (email-once-then-stuck
     * for a two-channel card, or never-deliverable for a phone-only card).
     * To mirror the manual-send rule ("deliver whatever we actually can"),
     * the SMS leg of the pending-channel test is dropped when SMS is off, so
     * only the email channel makes a card due. Phone-only cards then simply
     * aren't selected until SMS is configured (nothing we can do for them),
     * instead of churning the queue. Pass $smsEnabled from
     * GiftCardDeliveryService::isSmsEnabled().
     *
     * Ordered oldest-first so a back-pressured queue drains fairly.
     *
     * @return list<GiftCard>
     */
    public function findDueForDelivery(DateTimeImmutable $now, int $limit = 100, bool $smsEnabled = true): array
    {
        $qb = $this->createQueryBuilder('g')
            // Join the (optional) claimed recipient account so a card the buyer
            // left without a delivery email/phone can still be reached at the
            // contact the recipient registered when they claimed it. Mirrors
            // GiftCard::needs*DeliveryToRecipient() + effectiveRecipient*().
            ->leftJoin('g.recipientUser', 'r')
            ->where("g.status IN ('active', 'partially_used')");

        if ($smsEnabled) {
            // Either channel being pending makes the card due.
            $qb->andWhere('(' . $this->emailDuePredicate() . ') OR (' . $this->smsDuePredicate() . ')');
        } else {
            // SMS is a no-op sender: only a pending email channel makes a card
            // due (a phone-only card we cannot yet SMS is left for when SMS is
            // configured, instead of churning the queue forever).
            $qb->andWhere($this->emailDuePredicate());
        }

        /** @var list<GiftCard> $results */
        $results = $qb
            ->andWhere('g.scheduledDeliveryAt IS NULL OR g.scheduledDeliveryAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('g.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
        return $results;
    }

    /**
     * Scheduled cards whose delivery moment has PASSED but which we cannot
     * deliver on any channel — the silent-casualty detector for the
     * gift-cards:dispatch-scheduled cron's observability.
     *
     * These are active/partially_used gifts with a past scheduled_delivery_at,
     * nothing delivered on either channel, and NO reachable contact for the
     * channels we can actually send on (no email at all, and — when SMS is off
     * or absent — no phone either). findDueForDelivery() excludes them by
     * design (there is nothing to send), so without this they would vanish from
     * every "Found N / delivered N" report and never be noticed. The dispatcher
     * logs them as a WARNING so an operator can reach out manually (e.g. copy +
     * WhatsApp) until the missing channel is wired.
     *
     * @return list<GiftCard>
     */
    public function findUndeliverableScheduled(DateTimeImmutable $now, bool $smsEnabled = true, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('g')
            ->leftJoin('g.recipientUser', 'r')
            ->where("g.status IN ('active', 'partially_used')")
            ->andWhere('g.scheduledDeliveryAt IS NOT NULL AND g.scheduledDeliveryAt <= :now')
            ->andWhere('g.emailDeliveredAt IS NULL AND g.smsDeliveredAt IS NULL')
            ->setParameter('now', $now);

        // Undeliverable = no reachable email, AND (SMS off OR no reachable phone).
        if ($smsEnabled) {
            $qb->andWhere('NOT (' . $this->reachableEmailPredicate() . ') AND NOT (' . $this->reachablePhonePredicate() . ')');
        } else {
            $qb->andWhere('NOT (' . $this->reachableEmailPredicate() . ')');
        }

        /** @var list<GiftCard> $results */
        $results = $qb
            ->orderBy('g.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
        return $results;
    }

    /**
     * DQL fragment: the card is a DESIGNATED gift (the buyer named any
     * recipient detail), the precondition for falling back to the claimed
     * account's contact. A self-purchase top-up names no recipient and so is
     * never auto-delivered. Assumes the recipientUser is joined as `r`.
     */
    private function giftPredicate(): string
    {
        return '(g.recipientName IS NOT NULL OR g.recipientEmail IS NOT NULL OR g.recipientPhone IS NOT NULL)';
    }

    /** DQL: there is an email we can reach the recipient at (buyer-provided or claimed account). */
    private function reachableEmailPredicate(): string
    {
        return '(g.recipientEmail IS NOT NULL OR (r.email IS NOT NULL AND ' . $this->giftPredicate() . '))';
    }

    /** DQL: there is a phone we can reach the recipient at (buyer-provided or claimed account). */
    private function reachablePhonePredicate(): string
    {
        return '(g.recipientPhone IS NOT NULL OR (r.phone IS NOT NULL AND ' . $this->giftPredicate() . '))';
    }

    /** DQL: the email channel is pending AND reachable. */
    private function emailDuePredicate(): string
    {
        return 'g.emailDeliveredAt IS NULL AND ' . $this->reachableEmailPredicate();
    }

    /** DQL: the SMS channel is pending AND reachable. */
    private function smsDuePredicate(): string
    {
        return 'g.smsDeliveredAt IS NULL AND ' . $this->reachablePhonePredicate();
    }

    /**
     * Admin list query, paginated, filterable, search-able.
     *
     * Joins the buyer + recipient user eagerly (addSelect) so the admin
     * serializer can render purchaser/recipient emails with NO N+1: one
     * query for the page, one for the count. The ledger is NOT loaded
     * here (it's only needed in the detail endpoint) so the list stays
     * cheap regardless of how many transactions a card has.
     *
     * Supported $filters keys (all optional; null/absent = no filter):
     *   - search       string , case-insensitive substring match across
     *                            code (raw, hyphens stripped + UPPER),
     *                            recipient_email, recipient_name,
     *                            buyer.email
     *   - status       string , exact GiftCard::STATUS_* match
     *   - minBalance   string , balance >= (decimal string)
     *   - maxBalance   string , balance <= (decimal string)
     *   - minValue     string , denomination >= (decimal string)
     *   - maxValue     string , denomination <= (decimal string)
     *   - createdFrom  DateTimeImmutable, created_at >=
     *   - createdTo    DateTimeImmutable, created_at <=
     *   - delivered    bool   , true: email OR sms delivered;
     *                            false: neither channel delivered
     *   - limit        int     (default 20)
     *   - offset       int     (default 0)
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<GiftCard>, total: int}
     */
    public function findPaginatedForAdmin(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('g')
            ->leftjoin('g.buyerUser', 'b')
            ->leftJoin('g.recipientUser', 'r');

        if (!empty($filters['search'])) {
            $term = (string) $filters['search'];
            // A code search wants the raw stored form (no hyphens, UPPER);
            // the contact searches want a plain lowercased LIKE.
            $codeNeedle = strtoupper(str_replace('-', '', $term));
            $qb->andWhere(
                'g.code LIKE :codeNeedle '
                . 'OR LOWER(g.recipientEmail) LIKE :needle '
                . 'OR LOWER(g.recipientName) LIKE :needle '
                . 'OR LOWER(b.email) LIKE :needle'
            )
                ->setParameter('codeNeedle', '%' . $codeNeedle . '%')
                ->setParameter('needle', '%' . mb_strtolower($term) . '%');
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('g.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['minBalance'])) {
            $qb->andWhere('g.balance >= :minBalance')
                ->setParameter('minBalance', $filters['minBalance']);
        }
        if (isset($filters['maxBalance'])) {
            $qb->andWhere('g.balance <= :maxBalance')
                ->setParameter('maxBalance', $filters['maxBalance']);
        }
        if (isset($filters['minValue'])) {
            $qb->andWhere('g.denomination >= :minValue')
                ->setParameter('minValue', $filters['minValue']);
        }
        if (isset($filters['maxValue'])) {
            $qb->andWhere('g.denomination <= :maxValue')
                ->setParameter('maxValue', $filters['maxValue']);
        }

        if (!empty($filters['createdFrom'])) {
            $qb->andWhere('g.createdAt >= :createdFrom')
                ->setParameter('createdFrom', $filters['createdFrom']);
        }
        if (!empty($filters['createdTo'])) {
            $qb->andWhere('g.createdAt <= :createdTo')
                ->setParameter('createdTo', $filters['createdTo']);
        }

        if (isset($filters['delivered'])) {
            if ($filters['delivered'] === true) {
                $qb->andWhere('g.emailDeliveredAt IS NOT NULL OR g.smsDeliveredAt IS NOT NULL');
            } else {
                $qb->andWhere('g.emailDeliveredAt IS NULL AND g.smsDeliveredAt IS NULL');
            }
        }

        // Count BEFORE pagination + before the eager addSelect (a join
        // fetch would break the COUNT DISTINCT). Clone the filtered qb.
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(g.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Eager-load the joined users only for the page query.
        $qb->addSelect('b')->addSelect('r')
            ->orderBy('g.id', 'DESC')
            ->setMaxResults((int) ($filters['limit'] ?? 20))
            ->setFirstResult((int) ($filters['offset'] ?? 0));

        /** @var list<GiftCard> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Detail fetch for the admin endpoint, eager-loads the full
     * transaction ledger + buyer/recipient in ONE query so the detail
     * serializer never lazy-loads. Returns null when not found.
     */
    public function findByIdForAdmin(int $id): ?GiftCard
    {
        /** @var GiftCard|null $card */
        $card = $this->createQueryBuilder('g')
            ->leftJoin('g.buyerUser', 'b')->addSelect('b')
            ->leftJoin('g.recipientUser', 'r')->addSelect('r')
            ->leftJoin('g.transactions', 't')->addSelect('t')
            ->where('g.id = :id')
            ->setParameter('id', $id)
            ->orderBy('t.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
        return $card;
    }
}
