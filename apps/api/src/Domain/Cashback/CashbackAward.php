<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The cashback a customer earns on ONE order (UNIQUE order_id — that's what
 * makes accrual idempotent).
 *
 * Lifecycle
 * ---------
 *   pending   accrued when the order was paid; waits for delivery + hold
 *   issued    a cashback gift card was issued (gift_card_id set, UNIQUE)
 *   cancelled the order was cancelled/refunded/returned before release
 *   reversed  clawed back after issue (balance debited from the card)
 *
 * `amount` is the CURRENT award value: accrued at pay time, then reduced at
 * release for cancelled/returned items and partial refunds. `reversed_amount`
 * tracks clawbacks after issue (a partial return can claw back part of an
 * issued award without fully reversing it).
 */
#[ORM\Entity(repositoryClass: CashbackAwardRepository::class)]
#[ORM\Table(name: 'cashback_awards')]
#[ORM\HasLifecycleCallbacks]
class CashbackAward
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REVERSED = 'reversed';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ISSUED, self::STATUS_CANCELLED, self::STATUS_REVERSED];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'amount', type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    /** The amount accrued at pay time (before release-time adjustments). */
    #[ORM\Column(name: 'accrued_amount', type: 'decimal', precision: 10, scale: 2)]
    private string $accruedAmount;

    #[ORM\Column(name: 'reversed_amount', type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $reversedAmount = '0.00';

    #[ORM\Column(name: 'currency', type: 'string', length: 3, options: ['default' => 'AED'])]
    private string $currency = 'AED';

    #[ORM\Column(name: 'status', type: 'string', length: 16, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    /** Days after delivery before release (max of the matched rules' hold_days). */
    #[ORM\Column(name: 'hold_days', type: 'integer', options: ['default' => 14])]
    private int $holdDays;

    /** Lifetime of the gift card issued for this award (days). */
    #[ORM\Column(name: 'gift_card_validity_days', type: 'integer', options: ['default' => 365])]
    private int $giftCardValidityDays;

    /** When the gift card was actually issued (set on release). */
    #[ORM\Column(name: 'release_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $releasedAt = null;

    #[ORM\ManyToOne(targetEntity: GiftCard::class)]
    #[ORM\JoinColumn(name: 'gift_card_id', referencedColumnName: 'id', nullable: true, unique: true, onDelete: 'SET NULL')]
    private ?GiftCard $giftCard = null;

    #[ORM\Column(name: 'status_reason', type: 'string', length: 255, nullable: true)]
    private ?string $statusReason = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'snapshot', type: 'json', options: ['jsonb' => true])]
    private array $snapshot = [];

    #[ORM\Column(name: 'cancelled_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'reversed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $reversedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, CashbackAwardLine> */
    #[ORM\OneToMany(targetEntity: CashbackAwardLine::class, mappedBy: 'award', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        Order $order,
        User $user,
        string $amount,
        string $currency,
        int $holdDays,
        int $giftCardValidityDays,
        array $snapshot = [],
    ) {
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException('Cashback award amount must be positive.');
        }
        $this->order = $order;
        $this->user = $user;
        $this->amount = bcadd($amount, '0', 2);
        $this->accruedAmount = $this->amount;
        $this->currency = $currency;
        $this->holdDays = max(0, $holdDays);
        $this->giftCardValidityDays = max(1, $giftCardValidityDays);
        $this->snapshot = $snapshot;
        $this->lines = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function addLine(CashbackRule $rule, ?int $orderItemId, string $baseAmount, string $amount): CashbackAwardLine
    {
        $line = new CashbackAwardLine($this, $rule, $orderItemId, $baseAmount, $amount);
        $this->lines->add($line);
        return $line;
    }

    // ── Transitions ──────────────────────────────────────────────────

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isIssued(): bool { return $this->status === self::STATUS_ISSUED; }

    /** Set the final (release-time) amount before issuing. */
    public function adjustAmount(string $amount): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \LogicException("Cannot adjust a {$this->status} cashback award.");
        }
        $this->amount = bcadd($amount, '0', 2);
        $this->touch();
    }

    public function markIssued(GiftCard $card, DateTimeImmutable $at): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \LogicException("Cannot issue a {$this->status} cashback award.");
        }
        $this->status = self::STATUS_ISSUED;
        $this->giftCard = $card;
        $this->releasedAt = $at;
        $this->statusReason = null;
        $this->touch();
    }

    public function markCancelled(string $reason, ?DateTimeImmutable $at = null): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \LogicException("Cannot cancel a {$this->status} cashback award.");
        }
        $this->status = self::STATUS_CANCELLED;
        $this->statusReason = mb_substr($reason, 0, 255);
        $this->cancelledAt = $at ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->touch();
    }

    /**
     * Record a clawback after issue.
     *
     * $value is how much of the award is taken back (no longer earned);
     * $collected is what was actually debited from the cashback card (the
     * customer may already have spent part of it; the rest is written off).
     * The award becomes `reversed` on a full reversal, or once the cumulative
     * reversed value reaches the award amount. A partial reversal (one
     * returned item) leaves it `issued`. Each reversal is logged in the
     * snapshot under `reversals`.
     */
    public function recordReversal(
        string $value,
        string $collected,
        string $reason,
        bool $full,
        ?DateTimeImmutable $at = null,
    ): void {
        if ($this->status !== self::STATUS_ISSUED) {
            throw new \LogicException("Cannot reverse a {$this->status} cashback award.");
        }
        $at ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->reversedAmount = bcadd($this->reversedAmount, $value, 2);
        if ($full || bccomp($this->reversedAmount, $this->amount, 2) >= 0) {
            $this->reversedAmount = $this->amount;
            $this->status = self::STATUS_REVERSED;
            $this->reversedAt = $at;
        }
        $this->statusReason = mb_substr($reason, 0, 255);

        $log = $this->snapshot['reversals'] ?? [];
        if (!is_array($log)) {
            $log = [];
        }
        $log[] = [
            'at' => $at->format(DATE_ATOM),
            'value' => bcadd($value, '0', 2),
            'collected' => bcadd($collected, '0', 2),
            'written_off' => bcsub(bcadd($value, '0', 2), bcadd($collected, '0', 2), 2),
            'reason' => mb_substr($reason, 0, 255),
        ];
        $this->snapshot['reversals'] = $log;
        $this->touch();
    }

    /** What is still claw-back-able (issued amount minus prior clawbacks). */
    public function remainingAmount(): string
    {
        $r = bcsub($this->amount, $this->reversedAmount, 2);
        return bccomp($r, '0.00', 2) < 0 ? '0.00' : $r;
    }

    /**
     * Expected (pending) or actual (issued) release time. Pending: order
     * delivered_at + hold_days, or null while the order is not delivered.
     */
    public function expectedReleaseAt(): ?DateTimeImmutable
    {
        if ($this->releasedAt !== null) {
            return $this->releasedAt;
        }
        if ($this->status !== self::STATUS_PENDING) {
            return null;
        }
        $delivered = $this->order->getDeliveredAt();
        if ($delivered === null) {
            return null;
        }
        return $delivered->modify('+' . $this->holdDays . ' days');
    }

    /** @param array<string, mixed> $extra */
    public function mergeSnapshot(array $extra): void
    {
        $this->snapshot = array_merge($this->snapshot, $extra);
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    // ── Accessors ────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function getUser(): User { return $this->user; }
    public function getAmount(): string { return $this->amount; }
    public function getAccruedAmount(): string { return $this->accruedAmount; }
    public function getReversedAmount(): string { return $this->reversedAmount; }
    public function getCurrency(): string { return $this->currency; }
    public function getStatus(): string { return $this->status; }
    public function getHoldDays(): int { return $this->holdDays; }
    public function getGiftCardValidityDays(): int { return $this->giftCardValidityDays; }
    public function getReleasedAt(): ?DateTimeImmutable { return $this->releasedAt; }
    public function getGiftCard(): ?GiftCard { return $this->giftCard; }
    public function getStatusReason(): ?string { return $this->statusReason; }
    /** @return array<string, mixed> */
    public function getSnapshot(): array { return $this->snapshot; }
    public function getCancelledAt(): ?DateTimeImmutable { return $this->cancelledAt; }
    public function getReversedAt(): ?DateTimeImmutable { return $this->reversedAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
    /** @return Collection<int, CashbackAwardLine> */
    public function getLines(): Collection { return $this->lines; }
}
