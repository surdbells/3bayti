<?php

declare(strict_types=1);

namespace Bayti\Api\Notification\Push;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * Idempotency + audit ledger for the marketing-PUSH scheduled jobs
 * (abandoned-cart push, post-delivery review prompt, re-engagement
 * nudge).
 *
 * Why raw DBAL rather than the NotificationLog entity
 * ===================================================
 * The foundation generalized the notification_logs TABLE (added
 * user_id, channel, made recipient nullable) but DELIBERATELY did
 * NOT remap the NotificationLog *entity* for those columns. So the
 * entity's static factories can't populate channel='push' or
 * user_id. We therefore write/read the push ledger with narrow raw
 * SQL here, leaving the entity (and its email-side invariants)
 * untouched. This is also the safest posture for the PHP-DI
 * compiled container, no object/enum defaults, no closures in DI.
 *
 * The `channel` column is the linchpin of independent idempotency:
 * an email log row (channel='email') never suppresses a push, and a
 * push log row (channel='push') never suppresses an email. Every
 * guard below pins channel='push' so the two streams stay separate.
 *
 * Template values used here are the PUSH data.type strings from the
 * shared mobile contract (cart.abandoned, order.review_prompt,
 * re_engagement.nudge), distinct from the email template values
 * (e.g. cart.abandoned.customer) so the two never collide.
 *
 * @internal Marked non-final ONLY so PHPUnit can build class doubles in
 *           the console-command tests. There is no extension point;
 *           production code must not subclass it. Same posture as
 *           CartAbandonmentFinder / OrderTimelineBuilder.
 */
class PushNotificationLogger
{
    public const CHANNEL = 'push';

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Has a PUSH log row already been written for this cart + template?
     * Used as the abandoned-cart push idempotency guard (channel-scoped
     * so an email reminder log never suppresses the push).
     */
    public function pushSentForCart(int $cartId, string $template): bool
    {
        return $this->exists(
            'SELECT 1 FROM notification_logs '
            . "WHERE cart_id = :cart AND template = :tpl AND channel = :ch LIMIT 1",
            ['cart' => $cartId, 'tpl' => $template, 'ch' => self::CHANNEL],
            ['cart' => ParameterType::INTEGER, 'tpl' => ParameterType::STRING, 'ch' => ParameterType::STRING],
        );
    }

    /**
     * Has a PUSH log row already been written for this order + template?
     * Used as the post-delivery review-prompt idempotency guard.
     */
    public function pushSentForOrder(int $orderId, string $template): bool
    {
        return $this->exists(
            'SELECT 1 FROM notification_logs '
            . "WHERE order_id = :oid AND template = :tpl AND channel = :ch LIMIT 1",
            ['oid' => $orderId, 'tpl' => $template, 'ch' => self::CHANNEL],
            ['oid' => ParameterType::INTEGER, 'tpl' => ParameterType::STRING, 'ch' => ParameterType::STRING],
        );
    }

    /**
     * Has a PUSH log row for this user + template been written since
     * $since? Used by the re-engagement nudge to enforce the 14-day
     * cadence (don't nudge the same user more than once per window).
     */
    public function pushSentForUserSince(int $userId, string $template, DateTimeImmutable $since): bool
    {
        return $this->exists(
            'SELECT 1 FROM notification_logs '
            . 'WHERE user_id = :uid AND template = :tpl AND channel = :ch '
            . 'AND sent_at > :since LIMIT 1',
            [
                'uid' => $userId,
                'tpl' => $template,
                'ch' => self::CHANNEL,
                'since' => $since->format('Y-m-d H:i:sP'),
            ],
            [
                'uid' => ParameterType::INTEGER,
                'tpl' => ParameterType::STRING,
                'ch' => ParameterType::STRING,
                'since' => ParameterType::STRING,
            ],
        );
    }

    /**
     * Write a push notification_log row. Fire-and-forget: persistence
     * failures are logged and swallowed so the cron batch never aborts
     * on a ledger hiccup. The row makes the cart/order/user ineligible
     * on subsequent runs (channel-scoped idempotency).
     *
     * Exactly one of $cartId / $orderId is typically set; $userId is
     * populated for user-scoped nudges. recipient is nullable in the
     * generalized schema, so push rows carry NULL there.
     */
    public function log(
        string $template,
        string $status,
        ?int $cartId = null,
        ?int $orderId = null,
        ?int $userId = null,
        ?string $errorKind = null,
        ?string $errorMessage = null,
    ): void {
        try {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
            $this->connection->insert('notification_logs', [
                'order_id' => $orderId,
                'cart_id' => $cartId,
                'user_id' => $userId,
                'template' => $template,
                'channel' => self::CHANNEL,
                'recipient' => null,
                'status' => $status,
                'sent_at' => $now,
                'error_kind' => $errorKind,
                'error_message' => $errorMessage,
                'created_at' => $now,
                'updated_at' => $now,
                'is_read' => false,
            ], [
                'order_id' => ParameterType::INTEGER,
                'cart_id' => ParameterType::INTEGER,
                'user_id' => ParameterType::INTEGER,
                'template' => ParameterType::STRING,
                'channel' => ParameterType::STRING,
                'recipient' => ParameterType::NULL,
                'status' => ParameterType::STRING,
                'sent_at' => ParameterType::STRING,
                'error_kind' => ParameterType::STRING,
                'error_message' => ParameterType::STRING,
                'created_at' => ParameterType::STRING,
                'updated_at' => ParameterType::STRING,
                'is_read' => ParameterType::BOOLEAN,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('push_notification.log_persist_failed', [
                'template' => $template,
                'channel' => self::CHANNEL,
                'cart_id' => $cartId,
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => $status,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, ParameterType|int> $types
     */
    private function exists(string $sql, array $params, array $types): bool
    {
        try {
            $row = $this->connection->fetchOne($sql, $params, $types);
            return $row !== false;
        } catch (\Throwable $e) {
            // On a read failure, fall back to "already sent" = false so
            // the caller proceeds. The write side is itself idempotent
            // enough for a cron (worst case: a duplicate push on a
            // transient DB error, never a missed-forever send).
            $this->logger->error('push_notification.idempotency_check_failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            return false;
        }
    }
}
