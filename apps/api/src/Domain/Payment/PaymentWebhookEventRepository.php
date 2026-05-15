<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Payment;

use Doctrine\ORM\EntityRepository;

/**
 * Repository for PaymentWebhookEvent.
 *
 * @extends EntityRepository<PaymentWebhookEvent>
 */
class PaymentWebhookEventRepository extends EntityRepository
{
    /**
     * Find an event by its idempotency key. Used by the webhook
     * handler to short-circuit re-deliveries from Noon.
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?PaymentWebhookEvent
    {
        $result = $this->findOneBy(['idempotencyKey' => $idempotencyKey]);
        /** @var PaymentWebhookEvent|null $result */
        return $result;
    }

    /**
     * List all webhook events for a given provider_order_ref. Used
     * for reconciliation: "show me everything Noon told us about
     * this order".
     *
     * @return list<PaymentWebhookEvent>
     */
    public function listForProviderOrderRef(string $providerOrderRef): array
    {
        $result = $this->createQueryBuilder('e')
            ->where('e.providerOrderRef = :ref')
            ->setParameter('ref', $providerOrderRef)
            ->orderBy('e.receivedAt', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var list<PaymentWebhookEvent> $result */
        return $result;
    }

    /**
     * Page of unprocessed events older than $olderThan. Used by the
     * dead-letter retry cron in M3.1.7 to retry processing of
     * webhooks that failed mid-handler.
     *
     * The partial index idx_payment_webhook_unprocessed makes this
     * fast even on large event tables.
     *
     * @return list<PaymentWebhookEvent>
     */
    public function listUnprocessedOlderThan(\DateTimeImmutable $olderThan, int $limit = 100): array
    {
        $result = $this->createQueryBuilder('e')
            ->where('e.processedAt IS NULL')
            ->andWhere('e.receivedAt < :threshold')
            ->setParameter('threshold', $olderThan)
            ->orderBy('e.receivedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        /** @var list<PaymentWebhookEvent> $result */
        return $result;
    }

    public function save(PaymentWebhookEvent $event): void
    {
        $em = $this->getEntityManager();
        $em->persist($event);
        $em->flush();
    }
}
