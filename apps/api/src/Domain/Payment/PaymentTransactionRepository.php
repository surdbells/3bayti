<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Payment;

use Bayti\Api\Domain\Order\Order;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for PaymentTransaction.
 *
 * @extends EntityRepository<PaymentTransaction>
 */
class PaymentTransactionRepository extends EntityRepository
{
    /**
     * Find a transaction by its idempotency key. Used by the
     * Noon adapter to short-circuit re-submitted operations
     * (e.g. user double-tapping "Pay now").
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?PaymentTransaction
    {
        $result = $this->findOneBy(['idempotencyKey' => $idempotencyKey]);
        /** @var PaymentTransaction|null $result */
        return $result;
    }

    /**
     * Find a transaction by the gateway's order ref (Noon's orderId).
     * Used during webhook processing to correlate the incoming event
     * back to a known transaction.
     */
    public function findByProviderOrderRef(string $providerOrderRef): ?PaymentTransaction
    {
        $result = $this->findOneBy(['providerOrderRef' => $providerOrderRef]);
        /** @var PaymentTransaction|null $result */
        return $result;
    }

    /**
     * List all transactions for an order ordered by creation time.
     * Used for the order detail page (admin) and audit queries.
     *
     * @return list<PaymentTransaction>
     */
    public function listForOrder(Order $order): array
    {
        $result = $this->createQueryBuilder('t')
            ->where('t.order = :order')
            ->setParameter('order', $order)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var list<PaymentTransaction> $result */
        return $result;
    }

    /**
     * Latest INITIATE transaction for an order. Used by the
     * GetCheckoutStatusController when deciding whether a polled
     * GET_ORDER call is appropriate (no INITIATE → no Noon order
     * exists yet → no point polling).
     */
    public function findLatestInitiateForOrder(Order $order): ?PaymentTransaction
    {
        $result = $this->createQueryBuilder('t')
            ->where('t.order = :order')
            ->andWhere('t.operation = :op')
            ->setParameter('order', $order)
            ->setParameter('op', PaymentTransaction::OPERATION_INITIATE)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        /** @var PaymentTransaction|null $result */
        return $result;
    }

    public function save(PaymentTransaction $transaction): void
    {
        $em = $this->getEntityManager();
        $em->persist($transaction);
        $em->flush();
    }

    /**
     * Sum the amount of all successful REFUND transactions for an
     * order. Used by M3.1.7-E to validate that a new refund request
     * + existing refunds <= original paid amount.
     *
     * Returns a decimal string ('0.00' if no refunds yet). Uses
     * string arithmetic (bcadd) to avoid float drift on decimal
     * amounts.
     *
     * A 'successful' refund is one whose status indicates the gateway
     * accepted it. Noon returns 'Refunded' or 'PartiallyRefunded';
     * we accept anything except 'Failed' / 'Rejected' (gateway-
     * specific) so we don't accidentally double-refund when a
     * pending row exists.
     */
    public function sumRefundsForOrder(Order $order): string
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.amount, t.status')
            ->where('t.order = :order')
            ->andWhere('t.operation = :op')
            ->setParameter('order', $order)
            ->setParameter('op', PaymentTransaction::OPERATION_REFUND)
            ->getQuery()
            ->getScalarResult();

        $sum = '0.00';
        foreach ($rows as $row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            // Skip explicit failures; everything else (including
            // pending Refund-Requested rows) counts to prevent
            // double-issuing in race conditions.
            if (str_contains($status, 'fail') || str_contains($status, 'reject')) {
                continue;
            }
            $sum = bcadd($sum, (string) $row['amount'], 2);
        }
        return $sum;
    }
}
