<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Notification\EmailTemplate;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Find orders eligible for an automated "complete your payment" reminder.
 *
 * Targets orders stuck in `pending_payment` (customer reached the gateway
 * but never finished) or `failed` (the charge attempt failed and is
 * retryable). Both are recoverable revenue, a well-timed nudge brings a
 * share of them back to a completed sale, mirroring the abandoned-cart
 * recovery flow (CartAbandonmentFinder).
 *
 * Eligibility (both channels)
 * ===========================
 *   1. status IN ('pending_payment', 'failed')
 *   2. created_at <= now - minAge   (old enough that the customer has
 *      genuinely dropped off, not still mid-checkout, and past the
 *      reconcile cron's window)
 *   3. created_at >= now - maxAge   (not so old the nudge is pointless)
 *   4. No prior reminder for this order+channel (NOT EXISTS guard), so a
 *      re-run never double-nudges the same order.
 *
 * Two independent finders, one per channel, exactly like the cart finder:
 * the EMAIL guard keys on template 'order.payment_reminder.customer'; the
 * PUSH guard keys on the push data.type 'order.payment_reminder' AND
 * channel='push'. An email reminder therefore never suppresses a push and
 * vice-versa, an order can receive both, each idempotent on its own
 * channel. Additionally, the email finder requires the customer to have an
 * email; the push finder requires at least one active device token.
 *
 * IDs (not entities) are returned so the caller can hydrate one at a time
 * and keep memory bounded over large batches.
 *
 * Indexes relied upon
 * ===================
 *   - orders partial index on (created_at) WHERE status IN (...), added in
 *     the accompanying migration; keeps the range scan cheap as the table
 *     grows.
 *   - notification_logs (order_id), for the NOT EXISTS subquery.
 */
final class PendingOrderReminderFinder
{
    /** Push data.type used as the per-order push idempotency key (stage 1). */
    public const PUSH_TEMPLATE = 'order.payment_reminder';
    /** Push data.type for the follow-up nudge (stage 2). */
    public const PUSH_TEMPLATE_FOLLOWUP = 'order.payment_reminder2';

    public const DEFAULT_MIN_AGE_HOURS = 1;
    public const DEFAULT_MAX_AGE_HOURS = 168; // 7 days
    public const DEFAULT_FOLLOWUP_AFTER_HOURS = 24;
    public const DEFAULT_BATCH_SIZE = 200;
    public const MAX_BATCH_SIZE = 1000;

    private const SLOW_THRESHOLD_MS = 500;
    private const STATEMENT_TIMEOUT_MS = 3000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Order ids eligible for an EMAIL payment reminder.
     *
     * @return list<int>
     */
    public function findEmailEligibleOrderIds(
        \DateTimeImmutable $now,
        int $minAgeHours = self::DEFAULT_MIN_AGE_HOURS,
        int $maxAgeHours = self::DEFAULT_MAX_AGE_HOURS,
        int $limit = self::DEFAULT_BATCH_SIZE,
    ): array {
        [$olderThan, $newerThan, $limit] = $this->window($now, $minAgeHours, $maxAgeHours, $limit);
        $startNs = hrtime(true);
        $this->applyStatementTimeout();

        $sql = <<<'SQL'
            SELECT o.id
            FROM orders o
            INNER JOIN users u ON u.id = o.user_id
            WHERE o.status IN ('pending_payment', 'failed')
              AND u.email <> ''
              AND o.created_at <= :older_than
              AND o.created_at >= :newer_than
              AND NOT EXISTS (
                  SELECT 1
                  FROM notification_logs nl
                  WHERE nl.order_id = o.id
                    AND nl.template = :template
              )
            ORDER BY o.created_at ASC
            LIMIT :limit
        SQL;

        $ids = $this->runQuery($sql, [
            'older_than' => $olderThan->format('Y-m-d H:i:sP'),
            'newer_than' => $newerThan->format('Y-m-d H:i:sP'),
            'template' => EmailTemplate::ORDER_PAYMENT_REMINDER_CUSTOMER->value,
            'limit' => $limit,
        ], [
            'older_than' => ParameterType::STRING,
            'newer_than' => ParameterType::STRING,
            'template' => ParameterType::STRING,
            'limit' => ParameterType::INTEGER,
        ]);

        $this->emitTimingLogs((int) ((hrtime(true) - $startNs) / 1_000_000), [
            'channel' => 'email',
            'older_than' => $olderThan->format(\DateTimeInterface::ATOM),
            'newer_than' => $newerThan->format(\DateTimeInterface::ATOM),
            'limit' => $limit,
            'matched' => count($ids),
        ]);

        return $ids;
    }

    /**
     * Order ids eligible for a PUSH payment reminder. Independent of the
     * email finder (channel-scoped NOT EXISTS); requires an active device
     * token rather than an email.
     *
     * @return list<int>
     */
    public function findPushEligibleOrderIds(
        \DateTimeImmutable $now,
        int $minAgeHours = self::DEFAULT_MIN_AGE_HOURS,
        int $maxAgeHours = self::DEFAULT_MAX_AGE_HOURS,
        int $limit = self::DEFAULT_BATCH_SIZE,
    ): array {
        [$olderThan, $newerThan, $limit] = $this->window($now, $minAgeHours, $maxAgeHours, $limit);
        $startNs = hrtime(true);
        $this->applyStatementTimeout();

        $sql = <<<'SQL'
            SELECT o.id
            FROM orders o
            WHERE o.status IN ('pending_payment', 'failed')
              AND o.user_id IS NOT NULL
              AND o.created_at <= :older_than
              AND o.created_at >= :newer_than
              AND EXISTS (
                  SELECT 1 FROM device_tokens dt
                  WHERE dt.user_id = o.user_id AND dt.is_active = true
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM notification_logs nl
                  WHERE nl.order_id = o.id
                    AND nl.template = :template
                    AND nl.channel = :channel
              )
            ORDER BY o.created_at ASC
            LIMIT :limit
        SQL;

        $ids = $this->runQuery($sql, [
            'older_than' => $olderThan->format('Y-m-d H:i:sP'),
            'newer_than' => $newerThan->format('Y-m-d H:i:sP'),
            'template' => self::PUSH_TEMPLATE,
            'channel' => 'push',
            'limit' => $limit,
        ], [
            'older_than' => ParameterType::STRING,
            'newer_than' => ParameterType::STRING,
            'template' => ParameterType::STRING,
            'channel' => ParameterType::STRING,
            'limit' => ParameterType::INTEGER,
        ]);

        $this->emitTimingLogs((int) ((hrtime(true) - $startNs) / 1_000_000), [
            'channel' => 'push',
            'older_than' => $olderThan->format(\DateTimeInterface::ATOM),
            'newer_than' => $newerThan->format(\DateTimeInterface::ATOM),
            'limit' => $limit,
            'matched' => count($ids),
        ]);

        return $ids;
    }

    /**
     * Order ids eligible for the EMAIL FOLLOW-UP (stage 2). Eligible when the
     * stage-1 email was sent at least $followupAfterHours ago and no stage-2
     * email exists yet, so there is always a real gap since the first
     * reminder, regardless of when it actually fired. Bounded by a created_at
     * floor so ancient orders aren't nudged.
     *
     * @return list<int>
     */
    public function findEmailFollowupEligibleOrderIds(
        \DateTimeImmutable $now,
        int $followupAfterHours = self::DEFAULT_FOLLOWUP_AFTER_HOURS,
        int $maxAgeHours = self::DEFAULT_MAX_AGE_HOURS,
        int $limit = self::DEFAULT_BATCH_SIZE,
    ): array {
        [$followupCutoff, $createdFloor, $limit] = $this->followupWindow($now, $followupAfterHours, $maxAgeHours, $limit);
        $startNs = hrtime(true);
        $this->applyStatementTimeout();

        $sql = <<<'SQL'
            SELECT o.id
            FROM orders o
            INNER JOIN users u ON u.id = o.user_id
            WHERE o.status IN ('pending_payment', 'failed')
              AND u.email <> ''
              AND o.created_at >= :created_floor
              AND EXISTS (
                  SELECT 1
                  FROM notification_logs nl1
                  WHERE nl1.order_id = o.id
                    AND nl1.template = :stage1_template
                    AND nl1.sent_at <= :followup_cutoff
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM notification_logs nl2
                  WHERE nl2.order_id = o.id
                    AND nl2.template = :stage2_template
              )
            ORDER BY o.created_at ASC
            LIMIT :limit
        SQL;

        $ids = $this->runQuery($sql, [
            'created_floor' => $createdFloor->format('Y-m-d H:i:sP'),
            'stage1_template' => EmailTemplate::ORDER_PAYMENT_REMINDER_CUSTOMER->value,
            'followup_cutoff' => $followupCutoff->format('Y-m-d H:i:sP'),
            'stage2_template' => EmailTemplate::ORDER_PAYMENT_REMINDER_2_CUSTOMER->value,
            'limit' => $limit,
        ], [
            'created_floor' => ParameterType::STRING,
            'stage1_template' => ParameterType::STRING,
            'followup_cutoff' => ParameterType::STRING,
            'stage2_template' => ParameterType::STRING,
            'limit' => ParameterType::INTEGER,
        ]);

        $this->emitTimingLogs((int) ((hrtime(true) - $startNs) / 1_000_000), [
            'channel' => 'email',
            'stage' => 'followup',
            'followup_cutoff' => $followupCutoff->format(\DateTimeInterface::ATOM),
            'limit' => $limit,
            'matched' => count($ids),
        ]);

        return $ids;
    }

    /**
     * Order ids eligible for the PUSH FOLLOW-UP (stage 2). Same sent_at-anchored
     * rule as the email follow-up, channel-scoped and requiring an active token.
     *
     * @return list<int>
     */
    public function findPushFollowupEligibleOrderIds(
        \DateTimeImmutable $now,
        int $followupAfterHours = self::DEFAULT_FOLLOWUP_AFTER_HOURS,
        int $maxAgeHours = self::DEFAULT_MAX_AGE_HOURS,
        int $limit = self::DEFAULT_BATCH_SIZE,
    ): array {
        [$followupCutoff, $createdFloor, $limit] = $this->followupWindow($now, $followupAfterHours, $maxAgeHours, $limit);
        $startNs = hrtime(true);
        $this->applyStatementTimeout();

        $sql = <<<'SQL'
            SELECT o.id
            FROM orders o
            WHERE o.status IN ('pending_payment', 'failed')
              AND o.user_id IS NOT NULL
              AND o.created_at >= :created_floor
              AND EXISTS (
                  SELECT 1 FROM device_tokens dt
                  WHERE dt.user_id = o.user_id AND dt.is_active = true
              )
              AND EXISTS (
                  SELECT 1
                  FROM notification_logs nl1
                  WHERE nl1.order_id = o.id
                    AND nl1.template = :stage1_template
                    AND nl1.channel = :channel
                    AND nl1.sent_at <= :followup_cutoff
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM notification_logs nl2
                  WHERE nl2.order_id = o.id
                    AND nl2.template = :stage2_template
                    AND nl2.channel = :channel
              )
            ORDER BY o.created_at ASC
            LIMIT :limit
        SQL;

        $ids = $this->runQuery($sql, [
            'created_floor' => $createdFloor->format('Y-m-d H:i:sP'),
            'stage1_template' => self::PUSH_TEMPLATE,
            'channel' => 'push',
            'followup_cutoff' => $followupCutoff->format('Y-m-d H:i:sP'),
            'stage2_template' => self::PUSH_TEMPLATE_FOLLOWUP,
            'limit' => $limit,
        ], [
            'created_floor' => ParameterType::STRING,
            'stage1_template' => ParameterType::STRING,
            'channel' => ParameterType::STRING,
            'followup_cutoff' => ParameterType::STRING,
            'stage2_template' => ParameterType::STRING,
            'limit' => ParameterType::INTEGER,
        ]);

        $this->emitTimingLogs((int) ((hrtime(true) - $startNs) / 1_000_000), [
            'channel' => 'push',
            'stage' => 'followup',
            'followup_cutoff' => $followupCutoff->format(\DateTimeInterface::ATOM),
            'limit' => $limit,
            'matched' => count($ids),
        ]);

        return $ids;
    }

    /**
     * Normalise the age window + batch size. Returns [olderThan, newerThan,
     * limit]: an order is eligible when newerThan <= created_at <= olderThan.
     * A minAge >= maxAge is coerced to a sane window rather than erroring.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int}
     */
    private function window(\DateTimeImmutable $now, int $minAgeHours, int $maxAgeHours, int $limit): array
    {
        $minAgeHours = max(0, $minAgeHours);
        $maxAgeHours = max($minAgeHours + 1, $maxAgeHours);
        if ($limit < 1) {
            $limit = self::DEFAULT_BATCH_SIZE;
        }
        $limit = min($limit, self::MAX_BATCH_SIZE);

        $olderThan = $now->sub(new \DateInterval('PT' . $minAgeHours . 'H'));
        $newerThan = $now->sub(new \DateInterval('PT' . $maxAgeHours . 'H'));

        return [$olderThan, $newerThan, $limit];
    }

    /**
     * Follow-up (stage 2) window. Returns [followupCutoff, createdFloor,
     * limit]: stage-1 must have been sent at or before followupCutoff
     * (>= followupAfterHours ago), and the order created at or after
     * createdFloor. The floor is maxAge + followupAfter so every stage-1
     * recipient (which required created_at within maxAge) can still receive
     * the follow-up followupAfter hours later.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int}
     */
    private function followupWindow(\DateTimeImmutable $now, int $followupAfterHours, int $maxAgeHours, int $limit): array
    {
        $followupAfterHours = max(1, $followupAfterHours);
        $maxAgeHours = max(1, $maxAgeHours);
        if ($limit < 1) {
            $limit = self::DEFAULT_BATCH_SIZE;
        }
        $limit = min($limit, self::MAX_BATCH_SIZE);

        $followupCutoff = $now->sub(new \DateInterval('PT' . $followupAfterHours . 'H'));
        $createdFloor = $now->sub(new \DateInterval('PT' . ($maxAgeHours + $followupAfterHours) . 'H'));

        return [$followupCutoff, $createdFloor, $limit];
    }

    /**
     * @param array<string, scalar> $params
     * @param array<string, ParameterType|int> $types
     * @return list<int>
     */
    private function runQuery(string $sql, array $params, array $types): array
    {
        $rows = $this->em->getConnection()
            ->executeQuery($sql, $params, $types)
            ->fetchAllAssociative();

        /** @var list<int> $ids */
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    private function applyStatementTimeout(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                sprintf('SET LOCAL statement_timeout = %d', self::STATEMENT_TIMEOUT_MS),
            );
        } catch (\Throwable $e) {
            $this->logger->debug('pending_order_reminder_finder.timeout.skipped', [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function emitTimingLogs(int $elapsedMs, array $context): void
    {
        $this->logger->debug('pending_order_reminder_finder.computed', array_merge(
            ['duration_ms' => $elapsedMs],
            $context,
        ));

        if ($elapsedMs > self::SLOW_THRESHOLD_MS) {
            $this->logger->warning('pending_order_reminder_finder.slow_response', array_merge(
                ['duration_ms' => $elapsedMs, 'threshold_ms' => self::SLOW_THRESHOLD_MS],
                $context,
            ));
        }
    }
}
