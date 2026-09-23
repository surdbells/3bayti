<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Customization;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer-initiated request for a bespoke customization (alteration,
 * embroidery, made-to-measure adjustment) on a specific product, quoted
 * and fulfilled by that product's vendor (Marketplace plan P5).
 *
 * Why this mirrors OrderReturnRequest
 * ===================================
 * It's the same house pattern: a request→review→resolve lifecycle between
 * a customer and a vendor, with in-entity transition methods that throw
 * \DomainException on an illegal move, string status columns backed by DB
 * CHECK constraints (no PHP/Doctrine enums), datetime_immutable stamps and
 * DECIMAL(10,2)-as-string money. It differs from returns on two axes:
 *   1. Product-scoped, not order-scoped — the customer customizes a live
 *      product before buying it, so there is no Order/OrderItem yet.
 *   2. The VENDOR reviews and quotes (returns are decided by admin). The
 *      customer then accepts (→ pays) or rejects.
 *
 * Status state machine
 * ====================
 *
 *                     ┌─→ declined  (terminal, vendor)
 *    pending ─────────┤
 *                     ├─→ cancelled (terminal, customer)
 *                     └─→ quoted ───┬─→ rejected  (terminal, customer)
 *                                   ├─→ cancelled (terminal, customer)
 *                                   └─→ accepted → paid → completed (terminal, vendor)
 *
 * Payment
 * =======
 * Accepting a quote does not charge anything by itself. The customer then
 * pays via the normal Noon checkout: an item-less synthetic Order is
 * created at the quote amount (the exact gift-card-purchase shape), and its
 * reference is stored on `payment_order_reference`. The Noon webhook's paid
 * branch looks the request up by that reference and calls markPaid().
 */
#[ORM\Entity(repositoryClass: CustomizationRequestRepository::class)]
#[ORM\Table(name: 'customization_requests')]
#[ORM\HasLifecycleCallbacks]
class CustomizationRequest
{
    // Status taxonomy (VARCHAR + CHECK constraint at DB level).
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUOTED = 'quoted';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_PAID = 'paid';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALL_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUOTED,
        self::STATUS_ACCEPTED,
        self::STATUS_PAID,
        self::STATUS_COMPLETED,
        self::STATUS_DECLINED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** States the request can't be transitioned out of. */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_DECLINED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses that still count as an "in-flight" request for the dup guard. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUOTED,
        self::STATUS_ACCEPTED,
        self::STATUS_PAID,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    /**
     * Denormalized owning vendor (derived from the product at construct
     * time), so vendor-portal queries filter without joining through
     * products — the same indexing decision OrderReturnRequestItem makes.
     */
    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Vendor $vendor;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'customer_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $customer;

    #[ORM\Column(name: 'status', type: 'string', length: 32)]
    private string $status = self::STATUS_PENDING;

    /** The customer's description of the customization they want. Required. */
    #[ORM\Column(name: 'customer_notes', type: 'text')]
    private string $customerNotes;

    /**
     * Optional snapshot of the customer's body measurements at request time
     * (so the vendor sees sizing even if the customer later edits their
     * profile). Free-form key→value map captured by the client.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'measurement_snapshot', type: 'json', nullable: true)]
    private ?array $measurementSnapshot = null;

    // --- Vendor quote (set on quote()) ---

    #[ORM\Column(name: 'quote_amount', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $quoteAmount = null;

    #[ORM\Column(name: 'quote_currency', type: 'string', length: 3, nullable: true)]
    private ?string $quoteCurrency = null;

    #[ORM\Column(name: 'quote_lead_time_days', type: 'integer', nullable: true)]
    private ?int $quoteLeadTimeDays = null;

    /** Vendor's message accompanying the quote, or the reason for a decline. */
    #[ORM\Column(name: 'vendor_notes', type: 'text', nullable: true)]
    private ?string $vendorNotes = null;

