<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One push-broadcast execution — an immediate admin send today, and a
 * scheduled/recurring occurrence in a later phase.
 *
 * Lifecycle: queued -> processing -> (sent | partially_delivered | failed).
 * A broadcast with no recipients finishes as 'sent' with zero counters.
 * cancelled is a terminal state set before dispatch.
 *
 * Delivery honesty: FCM v1 only confirms "accepted", so sent_count is the
 * number of devices FCM accepted (not device-confirmed delivery) and
 * failed_count carries rejections. There is no fabricated "delivered".
 *
 * Counters are denormalised on the broadcast so the history table renders
 * without scanning the (potentially huge) recipients table.
 */
#[ORM\Entity(repositoryClass: NotificationBroadcastRepository::class)]
#[ORM\Table(name: 'notification_broadcasts')]
#[ORM\Index(columns: ['status'], name: 'idx_notification_broadcasts_status')]
#[ORM\Index(columns: ['status', 'created_at'], name: 'idx_notification_broadcasts_status_created')]
class NotificationBroadcast
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_PARTIALLY_DELIVERED = 'partially_delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const RESEND_ALL = 'all';
    public const RESEND_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\Column(name: 'schedule_id', type: 'bigint', nullable: true)]
    private ?int $scheduleId = null;

    #[ORM\Column(name: 'template_id', type: 'bigint', nullable: true)]
    private ?int $templateId = null;

    #[ORM\Column(name: 'resent_from_broadcast_id', type: 'bigint', nullable: true)]
    private ?int $resentFromBroadcastId = null;

    #[ORM\Column(name: 'resend_mode', type: 'string', length: 12, nullable: true)]
    private ?string $resendMode = null;

    #[ORM\Column(name: 'title', type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(name: 'body', type: 'text')]
    private string $body;

    #[ORM\Column(name: 'image_url', type: 'string', length: 1000, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(name: 'deep_link', type: 'string', length: 1000, nullable: true)]
    private ?string $deepLink = null;

    /** @var array<string, string>|null */
    #[ORM\Column(name: 'data', type: 'json', nullable: true)]
    private ?array $data = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'audience', type: 'json')]
    private array $audience;

    #[ORM\Column(name: 'status', type: 'string', length: 24)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(name: 'recipients_total', type: 'integer')]
    private int $recipientsTotal = 0;

    #[ORM\Column(name: 'sent_count', type: 'integer')]
    private int $sentCount = 0;

    #[ORM\Column(name: 'failed_count', type: 'integer')]
    private int $failedCount = 0;

    #[ORM\Column(name: 'android_total', type: 'integer')]
    private int $androidTotal = 0;

    #[ORM\Column(name: 'ios_total', type: 'integer')]
    private int $iosTotal = 0;

    #[ORM\Column(name: 'android_sent', type: 'integer')]
    private int $androidSent = 0;

    #[ORM\Column(name: 'ios_sent', type: 'integer')]
    private int $iosSent = 0;

    #[ORM\Column(name: 'android_failed', type: 'integer')]
    private int $androidFailed = 0;

    #[ORM\Column(name: 'ios_failed', type: 'integer')]
    private int $iosFailed = 0;

    /** @var array<string, int>|null */
    #[ORM\Column(name: 'failure_kinds', type: 'json', nullable: true)]
    private ?array $failureKinds = null;

    #[ORM\Column(name: 'error_sample', type: 'text', nullable: true)]
    private ?string $errorSample = null;

    #[ORM\Column(name: 'sent_by_user_id', type: 'bigint', nullable: true)]
    private ?int $sentByUserId = null;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $audience
     * @param array<string, string>|null $data
     */
    public function __construct(
        string $title,
        string $body,
        array $audience,
        ?int $sentByUserId = null,
        ?string $imageUrl = null,
        ?string $deepLink = null,
        ?array $data = null,
    ) {
        $now = new DateTimeImmutable();
        $this->title = $title;
        $this->body = $body;
        $this->audience = $audience;
        $this->sentByUserId = $sentByUserId;
        $this->imageUrl = $imageUrl;
        $this->deepLink = $deepLink;
        $this->data = $data;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    // ── Lifecycle mutators (used by BroadcastSender) ────────────────────
    public function markProcessing(): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->startedAt = new DateTimeImmutable();
        $this->touch();
    }

    public function setRecipientTotals(int $total, int $android, int $ios): void
    {
        $this->recipientsTotal = $total;
        $this->androidTotal = $android;
        $this->iosTotal = $ios;
        $this->touch();
    }

    public function recordSent(string $platform): void
    {
        $this->sentCount++;
        if ($platform === DeviceToken::PLATFORM_IOS) {
            $this->iosSent++;
        } else {
            $this->androidSent++;
        }
    }

    public function recordFailed(string $platform, string $kind): void
    {
        $this->failedCount++;
        if ($platform === DeviceToken::PLATFORM_IOS) {
            $this->iosFailed++;
        } else {
            $this->androidFailed++;
        }
        $kinds = $this->failureKinds ?? [];
        $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        $this->failureKinds = $kinds;
    }

    public function setErrorSample(?string $sample): void
    {
        if ($this->errorSample === null && $sample !== null) {
            $this->errorSample = $sample;
        }
    }

    /** Finalise to the terminal status implied by the counters. */
    public function finish(): void
    {
        if ($this->failedCount === 0) {
            $this->status = self::STATUS_SENT;
        } elseif ($this->sentCount === 0) {
            $this->status = self::STATUS_FAILED;
        } else {
            $this->status = self::STATUS_PARTIALLY_DELIVERED;
        }
        $this->finishedAt = new DateTimeImmutable();
        $this->touch();
    }

    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->touch();
    }

    /** Hard failure (e.g. the dispatcher threw mid-send) — never leave a
     *  broadcast stuck in 'processing'. */
    public function failWith(?string $sample): void
    {
        $this->status = self::STATUS_FAILED;
        $this->setErrorSample($sample);
        $this->finishedAt = new DateTimeImmutable();
        $this->touch();
    }

    public function markResend(int $sourceBroadcastId, string $mode): void
    {
        $this->resentFromBroadcastId = $sourceBroadcastId;
        $this->resendMode = $mode;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    // ── Getters ─────────────────────────────────────────────────────────
    public function getId(): ?int { return $this->id; }
    public function getScheduleId(): ?int { return $this->scheduleId; }
    public function getTemplateId(): ?int { return $this->templateId; }
    public function getResentFromBroadcastId(): ?int { return $this->resentFromBroadcastId; }
    public function getResendMode(): ?string { return $this->resendMode; }
    public function getTitle(): string { return $this->title; }
    public function getBody(): string { return $this->body; }
    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function getDeepLink(): ?string { return $this->deepLink; }
    /** @return array<string, string>|null */
    public function getData(): ?array { return $this->data; }
    /** @return array<string, mixed> */
    public function getAudience(): array { return $this->audience; }
    public function getStatus(): string { return $this->status; }
    public function getRecipientsTotal(): int { return $this->recipientsTotal; }
    public function getSentCount(): int { return $this->sentCount; }
    public function getFailedCount(): int { return $this->failedCount; }
    public function getAndroidTotal(): int { return $this->androidTotal; }
    public function getIosTotal(): int { return $this->iosTotal; }
    public function getAndroidSent(): int { return $this->androidSent; }
    public function getIosSent(): int { return $this->iosSent; }
    public function getAndroidFailed(): int { return $this->androidFailed; }
    public function getIosFailed(): int { return $this->iosFailed; }
    /** @return array<string, int>|null */
    public function getFailureKinds(): ?array { return $this->failureKinds; }
    public function getErrorSample(): ?string { return $this->errorSample; }
    public function getSentByUserId(): ?int { return $this->sentByUserId; }
    public function getStartedAt(): ?DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?DateTimeImmutable { return $this->finishedAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
}
