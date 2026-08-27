<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A scheduled / recurring notification. Each due occurrence produces a
 * queued NotificationBroadcast (sent by the broadcast dispatcher). The
 * message keeps its {{variables}} unresolved; they resolve at each send.
 *
 * Recurrence is intentionally simple + extensible: once | daily | weekly |
 * monthly. next_run_at is the single source of truth for "when next" -
 * the dispatcher claims a schedule whose next_run_at has passed, emits a
 * broadcast, then advances (skipping any missed occurrences so a lagging
 * cron never floods). audience_mode is 'dynamic' (resolve current audience
 * at each send), the column is present for a future 'snapshot' mode.
 */
#[ORM\Entity(repositoryClass: NotificationScheduleRepository::class)]
#[ORM\Table(name: 'notification_schedules')]
#[ORM\Index(columns: ['status'], name: 'idx_notification_schedules_status')]
#[ORM\Index(columns: ['status', 'next_run_at'], name: 'idx_notification_schedules_due')]
class NotificationSchedule
{
    public const FREQ_ONCE = 'once';
    public const FREQ_DAILY = 'daily';
    public const FREQ_WEEKLY = 'weekly';
    public const FREQ_MONTHLY = 'monthly';
    public const FREQUENCIES = [self::FREQ_ONCE, self::FREQ_DAILY, self::FREQ_WEEKLY, self::FREQ_MONTHLY];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const AUDIENCE_DYNAMIC = 'dynamic';
    public const AUDIENCE_SNAPSHOT = 'snapshot';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\Column(name: 'template_id', type: 'bigint', nullable: true)]
    private ?int $templateId = null;

    #[ORM\Column(name: 'name', type: 'string', length: 200, nullable: true)]
    private ?string $name = null;

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

    #[ORM\Column(name: 'audience_mode', type: 'string', length: 12)]
    private string $audienceMode = self::AUDIENCE_DYNAMIC;

    #[ORM\Column(name: 'timezone', type: 'string', length: 64)]
    private string $timezone = 'Asia/Dubai';

    #[ORM\Column(name: 'frequency', type: 'string', length: 12)]
    private string $frequency;

    #[ORM\Column(name: 'start_at', type: 'datetime_immutable')]
    private DateTimeImmutable $startAt;

    #[ORM\Column(name: 'end_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $endAt = null;

    #[ORM\Column(name: 'next_run_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $nextRunAt = null;

    #[ORM\Column(name: 'last_run_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(name: 'status', type: 'string', length: 16)]
    private string $status = self::STATUS_SCHEDULED;

    #[ORM\Column(name: 'created_by_user_id', type: 'bigint', nullable: true)]
    private ?int $createdByUserId = null;

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
        string $frequency,
        DateTimeImmutable $startAt,
        ?DateTimeImmutable $endAt = null,
        ?int $createdByUserId = null,
        ?string $name = null,
        ?int $templateId = null,
        ?string $imageUrl = null,
        ?string $deepLink = null,
        ?array $data = null,
        string $timezone = 'Asia/Dubai',
        string $status = self::STATUS_SCHEDULED,
    ) {
        $now = new DateTimeImmutable();
        $this->title = $title;
        $this->body = $body;
        $this->audience = $audience;
        $this->frequency = $frequency;
        $this->startAt = $startAt;
        $this->endAt = $endAt;
        $this->createdByUserId = $createdByUserId;
        $this->name = $name;
        $this->templateId = $templateId;
        $this->imageUrl = $imageUrl;
        $this->deepLink = $deepLink;
        $this->data = $data;
        $this->timezone = $timezone;
        $this->status = $status;
        // A scheduled schedule fires first at start_at; a draft has no next run.
        $this->nextRunAt = $status === self::STATUS_SCHEDULED ? $startAt : null;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    // ── Recurrence ──────────────────────────────────────────────────────
    /** Advance after a run has been emitted: stamp last_run and compute the
     *  next occurrence (or complete). Skips missed occurrences so a lagging
     *  dispatcher emits at most one broadcast per due-check. */
    public function advanceAfterRun(DateTimeImmutable $now): void
    {
        $this->lastRunAt = $now;

        if ($this->frequency === self::FREQ_ONCE) {
            $this->nextRunAt = null;
            $this->status = self::STATUS_COMPLETED;
            $this->touch();
            return;
        }

        $next = $this->nextRunAt ?? $now;
        do {
            $next = $this->addInterval($next);
        } while ($next <= $now);

        if ($this->endAt !== null && $next > $this->endAt) {
            $this->nextRunAt = null;
            $this->status = self::STATUS_COMPLETED;
        } else {
            $this->nextRunAt = $next;
        }
        $this->touch();
    }

    private function addInterval(DateTimeImmutable $d): DateTimeImmutable
    {
        return match ($this->frequency) {
            self::FREQ_DAILY => $d->modify('+1 day'),
            self::FREQ_WEEKLY => $d->modify('+7 days'),
            self::FREQ_MONTHLY => $d->modify('+1 month'),
            default => $d->modify('+1 day'),
        };
    }

    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->nextRunAt = null;
        $this->touch();
    }

    /** Re-usable edit of the message + recurrence config (before completion). */
    public function reschedule(
        string $title,
        string $body,
        array $audience,
        string $frequency,
        DateTimeImmutable $startAt,
        ?DateTimeImmutable $endAt,
        ?string $name,
        ?int $templateId,
        ?string $imageUrl,
        ?string $deepLink,
        ?array $data,
        string $timezone,
    ): void {
        $this->title = $title;
        $this->body = $body;
        $this->audience = $audience;
        $this->frequency = $frequency;
        $this->startAt = $startAt;
        $this->endAt = $endAt;
        $this->name = $name;
        $this->templateId = $templateId;
        $this->imageUrl = $imageUrl;
        $this->deepLink = $deepLink;
        $this->data = $data;
        $this->timezone = $timezone;
        // Editing a live schedule re-arms it from the (possibly new) start.
        if ($this->status === self::STATUS_SCHEDULED) {
            $this->nextRunAt = $startAt;
        }
        $this->touch();
    }

    /** Set next_run to now so the dispatcher picks it up immediately. */
    public function triggerNow(DateTimeImmutable $now): void
    {
        if ($this->status === self::STATUS_SCHEDULED) {
            $this->nextRunAt = $now;
            $this->touch();
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true);
    }

    public function getId(): ?int { return $this->id; }
    public function getTemplateId(): ?int { return $this->templateId; }
    public function getName(): ?string { return $this->name; }
    public function getTitle(): string { return $this->title; }
    public function getBody(): string { return $this->body; }
    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function getDeepLink(): ?string { return $this->deepLink; }
    /** @return array<string, string>|null */
    public function getData(): ?array { return $this->data; }
    /** @return array<string, mixed> */
    public function getAudience(): array { return $this->audience; }
    public function getAudienceMode(): string { return $this->audienceMode; }
    public function getTimezone(): string { return $this->timezone; }
    public function getFrequency(): string { return $this->frequency; }
    public function getStartAt(): DateTimeImmutable { return $this->startAt; }
    public function getEndAt(): ?DateTimeImmutable { return $this->endAt; }
    public function getNextRunAt(): ?DateTimeImmutable { return $this->nextRunAt; }
    public function getLastRunAt(): ?DateTimeImmutable { return $this->lastRunAt; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedByUserId(): ?int { return $this->createdByUserId; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
}
