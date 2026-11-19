<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\GiftCard;

use Bayti\Api\Domain\Order\Order;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives gift-card balance back when the order that spent it does not go
 * through: the payment failed, the order was cancelled, or it was
 * refunded. Money goes back to the tender it came from: the gateway
 * part is refunded through Noon by the caller; this class handles the
 * gift-card part.
 *
 * The ledger is the source of truth. For each card, what the order still
 * holds = (debits tagged with the order) − (credits tagged with the
 * order). A restore credits at most that, so:
 *   - it is idempotent (a second call finds nothing outstanding),
 *   - partial restores (partial refunds) add up and can never exceed
 *     what the order took,
 *   - concurrent callers (webhook vs reconcile cron vs admin) cannot
 *     double-credit: the cards are locked FOR UPDATE before the ledger
 *     is read, so the second caller sees the first one's credit.
 *
 * Voided cards are skipped (a voided card is terminal); the skipped
 * amount is logged for ops and not counted as restored.
 */
class GiftCardOrderReversal
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Restore up to $limit (null = everything still held) of the gift-card
     * balance this order spent. Cards are restored newest-debit first.
     * Flushes. Returns the amount actually restored ("0.00" when nothing
     * was held, e.g. an order that used no gift card).
     */
    public function restoreForOrder(Order $order, string $reason, ?string $limit = null): string
    {
        // Cheap exit: most orders never touched a gift card.
        if (bccomp($order->getGiftCardAmount(), '0.00', 2) <= 0) {
            return '0.00';
        }
        if ($limit !== null && bccomp($limit, '0.00', 2) <= 0) {
            return '0.00';
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $restored = $this->restoreLocked($order, $reason, $limit);
            $this->em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->logger->error('gift_card.order_restore_failed', [
                'order_id'        => $order->getId(),
                'order_reference' => $order->getOrderReference(),
                'error'           => $e->getMessage(),
            ]);
            throw $e;
        }

        if (bccomp($restored, '0.00', 2) > 0) {
            $this->logger->info('gift_card.order_restored', [
                'order_id'        => $order->getId(),
                'order_reference' => $order->getOrderReference(),
                'amount'          => $restored,
                'reason'          => $reason,
            ]);
        }

        return $restored;
    }

    /**
     * Gift-card balance this order still holds (debits − credits across
     * all cards). "0.00" when it used none or everything was given back.
     */
    public function outstandingForOrder(Order $order): string
    {
        if (bccomp($order->getGiftCardAmount(), '0.00', 2) <= 0) {
            return '0.00';
        }
        $total = '0.00';
        foreach ($this->outstandingByCard($order) as $amount) {
            $total = bcadd($total, $amount, 2);
        }
        return $total;
    }

    /**
     * True once any of this order's gift-card spend has been given back.
     * Used to stop an admin from re-activating such an order (it would
     * then be "paid" with money the customer already has back).
     */
    public function hasRestoredForOrder(Order $order): bool
    {
        if (bccomp($order->getGiftCardAmount(), '0.00', 2) <= 0) {
            return false;
        }
        $count = $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM gift_card_transactions
              WHERE type = 'credit' AND " . self::ORDER_MATCH,
            ['oid' => (int) $order->getId(), 'ref' => $order->getOrderReference()],
        );
        return (int) $count > 0;
    }

    private const ORDER_MATCH = '(order_id = :oid OR order_reference = :ref)';

    private function restoreLocked(Order $order, string $reason, ?string $limit): string
    {
        $conn   = $this->em->getConnection();
        $params = ['oid' => (int) $order->getId(), 'ref' => $order->getOrderReference()];

        // Lock every card this order touched BEFORE reading the ledger, in
        // id order (consistent order = no deadlock between two restores).
        $cardIds = $conn->fetchFirstColumn(
            'SELECT DISTINCT gift_card_id FROM gift_card_transactions WHERE ' . self::ORDER_MATCH
            . ' ORDER BY gift_card_id',
            $params,
        );
        if ($cardIds === []) {
            return '0.00';
        }
        $conn->executeQuery(
            'SELECT id FROM gift_cards WHERE id IN (?) ORDER BY id FOR UPDATE',
            [array_map('intval', $cardIds)],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        $outstanding = $this->outstandingByCard($order);
        $plan        = self::plan($outstanding, $limit);

        $restored = '0.00';
        foreach ($plan as $cardId => $amount) {
            /** @var GiftCard|null $card */
            $card = $this->em->find(GiftCard::class, $cardId);
            if ($card === null) {
                continue;
            }
            // Pick up the committed balance now that we hold the row lock.
            $this->em->refresh($card);
            if ($card->isVoided()) {
                $this->logger->warning('gift_card.order_restore_skipped_voided', [
                    'gift_card_id'    => $cardId,
                    'order_reference' => $order->getOrderReference(),
                    'amount'          => $amount,
                ]);
                continue;
            }
            $card->restoreOrderDebit(
                amount: $amount,
                orderReference: $order->getOrderReference(),
                orderId: $order->getId(),
                reason: $reason,
            );
            $restored = bcadd($restored, $amount, 2);
        }

        return $restored;
    }

    /**
     * Per card: what this order still holds. Newest debit first.
     *
     * @return array<int, string> gift_card_id => amount (> 0 only)
     */
    private function outstandingByCard(Order $order): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT gift_card_id,
                    SUM(CASE WHEN type = 'debit'  THEN amount ELSE 0 END)
                  - SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) AS held,
                    MAX(CASE WHEN type = 'debit' THEN id ELSE 0 END)       AS last_debit
               FROM gift_card_transactions
              WHERE " . self::ORDER_MATCH . "
              GROUP BY gift_card_id
              ORDER BY last_debit DESC",
            ['oid' => (int) $order->getId(), 'ref' => $order->getOrderReference()],
        );

        $out = [];
        foreach ($rows as $row) {
            $held = bcadd((string) $row['held'], '0.00', 2);
            if (bccomp($held, '0.00', 2) > 0) {
                $out[(int) $row['gift_card_id']] = $held;
            }
        }
        return $out;
    }

    /**
     * Split a restore across cards: walk them in the given order, giving
     * each back what it is owed until $limit (null = no limit) runs out.
     *
     * @param array<int, string> $outstanding gift_card_id => amount owed
     * @return array<int, string> gift_card_id => amount to restore (> 0)
     */
    public static function plan(array $outstanding, ?string $limit): array
    {
        $left = $limit;
        $plan = [];
        foreach ($outstanding as $cardId => $owed) {
            if (bccomp($owed, '0.00', 2) <= 0) {
                continue;
            }
            $take = $owed;
            if ($left !== null) {
                if (bccomp($left, '0.00', 2) <= 0) {
                    break;
                }
                if (bccomp($take, $left, 2) > 0) {
                    $take = $left;
                }
                $left = bcsub($left, $take, 2);
            }
            $plan[$cardId] = bcadd($take, '0.00', 2);
        }
        return $plan;
    }
}
