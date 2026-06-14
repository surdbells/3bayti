<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * An append-only record of a notification send attempt.
 *
 * Every call to OrderNotificationService::safeSend() produces exactly
 * one row here, regardless of outcome (sent / failed / skipped). The
 * row is written at the same logical moment as the mailer call (after
 * success, in the catch block on failure, or when a guard short-
 * circuits before sending).
 *
 * Closes the M3.1.7-H observability gap: emails went out but nothing
 * was queryable. Now ops can answer "did customer X get email Y?"
 * via the admin endpoint, instead of grepping PSR-3 logs.
 *
 * Immutability
 * ------------
 * Once created, a notification_log row is never modified by the
 * application (M3.2.X.4 scope). The `raw_event` column is pre-
 * allocated for future ZeptoMail webhook handlers that may UPDATE
 * existing rows with bounce/complaint details, but no code in
 * M3.2.X.4 mutates the row after persist.
 *
 * No FK on order_id with CASCADE
 * -------------------------------
 * order_id is FK to orders(id) ON DELETE SET NULL. The notification
 * log is an audit trail — if an order is hard-deleted (rare; almost
 * always soft-delete via status), we keep the notification history
 * with order_id = null rather than losing the audit trail. Same
 * rationale as AuditLog::$userId / $subjectId (which use NO FK at
 * all because audit subjects outlive deletion). Here we keep the FK
 * because notification_logs are scoped to orders specifically and
 * the SET NULL gives us the same outlive-deletion property.
 *
 * Status taxonomy (Q-Status = A locked in M3.2.X.4 plan)
 * -------------------------------------------------------
 * - STATUS_SENT:    mailer->send() returned without exception
 * - STATUS_FAILED:  caught MailerException or generic \Throwable
 * - STATUS_SKIPPED: guard short-circuited before sending (no
 *                   recipient email, contact_email_unset, etc.)
 *
 * Skipped rows are valuable observability — they tell ops "this
 * customer has no email, so no notifications were attempted" rather
 * than silently dropping the signal.
 */
