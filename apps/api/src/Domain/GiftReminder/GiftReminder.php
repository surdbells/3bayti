<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\GiftReminder;

use Bayti\Api\Domain\Common\Timestamps;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer-saved gift reminder: who the gift is for, the occasion, and the
 * date, plus optional budget/category/note hints. A scheduled cron sends push +
 * email nudges at 14/7/2 days before the date, deep-linking into the Ain Gift
 * Concierge. The reminder stores NO product ids — gift picks are resolved live
 * at tap time so they always pass the orderable + in-stock gate.
 */
#[ORM\Entity(repositoryClass: GiftReminderRepository::class)]
#[ORM\Table(name: 'gift_reminders')]
#[ORM\Index(columns: ['user_id'], name: 'idx_gift_reminders_user')]
#[ORM\Index(columns: ['remind_date'], name: 'idx_gift_reminders_remind_date')]
#[ORM\HasLifecycleCallbacks]
class GiftReminder
{
    use Timestamps;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'recipient_name', type: 'string', length: 200)]
    private string $recipientName;

    #[ORM\Column(type: 'string', length: 100)]
    private string $occasion;

    #[ORM\Column(name: 'remind_date', type: 'date_immutable')]
    private DateTimeImmutable $remindDate;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** Decimal money is a string in Doctrine (like every other money field). */
    #[ORM\Column(name: 'budget_max', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $budgetMax = null;

    #[ORM\Column(name: 'category_slug', type: 'string', length: 160, nullable: true)]
    private ?string $categorySlug = null;

    /** Day count of the most-urgent nudge already sent (14/7/2), or null. */
    #[ORM\Column(name: 'last_notified_stage', type: 'smallint', nullable: true)]
    private ?int $lastNotifiedStage = null;

    public function __construct(
        User $user,
        string $recipientName,
        string $occasion,
        DateTimeImmutable $remindDate,
        ?string $note = null,
        ?string $budgetMax = null,
        ?string $categorySlug = null,
    ) {
        $this->user = $user;
        $this->recipientName = trim($recipientName);
        $this->occasion = trim($occasion);
        $this->remindDate = $remindDate;
        $this->note = $note !== null ? trim($note) : null;
        $this->budgetMax = $budgetMax;
        $this->categorySlug = $categorySlug !== null ? trim($categorySlug) : null;
        $this->initTimestamps();
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->touchUpdatedAt();
    }

    /**
     * Full-replace update (PUT semantics). Changing the date resets the nudge
     * marker so the new date's 14/7/2-day nudges fire again.
     */
    public function update(
        string $recipientName,
        string $occasion,
        DateTimeImmutable $remindDate,
        ?string $note,
        ?string $budgetMax,
        ?string $categorySlug,
    ): void {
        if ($remindDate->format('Y-m-d') !== $this->remindDate->format('Y-m-d')) {
            $this->lastNotifiedStage = null;
        }
        $this->recipientName = trim($recipientName);
        $this->occasion = trim($occasion);
        $this->remindDate = $remindDate;
        $this->note = $note !== null ? trim($note) : null;
        $this->budgetMax = $budgetMax;
        $this->categorySlug = $categorySlug !== null ? trim($categorySlug) : null;
    }

    public function markStageNotified(int $stageDays): void
    {
        $this->lastNotifiedStage = $stageDays;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getRecipientName(): string { return $this->recipientName; }
    public function getOccasion(): string { return $this->occasion; }
    public function getRemindDate(): DateTimeImmutable { return $this->remindDate; }
    public function getNote(): ?string { return $this->note; }
    public function getBudgetMax(): ?string { return $this->budgetMax; }
    public function getCategorySlug(): ?string { return $this->categorySlug; }
    public function getLastNotifiedStage(): ?int { return $this->lastNotifiedStage; }
}
