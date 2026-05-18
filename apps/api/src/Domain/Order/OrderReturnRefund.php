<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * Manual off-Noon-API refund record for a completed return
 * (M3.2.X.18-A).
 *
 * Per Q-Refund (locked May 18, 2026): refunds for returned items are
 * NOT issued through the Noon refund API. Ops processes the refund
 * via whatever mechanism is appropriate (bank transfer, store
 * credit, cash) and records the event here for compliance + audit.
 *
 * The existing RefundOrderController + PaymentTransaction(REFUND)
 * machinery is intentionally NOT used for returns — that path stays
 * available for dispute-driven refunds and admin direct refunds
 * outside the return flow, but it talks to Noon. Returns use this
 * entity instead.
 *
 * One refund per return request
 * =============================
 * UNIQUE constraint on return_request_id at the DB level. A return
 * request has at most one OrderReturnRefund row. If a correction is
 * needed (rare), the operational path is admin-led — either a new
 * return or a manual override through other channels — not mutating
 * this row.
 *
 * Method taxonomy (Q-Refund)
 * ==========================
 * bank_transfer — wire to the customer's bank account
 * store_credit — applied to the customer's account balance
 * cash — physical cash refund (rare, COD orders)
 * other — escape hatch; notes field should explain
 *
 * Reference field
 * ===============
 * Optional free-text holding the bank-transfer reference, store-
 * credit ledger entry id, cash receipt number, etc. Searchable in
 * the admin UI when investigating disputes.
 *
 * recorded_by_admin_user_id ON DELETE SET NULL
 * ============================================
 * If the recording admin's user row is removed (rare, e.g.,
 * employee offboarded), the refund record stays but the
 * attribution becomes null. The audit log on AuditEmitter
 * captures the user_id at the time of the operation for the
 * canonical attribution trail.
 */
#[ORM\Entity(repositoryClass: OrderReturnRefundRepository::class)]
#[ORM\Table(name: 'order_return_refunds')]
class OrderReturnRefund
{
    public const METHOD_BANK_TRANSFER = 'bank_transfer';
    public const METHOD_STORE_CREDIT = 'store_credit';
    public const METHOD_CASH = 'cash';
    public const METHOD_OTHER = 'other';

    public const ALL_METHODS = [
        self::METHOD_BANK_TRANSFER,
        self::METHOD_STORE_CREDIT,
        self::METHOD_CASH,
        self::METHOD_OTHER,
    ];

    public const DEFAULT_CURRENCY = 'AED';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: OrderReturnRequest::class, inversedBy: 'refund')]
    #[ORM\JoinColumn(name: 'return_request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE', unique: true)]
    private OrderReturnRequest $returnRequest;

    #[ORM\Column(name: 'method', type: 'string', length: 32)]
    private string $method;

    #[ORM\Column(name: 'amount', type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(name: 'currency', type: 'string', length: 3)]
    private string $currency = self::DEFAULT_CURRENCY;

    #[ORM\Column(name: 'reference', type: 'string', length: 128, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'notes', type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'recorded_at', type: 'datetime_immutable')]
    private DateTimeImmutable $recordedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recorded_by_admin_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $recordedByAdmin = null;

    /**
     * Construct a manual refund record. Called by admin endpoint
     * (POST /v3/admin/returns/{id}/refund) at the moment ops marks
     * the return as financially complete.
     *
     * @throws \InvalidArgumentException for unknown method, invalid
     *         money string, or invalid currency code.
     */
    public function __construct(
        OrderReturnRequest $returnRequest,
        string $method,
        string $amount,
        ?string $reference = null,
        ?string $notes = null,
        ?User $recordedByAdmin = null,
        string $currency = self::DEFAULT_CURRENCY,
    ) {
        if (!in_array($method, self::ALL_METHODS, true)) {
            throw new \InvalidArgumentException(
                "Unknown refund method '{$method}'. "
                . 'Must be one of: ' . implode(', ', self::ALL_METHODS),
            );
        }
        self::assertPositiveMoney($amount);
        self::assertValidCurrency($currency);

        $this->returnRequest = $returnRequest;
        $this->method = $method;
        $this->amount = $amount;
        $this->currency = strtoupper(trim($currency));
        $this->reference = $reference !== null ? trim($reference) : null;
        if ($this->reference === '') {
            $this->reference = null;
        }
        $this->notes = $notes !== null ? trim($notes) : null;
        if ($this->notes === '') {
            $this->notes = null;
        }
        $this->recordedByAdmin = $recordedByAdmin;
        $this->recordedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------

    public function getId(): ?int { return $this->id; }
    public function getReturnRequest(): OrderReturnRequest { return $this->returnRequest; }
    public function getMethod(): string { return $this->method; }
    public function getAmount(): string { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getReference(): ?string { return $this->reference; }
    public function getNotes(): ?string { return $this->notes; }
    public function getRecordedAt(): DateTimeImmutable { return $this->recordedAt; }
    public function getRecordedByAdmin(): ?User { return $this->recordedByAdmin; }

    // -----------------------------------------------------------------
    // Money validation
    // -----------------------------------------------------------------

    private static function assertPositiveMoney(string $amount): void
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException(
                "Refund amount must be a DECIMAL(10,2) string, got '{$amount}'."
            );
        }
        if (bccomp($amount, '0', 2) <= 0) {
            throw new \InvalidArgumentException(
                "Refund amount must be > 0, got '{$amount}'."
            );
        }
    }

    private static function assertValidCurrency(string $currency): void
    {
        $trimmed = trim($currency);
        if (!preg_match('/^[A-Za-z]{3}$/', $trimmed)) {
            throw new \InvalidArgumentException(
                "Currency must be a 3-letter ISO 4217 code, got '{$currency}'."
            );
        }
    }
}