#[ORM\Entity(repositoryClass: NotificationLogRepository::class)]
#[ORM\Table(name: 'notification_logs')]
#[ORM\Index(columns: ['order_id'], name: 'idx_notification_logs_order_id')]
#[ORM\Index(columns: ['template'], name: 'idx_notification_logs_template')]
#[ORM\Index(columns: ['status'], name: 'idx_notification_logs_status')]
#[ORM\Index(columns: ['sent_at'], name: 'idx_notification_logs_sent_at')]
#[ORM\Index(columns: ['recipient'], name: 'idx_notification_logs_recipient')]
#[ORM\Index(columns: ['status', 'sent_at'], name: 'idx_notification_logs_status_sent_at')]
class NotificationLog
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const ALL_STATUSES = [
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    /**
     * The order this notification relates to. Nullable to preserve
     * the audit trail even if the underlying order is hard-deleted
     * (FK ON DELETE SET NULL). M3.2.X.4 always populates this; future
     * non-order notifications could be added by leaving it null.
     */
    #[ORM\Column(name: 'order_id', type: 'integer', nullable: true)]
    private ?int $orderId;

    /**
     * The cart this notification relates to (M3.2.X.11). Sibling to
     * order_id — for cart-scoped notifications (e.g. abandoned cart
     * reminders) cart_id is populated and order_id is null. For
     * order-scoped notifications it's the inverse. Both could
     * theoretically be set for a future "you abandoned this cart,
     * here's a discount for your next order" flow; v1 keeps them
     * mutually exclusive.
     *
     * FK ON DELETE SET NULL preserves audit trail if the cart is
     * hard-deleted. Same posture as order_id.
     */
    #[ORM\Column(name: 'cart_id', type: 'integer', nullable: true)]
    private ?int $cartId = null;

    /**
     * EmailTemplate enum's string value (e.g. 'order.placed.customer').
     * Stored as varchar rather than enum column so adding a template
     * case in EmailTemplate.php doesn't require a schema migration.
     */
    #[ORM\Column(name: 'template', type: 'string', length: 64)]
    private string $template;

    /**
     * Recipient email address as it was sent to (or attempted).
     * Captured at attempt time; preserved even if the user's email
     * later changes — this is the audit record of what we tried, not
     * a live link to the current user state.
     */
    #[ORM\Column(name: 'recipient', type: 'string', length: 255)]
    private string $recipient;

    /** One of STATUS_* constants. */
    #[ORM\Column(name: 'status', type: 'string', length: 16)]
    private string $status;

    /**
     * Timestamp of the attempt. Set on row creation; immutable.
     * NOT the moment the email landed in the recipient's inbox —
     * ZeptoMail webhooks would provide that signal but M3.2.X.4
     * doesn't wire webhooks (the raw_event column is pre-allocated
     * for that future work).
     */
    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable')]
    private DateTimeImmutable $sentAt;

    /**
     * For STATUS_FAILED rows: the MailerException kind (network,
     * transport, rate_limit, auth, unknown) or the class name of a
     * generic \Throwable.
     * Null for STATUS_SENT and STATUS_SKIPPED rows.
     */
    #[ORM\Column(name: 'error_kind', type: 'string', length: 64, nullable: true)]
    private ?string $errorKind = null;

    /**
     * For STATUS_FAILED rows: the exception message for debugging.
     * For STATUS_SKIPPED rows: the reason short-code ('no_email',
     * 'contact_email_unset', etc.).
     * Null for STATUS_SENT rows.
     */
    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    /**
     * Reserved for future ZeptoMail webhook payloads (bounce,
     * complaint, delivered). Null in M3.2.X.4; column pre-allocated
     * so future webhook handler can UPDATE without schema migration.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'raw_event', type: 'json', nullable: true)]
    private ?array $rawEvent = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * Feed read-state — separate from the audit fact. Lets the
     * sent-notification log double as the vendor's in-app feed.
     */
    #[ORM\Column(name: 'is_read', type: 'boolean', options: ['default' => false])]
    private bool $isRead = false;

    #[ORM\Column(name: 'read_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $readAt = null;

    /**
     * Construct a log row with the minimum required fields. Use the
     * static factories below for the three status variants to ensure
     * the right error fields are populated for each.
     */
    private function __construct(
        ?int $orderId,
        string $template,
        string $recipient,
        string $status,
        ?int $cartId = null,
    ) {
        if (!in_array($status, self::ALL_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "Unknown notification status: $status. "
                . 'Must be one of: ' . implode(', ', self::ALL_STATUSES),
            );
        }
        $this->orderId = $orderId;
        $this->cartId = $cartId;
        $this->template = $template;
        $this->recipient = $recipient;
        $this->status = $status;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->sentAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Factory for the happy path: mailer->send() returned without
     * exception.
     */
    public static function sent(
        ?int $orderId,
        string $template,
        string $recipient,
        ?int $cartId = null,
    ): self {
        return new self($orderId, $template, $recipient, self::STATUS_SENT, $cartId);
    }

    /**
     * Factory for the failure path: MailerException or generic
     * \Throwable was caught.
     *
     * @param string $errorKind MailerException::KIND_* constant or
     *               class name of the throwable
     */
    public static function failed(
        ?int $orderId,
        string $template,
        string $recipient,
        string $errorKind,
        string $errorMessage,
        ?int $cartId = null,
    ): self {
        $log = new self($orderId, $template, $recipient, self::STATUS_FAILED, $cartId);
        $log->errorKind = $errorKind;
        $log->errorMessage = $errorMessage;
        return $log;
    }

    /**
     * Factory for the skipped path: guard short-circuited before
     * sending (no email, contact_email_unset, no admin recipients
     * configured, etc.).
     *
     * @param string $reason Short reason code (e.g. 'no_email',
     *               'contact_email_unset', 'no_admin_recipients')
     */
    public static function skipped(
        ?int $orderId,
        string $template,
        string $recipient,
        string $reason,
        ?int $cartId = null,
    ): self {
        $log = new self($orderId, $template, $recipient, self::STATUS_SKIPPED, $cartId);
        // For skipped rows, we don't have an error_kind in the
        // MailerException sense — populate error_message with the
        // reason short-code for triage. Leaving error_kind NULL
        // distinguishes 'skipped (reason in error_message)' from
        // 'failed (error_kind + error_message)'.
        $log->errorMessage = $reason;
        return $log;
    }

    // -----------------------------------------------------------------
    // Accessors — read-only by design (immutability)
    // -----------------------------------------------------------------

    public function getId(): ?int { return $this->id; }
    public function getOrderId(): ?int { return $this->orderId; }
    public function getCartId(): ?int { return $this->cartId; }
    public function getTemplate(): string { return $this->template; }
    public function getRecipient(): string { return $this->recipient; }
    public function getStatus(): string { return $this->status; }
    public function getSentAt(): DateTimeImmutable { return $this->sentAt; }    public function getErrorKind(): ?string { return $this->errorKind; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }

    /**
     * @return array<string, mixed>|null
     */
    public function getRawEvent(): ?array { return $this->rawEvent; }

    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }

    public function isRead(): bool { return $this->isRead; }
    public function getReadAt(): ?DateTimeImmutable { return $this->readAt; }

    /** Mark this notification read (idempotent). */
    public function markRead(): void
    {
        if ($this->isRead) {
            return;
        }
        $this->isRead = true;
        $this->readAt = new DateTimeImmutable();
        $this->updatedAt = $this->readAt;
    }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    /**
     * Update the rawEvent payload from a webhook. The only mutator
     * on this entity; reserved for the future webhook handler that
     * decorates a previously-sent log with bounce/complaint data.
     * M3.2.X.4 itself never calls this.
     *
     * @param array<string, mixed> $payload
     */
    public function setRawEvent(array $payload): void
    {
        $this->rawEvent = $payload;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
