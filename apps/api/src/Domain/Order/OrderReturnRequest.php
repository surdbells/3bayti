<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer-initiated request to return one or more items from a
 * delivered order (M3.2.X.18).
 *
 * Background
 * ==========
 * Per the M3.2.X.18 plan, customers can request returns for items
 * they received. The request goes to the vendor's return list (read-
 * only visibility), admin reviews the photo evidence + decides
 * approve/deny, 3bayti's ops team picks up from customer + delivers
 * to vendor, refund is recorded manually (off-Noon-API per
 * Q-Refund).
 *
 * Status state machine
 * ====================
 *
 *                  ┌─→ denied (terminal)
 *    pending ──────┤
 *                  └─→ approved → picked_up → delivered_to_vendor → refunded (terminal)
 *                         ↑
 *                    cancelled (terminal — customer-initiated only, before approved)
 *
 * State transitions are enforced by the mark*() methods on this
 * entity — illegal transitions throw \DomainException.
 *
 * Q-VendorRole decision (locked):
 *   Vendor does NOT approve/deny. Admin makes that decision based on
 *   the photo evidence the customer attached. Vendor's only action
 *   is confirmReceived() once the goods arrive back at their
 *   warehouse — that transitions the request to delivered_to_vendor.
 *
 * Q-Refund decision (locked):
 *   The refund is a manual operational record, not a call to the
 *   Noon refund API. The refund_amount lives on the related
 *   OrderReturnRefund child entity (created when ops invokes the
 *   markRefunded() transition with all the manual-record fields).
 *
 * Multi-vendor orders
 * ===================
 * A single OrderReturnRequest can span items from multiple vendors
 * — see the items collection. Each OrderReturnRequestItem carries a
 * denormalized vendor_id so vendor-facing queries can filter without
 * joining through order_items. The request itself stays unified
 * (one customer-facing entity), but vendor-portal endpoints filter
 * down to "items I sold" at query time.
 *
 * Reason taxonomy (Q-ReturnReasons = A)
 * =====================================
 * Constrained list of reasons; `other` requires non-empty
 * customer_notes per the DTO layer.
 */
