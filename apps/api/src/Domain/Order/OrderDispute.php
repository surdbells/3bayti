<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * A dispute / chargeback raised against an order.
 *
 * Created by the Noon webhook handler when a dispute event arrives.
 * Mutated by admin actions during the dispute lifecycle (in_review,
 * then resolved_won/resolved_lost/withdrawn).
 *
 * Audit
 * -----
 * All admin mutations (markInReview, markResolved) emit AuditLog
 * entries via the controller layer. The entity itself doesn't emit
 * audit, it's a pure state holder.
 *
 * Idempotent webhook handling
 * ---------------------------
 * The provider_dispute_id has a UNIQUE constraint at the DB layer.
 * If Noon re-delivers the same dispute event (retry on 5xx, network
 * blip, etc.), the webhook handler looks up by this id and either
 * updates the existing row or no-ops. Without this, retry storms
 * would create duplicate dispute rows.
 *
 * Why no FK on resolved_by_user_id
 * --------------------------------
 * Admin users may be deleted later (offboarding, account closure).
 * The dispute resolution audit trail must survive, we keep the
 * id as data, not as a referential constraint that could either
 * CASCADE-delete history or RESTRICT future deletes.
 */
#[ORM\Entity(repositoryClass: OrderDisputeRepository::class)]
#[ORM\Table(name: 'order_disputes')]
#[ORM\Index(columns: ['order_id', 'created_at'], name: 'idx_order_disputes_order_created')]
#[ORM\Index(columns: ['status', 'created_at'], name: 'idx_order_disputes_status')]
#[ORM\HasLifecycleCallbacks]
class OrderDispute
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_RESOLVED_WON = 'resolved_won';
    public const STATUS_RESOLVED_LOST = 'resolved_lost';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const ALL_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_REVIEW,
        self::STATUS_RESOLVED_WON,
        self::STATUS_RESOLVED_LOST,
        self::STATUS_WITHDRAWN,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_RESOLVED_WON,
        self::STATUS_RESOLVED_LOST,
        self::STATUS_WITHDRAWN,
    ];

    /** Doctrine ORM populates via reflection at hydration; nullable until persist. */
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;  // @phpstan-ignore property.unusedType

    /**
     * Linked order. Nullable for orphan disputes whose provider_order_ref
     * didn't match any of our orders at webhook arrival time.
     */
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Order $order = null;

    #[ORM\Column(name: 'provider_order_ref', type: 'string', length: 64)]
    private string $providerOrderRef;

    #[ORM\Column(name: 'provider_dispute_id', type: 'string', length: 128, nullable: true)]
    private ?string $providerDisputeId = null;

    #[ORM\Column(name: 'event_type', type: 'string', length: 64)]
    private string $eventType;

    #[ORM\Column(name: 'status', type: 'string', length: 32)]
    private string $status = self::STATUS_OPEN;

    /** Stored as decimal string for money precision (bcmath). */
    #[ORM\Column(name: 'amount', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $amount = null;

    #[ORM\Column(name: 'currency', type: 'string', length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(name: 'reason', type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'resolution_note', type: 'text', nullable: true)]
    private ?string $resolutionNote = null;

    /** NOT a FK, admin users may be removed; audit must survive. */
    #[ORM\Column(name: 'resolved_by_user_id', type: 'bigint', nullable: true)]
    private ?int $resolvedByUserId = null;

    #[ORM\Column(name: 'resolved_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $resolvedAt = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'raw_event', type: 'json')]
    private array $rawEvent;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $rawEvent
     */
    public function __construct(
        string $providerOrderRef,
        string $eventType,
        array $rawEvent,
        ?Order $order = null,
        ?string $providerDisputeId = null,
        ?string $amount = null,
        ?string $currency = null,
        ?string $reason = null,
    ) {
        if ($providerOrderRef === '' || strlen($providerOrderRef) > 64) {
            throw new \InvalidArgumentException(
                "providerOrderRef must be 1-64 chars, got " . strlen($providerOrderRef)
            );
        }
        if ($eventType === '' || strlen($eventType) > 64) {
            throw new \InvalidArgumentException(
                "eventType must be 1-64 chars, got " . strlen($eventType)
            );
        }
        if ($amount !== null) {
            if (bccomp($amount, '0.00', 2) < 0) {
                throw new \InvalidArgumentException("amount must be >= 0, got '{$amount}'");
            }
        }

        $this->providerOrderRef = $providerOrderRef;
        $this->eventType = $eventType;
        $this->rawEvent = $rawEvent;
        $this->order = $order;
        $this->providerDisputeId = $providerDisputeId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->reason = $reason;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Advance from 'open' to 'in_review'. Idempotent: re-calling on
     * an already-in_review dispute is a no-op. Throws if dispute is
     * already terminal.
     */
    public function markInReview(): void
    {
        if ($this->status === self::STATUS_IN_REVIEW) {
            return;
        }
        if (in_array($this->status, self::TERMINAL_STATUSES, true)) {
            throw new \DomainException(
                "Cannot mark dispute as in_review: already in terminal state '{$this->status}'"
            );
        }
        $this->status = self::STATUS_IN_REVIEW;
        $this->touchUpdatedAt();
    }

    /**
     * Resolve the dispute. Records the resolving admin's id, note,
     * and timestamp. Throws if dispute is already terminal.
     *
     * @param string $resolutionStatus One of TERMINAL_STATUSES.
     */
    public function markResolved(string $resolutionStatus, string $resolutionNote, User $resolver): void
    {
        if (!in_array($resolutionStatus, self::TERMINAL_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "Resolution status must be one of: " . implode(', ', self::TERMINAL_STATUSES)
                . " — got '{$resolutionStatus}'"
            );
        }
        if (in_array($this->status, self::TERMINAL_STATUSES, true)) {
            throw new \DomainException(
                "Cannot resolve dispute: already in terminal state '{$this->status}'"
            );
        }
        $this->status = $resolutionStatus;
        $this->resolutionNote = $resolutionNote;
        $this->resolvedByUserId = $resolver->getId();
        $this->resolvedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->touchUpdatedAt();
    }

    private function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    // ===== Getters =====

    public function getId(): ?int { return $this->id; }
    public function getOrder(): ?Order { return $this->order; }
    public function getProviderOrderRef(): string { return $this->providerOrderRef; }
    public function getProviderDisputeId(): ?string { return $this->providerDisputeId; }
    public function getEventType(): string { return $this->eventType; }
    public function getStatus(): string { return $this->status; }
    public function getAmount(): ?string { return $this->amount; }
    public function getCurrency(): ?string { return $this->currency; }
    public function getReason(): ?string { return $this->reason; }
    public function getResolutionNote(): ?string { return $this->resolutionNote; }
    public function getResolvedByUserId(): ?int { return $this->resolvedByUserId; }
    public function getResolvedAt(): ?DateTimeImmutable { return $this->resolvedAt; }
    /** @return array<string, mixed> */
    public function getRawEvent(): array { return $this->rawEvent; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