    /**
     * Reference of the synthetic Order created when the customer pays the
     * accepted quote (mirrors gift_cards.purchase_order_reference). The Noon
     * webhook resolves the request from this to call markPaid().
     */
    #[ORM\Column(name: 'payment_order_reference', type: 'string', length: 64, nullable: true)]
    private ?string $paymentOrderReference = null;

    // --- Lifecycle timestamps ---

    #[ORM\Column(name: 'requested_at', type: 'datetime_immutable')]
    private DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'quoted_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $quotedAt = null;

    #[ORM\Column(name: 'accepted_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(name: 'paid_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $paidAt = null;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'declined_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $declinedAt = null;

    #[ORM\Column(name: 'rejected_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $rejectedAt = null;

    #[ORM\Column(name: 'cancelled_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed>|null $measurementSnapshot
     */
    public function __construct(
        Product $product,
        Vendor $vendor,
        User $customer,
        string $customerNotes,
        ?array $measurementSnapshot = null,
    ) {
        $trimmedNotes = trim($customerNotes);
        if ($trimmedNotes === '') {
            throw new \InvalidArgumentException('A customization request requires a non-empty description.');
        }
        $this->product = $product;
        $this->vendor = $vendor;
        $this->customer = $customer;
        $this->customerNotes = $trimmedNotes;
        $this->measurementSnapshot = $measurementSnapshot;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->requestedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
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
     * Vendor quotes a price for the work. Allowed only from `pending`.
     */
    public function quote(string $amount, string $currency, ?int $leadTimeDays, ?string $vendorNotes): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException(
                "Cannot quote from status '{$this->status}'; must be 'pending'."
            );
        }
        self::assertPositiveMoney($amount);
        $currency = self::assertValidCurrency($currency);
        if ($leadTimeDays !== null && $leadTimeDays < 0) {
            throw new \InvalidArgumentException('Lead time cannot be negative.');
        }
        $this->status = self::STATUS_QUOTED;
        $this->quoteAmount = $amount;
        $this->quoteCurrency = $currency;
        $this->quoteLeadTimeDays = $leadTimeDays;
        $this->vendorNotes = $vendorNotes !== null && trim($vendorNotes) !== '' ? trim($vendorNotes) : null;
        $this->quotedAt = self::now();
    }

    /**
     * Vendor declines to take on the work. Allowed only from `pending`.
     */
    public function declineByVendor(?string $vendorNotes): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException(
                "Cannot decline from status '{$this->status}'; must be 'pending'."
            );
        }
        $this->status = self::STATUS_DECLINED;
        $this->vendorNotes = $vendorNotes !== null && trim($vendorNotes) !== '' ? trim($vendorNotes) : null;
        $this->declinedAt = self::now();
    }

    /**
     * Customer accepts the quote (does NOT charge — payment follows via
     * checkout). Allowed only from `quoted`.
     */
    public function acceptQuote(): void
    {
        if ($this->status !== self::STATUS_QUOTED) {
            throw new \DomainException(
                "Cannot accept from status '{$this->status}'; must be 'quoted'."
            );
        }
        $this->status = self::STATUS_ACCEPTED;
        $this->acceptedAt = self::now();
    }

    /**
     * Customer rejects the quote. Allowed only from `quoted`.
     */
    public function rejectQuote(): void
    {
        if ($this->status !== self::STATUS_QUOTED) {
            throw new \DomainException(
                "Cannot reject from status '{$this->status}'; must be 'quoted'."
            );
        }
        $this->status = self::STATUS_REJECTED;
        $this->rejectedAt = self::now();
    }

    /**
     * Customer withdraws the request. Allowed before they've committed to
     * pay, i.e. from `pending` or `quoted`.
     */
    public function cancelByCustomer(): void
    {
        if ($this->status !== self::STATUS_PENDING && $this->status !== self::STATUS_QUOTED) {
            throw new \DomainException(
                "Cannot cancel from status '{$this->status}'; must be 'pending' or 'quoted'."
            );
        }
        $this->status = self::STATUS_CANCELLED;
        $this->cancelledAt = self::now();
    }

    /**
     * Attach the synthetic payment order's reference when the customer
     * initiates checkout for an accepted quote. Allowed only from
     * `accepted`; idempotent-safe to overwrite a prior unpaid attempt.
     */
    public function attachPaymentOrderReference(string $orderReference): void
    {
        if ($this->status !== self::STATUS_ACCEPTED) {
            throw new \DomainException(
                "Cannot attach payment from status '{$this->status}'; must be 'accepted'."
            );
        }
        $this->paymentOrderReference = $orderReference;
    }

    /**
     * Payment confirmed (Noon webhook). Allowed only from `accepted`.
     */
    public function markPaid(): void
    {
        if ($this->status !== self::STATUS_ACCEPTED) {
            throw new \DomainException(
                "Cannot mark paid from status '{$this->status}'; must be 'accepted'."
            );
        }
        $this->status = self::STATUS_PAID;
        $this->paidAt = self::now();
    }

    /**
     * Vendor marks the customization work fulfilled. Allowed only from
     * `paid`. Terminal.
     */
    public function markCompleted(): void
    {
        if ($this->status !== self::STATUS_PAID) {
            throw new \DomainException(
                "Cannot mark completed from status '{$this->status}'; must be 'paid'."
            );
        }
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = self::now();
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------

    public function getId(): ?int { return $this->id; }
    public function getProduct(): Product { return $this->product; }
    public function getVendor(): Vendor { return $this->vendor; }
    public function getCustomer(): User { return $this->customer; }
    public function getStatus(): string { return $this->status; }
    public function getCustomerNotes(): string { return $this->customerNotes; }
    /** @return array<string, mixed>|null */
    public function getMeasurementSnapshot(): ?array { return $this->measurementSnapshot; }
    public function getQuoteAmount(): ?string { return $this->quoteAmount; }
    public function getQuoteCurrency(): ?string { return $this->quoteCurrency; }
    public function getQuoteLeadTimeDays(): ?int { return $this->quoteLeadTimeDays; }
    public function getVendorNotes(): ?string { return $this->vendorNotes; }
    public function getPaymentOrderReference(): ?string { return $this->paymentOrderReference; }
    public function getRequestedAt(): DateTimeImmutable { return $this->requestedAt; }
    public function getQuotedAt(): ?DateTimeImmutable { return $this->quotedAt; }
    public function getAcceptedAt(): ?DateTimeImmutable { return $this->acceptedAt; }
    public function getPaidAt(): ?DateTimeImmutable { return $this->paidAt; }
    public function getCompletedAt(): ?DateTimeImmutable { return $this->completedAt; }
    public function getDeclinedAt(): ?DateTimeImmutable { return $this->declinedAt; }
    public function getRejectedAt(): ?DateTimeImmutable { return $this->rejectedAt; }
    public function getCancelledAt(): ?DateTimeImmutable { return $this->cancelledAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * A price must be a positive DECIMAL(10,2)-shaped string. Mirrors
     * OrderReturnRefund::assertPositiveMoney (bcmath, no floats).
     */
    private static function assertPositiveMoney(string $amount): void
    {
        if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1) {
            throw new \InvalidArgumentException(
                "Quote amount '{$amount}' must be a decimal with up to 2 places."
            );
        }
        if (bccomp($amount, '0', 2) !== 1) {
            throw new \InvalidArgumentException('Quote amount must be greater than zero.');
        }
    }

    /** Currency must be a 3-letter ISO-4217 code; returned upper-cased. */
    private static function assertValidCurrency(string $currency): string
    {
        $upper = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $upper) !== 1) {
            throw new \InvalidArgumentException(
                "Currency '{$currency}' must be a 3-letter ISO-4217 code."
            );
        }
        return $upper;
    }
}