#[ORM\Entity(repositoryClass: OrderReturnRequestRepository::class)]
#[ORM\Table(name: 'order_return_requests')]
#[ORM\HasLifecycleCallbacks]
class OrderReturnRequest
{
    // Status taxonomy (VARCHAR + CHECK constraint at DB level).
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PICKED_UP = 'picked_up';
    public const STATUS_DELIVERED_TO_VENDOR = 'delivered_to_vendor';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_DENIED = 'denied';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALL_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_PICKED_UP,
        self::STATUS_DELIVERED_TO_VENDOR,
        self::STATUS_REFUNDED,
        self::STATUS_DENIED,
        self::STATUS_CANCELLED,
    ];

    /** States the request can't be transitioned out of. */
    public const TERMINAL_STATUSES = [
        self::STATUS_REFUNDED,
        self::STATUS_DENIED,
        self::STATUS_CANCELLED,
    ];

    // Reason taxonomy (VARCHAR + CHECK constraint at DB level).
    public const REASON_DEFECTIVE = 'defective';
    public const REASON_WRONG_ITEM = 'wrong_item';
    public const REASON_DAMAGED_IN_TRANSIT = 'damaged_in_transit';
    public const REASON_NOT_AS_DESCRIBED = 'not_as_described';
    public const REASON_CHANGED_MIND = 'changed_mind';
    public const REASON_SIZE_ISSUE = 'size_issue';
    public const REASON_OTHER = 'other';

    public const ALL_REASONS = [
        self::REASON_DEFECTIVE,
        self::REASON_WRONG_ITEM,
        self::REASON_DAMAGED_IN_TRANSIT,
        self::REASON_NOT_AS_DESCRIBED,
        self::REASON_CHANGED_MIND,
        self::REASON_SIZE_ISSUE,
        self::REASON_OTHER,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'customer_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $customer;

    #[ORM\Column(name: 'status', type: 'string', length: 32)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'reason', type: 'string', length: 32)]
    private string $reason;

    #[ORM\Column(name: 'customer_notes', type: 'text', nullable: true)]
    private ?string $customerNotes = null;

    #[ORM\Column(name: 'admin_notes', type: 'text', nullable: true)]
    private ?string $adminNotes = null;

    #[ORM\Column(name: 'requested_at', type: 'datetime_immutable')]
    private DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'decided_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_admin_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedByAdmin = null;

    #[ORM\Column(name: 'picked_up_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $pickedUpAt = null;

    #[ORM\Column(name: 'delivered_to_vendor_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $deliveredToVendorAt = null;

    #[ORM\Column(name: 'refunded_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $refundedAt = null;

    #[ORM\Column(name: 'cancelled_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, OrderReturnRequestItem> */
    #[ORM\OneToMany(
        targetEntity: OrderReturnRequestItem::class,
        mappedBy: 'returnRequest',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $items;

    /** @var Collection<int, OrderReturnRequestPhoto> */
    #[ORM\OneToMany(
        targetEntity: OrderReturnRequestPhoto::class,
        mappedBy: 'returnRequest',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $photos;

    #[ORM\OneToOne(targetEntity: OrderReturnRefund::class, mappedBy: 'returnRequest', cascade: ['persist', 'remove'])]
    private ?OrderReturnRefund $refund = null;

    public function __construct(
        Order $order,
        User $customer,
        string $reason,
        ?string $customerNotes = null,
    ) {
        if (!in_array($reason, self::ALL_REASONS, true)) {
            throw new \InvalidArgumentException(
                "Unknown return reason '{$reason}'. "
                . 'Must be one of: ' . implode(', ', self::ALL_REASONS),
            );
        }
        if ($reason === self::REASON_OTHER) {
            // Validation also applied at DTO layer; defense-in-depth here.
            $trimmedNotes = $customerNotes !== null ? trim($customerNotes) : '';
            if ($trimmedNotes === '') {
                throw new \InvalidArgumentException(
                    "Return reason 'other' requires non-empty customer_notes.",
                );
            }
        }
        $this->order = $order;
        $this->customer = $customer;
        $this->reason = $reason;
        $this->customerNotes = $customerNotes !== null ? trim($customerNotes) : null;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->requestedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->items = new ArrayCollection();
        $this->photos = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    // -----------------------------------------------------------------
    // State transitions
    // -----------------------------------------------------------------

    /**
     * Admin approves the return request. Allowed only from `pending`.
     */
    public function approve(User $admin, ?string $adminNotes = null): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException(
                "Cannot approve from status '{$this->status}'; must be 'pending'."
            );
        }
        $this->status = self::STATUS_APPROVED;
        $this->decidedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->decidedByAdmin = $admin;
        if ($adminNotes !== null) {
            $this->adminNotes = trim($adminNotes);
        }
    }

    /**
     * Admin denies the return request. Allowed only from `pending`.
     * adminNotes is required (the customer deserves a reason).
     */
    public function deny(User $admin, string $adminNotes): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException(
                "Cannot deny from status '{$this->status}'; must be 'pending'."
            );
        }
        if (trim($adminNotes) === '') {
            throw new \InvalidArgumentException('Denial requires non-empty admin_notes.');
        }
        $this->status = self::STATUS_DENIED;
        $this->decidedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->decidedByAdmin = $admin;
        $this->adminNotes = trim($adminNotes);
    }

    /**
     * Admin marks the goods as picked up from the customer by ops.
     * Allowed only from `approved`.
     */
    public function markPickedUp(): void
    {
        if ($this->status !== self::STATUS_APPROVED) {
            throw new \DomainException(
                "Cannot mark picked-up from status '{$this->status}'; must be 'approved'."
            );
        }
        $this->status = self::STATUS_PICKED_UP;
        $this->pickedUpAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Vendor (or admin on their behalf) confirms goods received at
     * the vendor warehouse. Allowed only from `picked_up`.
     */
    public function confirmReceivedByVendor(): void
    {
        if ($this->status !== self::STATUS_PICKED_UP) {
            throw new \DomainException(
                "Cannot confirm vendor receipt from status '{$this->status}'; must be 'picked_up'."
            );
        }
        $this->status = self::STATUS_DELIVERED_TO_VENDOR;
        $this->deliveredToVendorAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Admin records the manual refund and transitions to terminal.
     * Allowed only from `delivered_to_vendor`. The refund entity is
     * passed in by the controller — created via OrderReturnRefund
     * with the manual-method fields the admin supplied.
     */
    public function markRefunded(OrderReturnRefund $refund): void
    {
        if ($this->status !== self::STATUS_DELIVERED_TO_VENDOR) {
            throw new \DomainException(
                "Cannot mark refunded from status '{$this->status}'; "
                . "must be 'delivered_to_vendor'."
            );
        }
        $this->status = self::STATUS_REFUNDED;
        $this->refundedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->refund = $refund;
    }

    /**
     * Customer withdraws the request. Allowed only from `pending`
     * (once admin has decided, customer can't unilaterally cancel).
     */
    public function cancelByCustomer(): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException(
                "Cannot cancel from status '{$this->status}'; must be 'pending'. "
                . 'Contact support if you need to abandon a return that has been approved.'
            );
        }
        $this->status = self::STATUS_CANCELLED;
        $this->cancelledAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    // -----------------------------------------------------------------
    // Item / photo collection management
    // -----------------------------------------------------------------

    public function addItem(OrderReturnRequestItem $item): void
    {
        $this->items->add($item);
        $item->setReturnRequest($this);
    }

    public function addPhoto(OrderReturnRequestPhoto $photo): void
    {
        $this->photos->add($photo);
        $photo->setReturnRequest($this);
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------

    public function getId(): ?int { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function getCustomer(): User { return $this->customer; }
    public function getStatus(): string { return $this->status; }
    public function getReason(): string { return $this->reason; }
    public function getCustomerNotes(): ?string { return $this->customerNotes; }
    public function getAdminNotes(): ?string { return $this->adminNotes; }
    public function getRequestedAt(): DateTimeImmutable { return $this->requestedAt; }
    public function getDecidedAt(): ?DateTimeImmutable { return $this->decidedAt; }
    public function getDecidedByAdmin(): ?User { return $this->decidedByAdmin; }
    public function getPickedUpAt(): ?DateTimeImmutable { return $this->pickedUpAt; }
    public function getDeliveredToVendorAt(): ?DateTimeImmutable { return $this->deliveredToVendorAt; }
    public function getRefundedAt(): ?DateTimeImmutable { return $this->refundedAt; }
    public function getCancelledAt(): ?DateTimeImmutable { return $this->cancelledAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
    /** @return Collection<int, OrderReturnRequestItem> */
    public function getItems(): Collection { return $this->items; }
    /** @return Collection<int, OrderReturnRequestPhoto> */
    public function getPhotos(): Collection { return $this->photos; }
    public function getRefund(): ?OrderReturnRefund { return $this->refund; }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Distinct vendor IDs across this request's items. Used by the
     * notification fan-out to deduplicate per-vendor emails when
     * multiple items from the same vendor are returned together.
     *
     * @return list<int>
     */
    public function getVendorIds(): array
    {
        $ids = [];
        foreach ($this->items as $item) {
            $vendorId = $item->getVendor()->getId();
            if ($vendorId !== null && !in_array($vendorId, $ids, true)) {
                $ids[] = $vendorId;
            }
        }
        return $ids;
    }
}
