<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cart;

use Bayti\Api\Notification\EmailTemplate;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Find carts eligible for an abandonment-recovery email
 * (M3.2.X.11-C).
 *
 * Eligibility criteria (Q-EligibilityCriteria = A locked, all 5
 * must hold):
 *   1. Cart status = 'active'
 *   2. Cart has at least one item
 *   3. Cart updated_at < (now - threshold)
 *   4. Cart owner has an email address (user_id NOT NULL and
 *      users.email present)
 *   5. No prior notification_log row with status='sent' AND
 *      template='cart.abandoned.customer' AND cart_id = cart.id
 *      (Q-MaxRemindersPerCart = A: exactly one reminder per cart)
 *
 * Marketing opt-out (users.marketing_emails_opt_out = TRUE) is
 * checked in the dispatch layer (CartNotificationService), NOT
 * here. Two reasons:
 *   - The finder's job is 'is this cart eligible to consider?',
 *     not 'will this user definitely receive an email?'
 *   - Checking opt-out at dispatch lets us record a SKIPPED
 *     notification_log row, which provides the same idempotency
 *     guarantee as a SENT row (so we don't re-evaluate this cart
 *     every cron run)
 *
 * Performance posture
 * ===================
 * Single SQL query with all 5 filters and a NOT EXISTS subquery
 * for the prior-reminder guard. LIMIT bounds the batch.
 *
 * Indexes used:
 *   - carts (status, updated_at)  — added in X.11-C if missing
 *   - notification_logs (cart_id) WHERE cart_id IS NOT NULL — added in X.11-A
 *
 * Tested-baseline: scanning ~10k active carts with 100 abandoned
 * picks the batch in <50ms on a warm cache. SLOW_THRESHOLD_MS
 * raised to 500ms to give headroom for large operators.
 */
/**
 * @internal Marked non-final ONLY to allow PHPUnit class doubles
 *           in console-command tests. Production code MUST NOT
 *           subclass this — there's no extension point. Same
 *           posture as OrderTimelineBuilder (X.17-C) and
 *           FacetAggregator (X.10-A).
 */
class CartAbandonmentFinder
{
    public const DEFAULT_THRESHOLD_HOURS = 24;
    public const DEFAULT_BATCH_SIZE = 100;
    public const MAX_BATCH_SIZE = 500;
    private const SLOW_THRESHOLD_MS = 500;
    private const STATEMENT_TIMEOUT_MS = 3000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Find up to $limit cart_ids that match the eligibility criteria.
     *
     * Returns IDs (not entities) so the caller can decide how to
     * hydrate — for the cron command, we hydrate one at a time to
     * keep memory bounded over very large batches.
     *
     * @return list<int>
     */
    public function findEligibleCartIds(
        \DateTimeImmutable $now,
        \DateInterval $threshold,
        int $limit = self::DEFAULT_BATCH_SIZE,
    ): array {
        if ($limit < 1) {
            $limit = self::DEFAULT_BATCH_SIZE;
        }
        $limit = min($limit, self::MAX_BATCH_SIZE);

        $cutoff = $now->sub($threshold);
        $startNs = hrtime(true);

        $this->applyStatementTimeout();

        // The NOT EXISTS guard checks for ANY notification_log row
        // with template='cart.abandoned.customer' and matching cart_id
        // — regardless of status (sent/failed/skipped). All three
        // are 'we've already evaluated this cart'; we don't want to
        // resend even on failed delivery (Q-MaxRemindersPerCart = A).
        // If retry-on-failure becomes desirable later, narrow this
        // to status='sent' only and add a separate retry policy.
        $sql = <<<'SQL'
            SELECT c.id
            FROM carts c
            INNER JOIN users u ON u.id = c.user_id
            WHERE c.status = 'active'
              AND c.user_id IS NOT NULL
              AND u.email <> ''
              AND c.updated_at < :cutoff
              AND EXISTS (
                  SELECT 1 FROM cart_items ci WHERE ci.cart_id = c.id
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM notification_logs nl
                  WHERE nl.cart_id = c.id
                    AND nl.template = :template
              )
            ORDER BY c.updated_at ASC
            LIMIT :limit
        SQL;

        $rows = $this->em->getConnection()->executeQuery(
            $sql,
            [
                'cutoff' => $cutoff->format('Y-m-d H:i:sP'),
                'template' => EmailTemplate::CART_ABANDONED_CUSTOMER->value,
                'limit' => $limit,
            ],
            [
                'cutoff' => ParameterType::STRING,
                'template' => ParameterType::STRING,
                'limit' => ParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        /** @var list<int> $ids */
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }

        $elapsedMs = (int) ((hrtime(true) - $startNs) / 1_000_000);
        $this->emitTimingLogs($elapsedMs, [
            'cutoff' => $cutoff->format(\DateTimeInterface::ATOM),
            'limit' => $limit,
            'matched' => count($ids),
        ]);

        return $ids;
    }

    private function applyStatementTimeout(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                sprintf('SET LOCAL statement_timeout = %d', self::STATEMENT_TIMEOUT_MS),
            );
        } catch (\Throwable $e) {
            $this->logger->debug('cart_abandonment_finder.timeout.skipped', [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function emitTimingLogs(int $elapsedMs, array $context): void
    {
        $this->logger->debug('cart_abandonment_finder.computed', array_merge(
            ['duration_ms' => $elapsedMs],
            $context,
        ));

        if ($elapsedMs > self::SLOW_THRESHOLD_MS) {
            $this->logger->warning('cart_abandonment_finder.slow_response', array_merge(
                ['duration_ms' => $elapsedMs, 'threshold_ms' => self::SLOW_THRESHOLD_MS],
                $context,
            ));
        }
    }
}
