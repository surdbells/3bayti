<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\GiftCard;

use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A 3bayti gift card, a closed-loop stored-value instrument.
 *
 * Lifecycle
 * ---------
 *   pending_payment , order created, gateway not yet confirmed
 *   active          , payment confirmed; balance available to spend
 *   partially_used  , at least one debit applied; balance > 0
 *   exhausted       , balance reached exactly 0.00
 *   expired         , expiry_at passed with remaining balance
 *   voided          , admin-cancelled (refund processed externally)
 *
 * Partial payment model
 * ---------------------
 * At checkout the customer may apply a gift card. The server:
 *   1. Debits min(card.balance, order.total) from the card
 *   2. The remainder (order.total - gift_card_amount) is charged
 *      to the payment gateway (Noon)
 *   3. If gateway charge is 0.00, the order completes with no
 *      gateway redirect (gift card covers it entirely)
 *
 * Code format
 * -----------
 * 16 uppercase hex characters grouped in 4×4:  XXXX-XXXX-XXXX-XXXX
 * Stored without hyphens (16 chars) in the DB; formatted on read.
 * Cryptographically random via random_bytes(8).
 *
 * Themes (6 designs)
 * ------------------
 *   birthday       , gold confetti, warm amber
 *   wedding        , ivory + rose gold, florals
 *   eid            , deep green, crescent motifs, Arabic calligraphy
 *   mother         , blush pink, soft botanicals
 *   graduation     , navy + gold, starburst
 *   luxury         , black + platinum, minimal editorial
 *
 * The 'luxury' theme is the only one that supports a recipient photo
 * (recipient_photo_url). This is a curated experience, upload via
 * POST /v3/upload?context=gift_card_photo before creating the card.
 *
 * Denomination constraints
 * ------------------------
 * Minimum AED 100, maximum AED 10,000. Enforced in the constructor.
 * Pre-set options: 100, 200, 500, 1000, 2000, 5000, 10000.
 * Custom amounts within the range are also accepted.
 *
 * Expiry
 * ------
 * Cards expire 12 months from activation (not purchase). An expired
 * card with remaining balance is voided by a nightly job (future M3.5).
 */
#[ORM\Entity(repositoryClass: GiftCardRepository::class)]
#[ORM\Table(name: 'gift_cards')]
#[ORM\HasLifecycleCallbacks]
class GiftCard
{
    // ── Status constants ──────────────────────────────────────────────
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_ACTIVE          = 'active';
    public const STATUS_PARTIALLY_USED  = 'partially_used';
    public const STATUS_EXHAUSTED       = 'exhausted';
    public const STATUS_EXPIRED         = 'expired';
    public const STATUS_VOIDED          = 'voided';

    // ── Theme constants ───────────────────────────────────────────────
    public const THEME_BIRTHDAY    = 'birthday';
    public const THEME_WEDDING     = 'wedding';
    public const THEME_EID         = 'eid';
    public const THEME_MOTHER      = 'mother';
    public const THEME_GRADUATION  = 'graduation';
    public const THEME_LUXURY      = 'luxury';

    public const THEMES = [
        self::THEME_BIRTHDAY,
        self::THEME_WEDDING,
        self::THEME_EID,
        self::THEME_MOTHER,
        self::THEME_GRADUATION,
        self::THEME_LUXURY,
    ];

    // ── Denomination constraints ──────────────────────────────────────
    public const MIN_DENOMINATION = '100.00';
    public const MAX_DENOMINATION = '10000.00';

    /** Pre-set denomination options (AED). */
    public const PRESET_DENOMINATIONS = ['100.00', '200.00', '500.00', '1000.00', '2000.00', '5000.00', '10000.00'];

    // ── Identity ──────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    /**
     * 16 uppercase hex chars without hyphens, stored raw, formatted
     * as XXXX-XXXX-XXXX-XXXX on read. Unique at DB level.
     */
    #[ORM\Column(name: 'code', type: 'string', length: 16, unique: true)]
    private string $code;

    // ── Monetary ──────────────────────────────────────────────────────

    #[ORM\Column(name: 'denomination', type: 'decimal', precision: 10, scale: 2)]
    private string $denomination;

    /** Current spendable balance. Starts equal to denomination; reduced on each debit. */
    #[ORM\Column(name: 'balance', type: 'decimal', precision: 10, scale: 2)]
    private string $balance;

    #[ORM\Column(name: 'currency', type: 'string', length: 3, options: ['default' => 'AED'])]
    private string $currency = 'AED';

    // ── Lifecycle ─────────────────────────────────────────────────────

    #[ORM\Column(name: 'status', type: 'string', length: 20, options: ['default' => self::STATUS_PENDING_PAYMENT])]
    private string $status = self::STATUS_PENDING_PAYMENT;

    #[ORM\Column(name: 'theme', type: 'string', length: 20)]
    private string $theme;

    // ── Ownership ─────────────────────────────────────────────────────

    /** Customer who purchased the card. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'buyer_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $buyerUser;

    /** Customer who redeemed/received the card. Set when recipient redeems. Nullable. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recipient_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $recipientUser = null;

    // ── Personalisation ───────────────────────────────────────────────

    #[ORM\Column(name: 'recipient_name', type: 'string', length: 100, nullable: true)]
    private ?string $recipientName = null;

    /** Personalised message from buyer (max 150 chars). */
    #[ORM\Column(name: 'recipient_message', type: 'string', length: 150, nullable: true)]
    private ?string $recipientMessage = null;

    /**
     * Recipient photo URL, only meaningful for theme='luxury'.
     * Uploaded via POST /v3/upload?context=gift_card_photo before card
     * creation; validated at creation time.
     */
    #[ORM\Column(name: 'recipient_photo_url', type: 'string', length: 500, nullable: true)]
    private ?string $recipientPhotoUrl = null;

    // ── Recipient delivery (optional) ─────────────────────────────────
    //
    // When recipient_email / recipient_phone is set, the card is
    // auto-delivered on activation (or by the scheduled-delivery cron).
    // When null, the buyer shares the formatted code manually, the
    // original, fully backward-compatible behaviour.

    /** Optional recipient email for auto-delivery. Validated at creation. */
    #[ORM\Column(name: 'recipient_email', type: 'string', length: 255, nullable: true)]
    private ?string $recipientEmail = null;

    /** Optional recipient phone (E.164-ish, stored as given) for SMS delivery. */
    #[ORM\Column(name: 'recipient_phone', type: 'string', length: 32, nullable: true)]
    private ?string $recipientPhone = null;

    /**
     * When the delivery email was sent. Null = not yet delivered.
     * Non-null is the idempotency guard (never re-send the email).
     */
    #[ORM\Column(name: 'email_delivered_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $emailDeliveredAt = null;

    /**
     * When the delivery SMS was sent. Null = not yet delivered.
     * Non-null is the idempotency guard (never re-send the SMS).
     */
    #[ORM\Column(name: 'sms_delivered_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $smsDeliveredAt = null;

    /**
     * Optional scheduled delivery date. When set, the card is NOT
     * immediately sent to the recipient, it's queued and delivered
     * on this date. Null = send immediately on activation.
     */
    #[ORM\Column(name: 'scheduled_delivery_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $scheduledDeliveryAt = null;

    // ── Timestamps ────────────────────────────────────────────────────

    #[ORM\Column(name: 'activated_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $activatedAt = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * The purchase order, links this card to the Noon payment that
     * funded it. Used to tie the activation webhook back to the card.
     * Nullable because we create the card before the payment completes.
     */
    #[ORM\Column(name: 'purchase_order_reference', type: 'string', length: 32, nullable: true, unique: true)]
    private ?string $purchaseOrderReference = null;

    /** @var Collection<int, GiftCardTransaction> */
    #[ORM\OneToMany(targetEntity: GiftCardTransaction::class, mappedBy: 'giftCard', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $transactions;

    // ── Constructor ───────────────────────────────────────────────────

    public function __construct(
        User $buyerUser,
        string $denomination,
        string $theme,
        ?string $recipientName = null,
        ?string $recipientMessage = null,
        ?string $recipientPhotoUrl = null,
        ?DateTimeImmutable $scheduledDeliveryAt = null,
        // Trailing params (appended so existing callers/tests are
        // unaffected). Both optional; null = no auto-delivery.
        ?string $recipientEmail = null,
        ?string $recipientPhone = null,
    ) {
        $this->assertValidDenomination($denomination);
        $this->assertValidTheme($theme);

        if ($recipientPhotoUrl !== null && $theme !== self::THEME_LUXURY) {
            throw new \InvalidArgumentException(
                "recipient_photo_url is only supported for the 'luxury' theme."
            );
        }

        if ($recipientEmail !== null) {
            $this->assertValidEmail($recipientEmail);
        }
        if ($recipientPhone !== null) {
            $this->assertValidPhone($recipientPhone);
        }

        $this->code         = strtoupper(bin2hex(random_bytes(8)));
        $this->denomination = $denomination;
        $this->balance      = $denomination;
        $this->theme        = $theme;
        $this->buyerUser    = $buyerUser;

        $this->recipientName       = $recipientName;
        $this->recipientMessage    = $recipientMessage !== null ? mb_substr($recipientMessage, 0, 150) : null;
        $this->recipientPhotoUrl   = $recipientPhotoUrl;
        $this->scheduledDeliveryAt = $scheduledDeliveryAt;
        $this->recipientEmail      = $recipientEmail;
        $this->recipientPhone      = $recipientPhone;

        $this->transactions = new ArrayCollection();
        $this->createdAt    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt    = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Manually issue / comp a gift card from the admin surface.
     *
     * Unlike the storefront purchase path (which creates a
     * pending_payment card that activates on payment), an admin-issued
     * card is born ACTIVE, no payment is collected. It is funded by an
     * initial CREDIT ledger row (so the ledger always opens with the
     * issued value) and carries the standard 12-month expiry from now.
     *
     * The unique 16-char code is generated by the constructor, exactly
     * like the storefront purchase path.
     *
     * @param User    $buyerUser      the issuing admin (recorded as buyer)
     * @param string  $value          DECIMAL(10,2) string within denom bounds
     * @param ?int    $actorUserId    admin user id for the ledger row
     */
    public static function issueByAdmin(
        User $buyerUser,
        string $value,
        ?string $recipientName = null,
        ?string $recipientEmail = null,
        ?string $note = null,
        ?int $actorUserId = null,
    ): self {
        $card = new self(
            buyerUser: $buyerUser,
            denomination: $value,
            theme: self::THEME_LUXURY,
            recipientName: $recipientName,
            recipientMessage: $note,
            recipientPhotoUrl: null,
            scheduledDeliveryAt: null,
            recipientEmail: $recipientEmail,
            recipientPhone: null,
        );

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $card->status      = self::STATUS_ACTIVE;
        $card->activatedAt = $now;
        $card->expiresAt   = $now->modify('+12 months');
        $card->updatedAt   = $now;

        // Open the ledger with the issued value as a CREDIT. The
        // constructor already set balance = denomination, so the
        // balance_after on this opening row equals the value.
        $tx = new GiftCardTransaction(
            giftCard: $card,
            type: GiftCardTransaction::TYPE_CREDIT,
            amount: $value,
            balanceAfter: $card->balance,
            orderReference: null,
            orderId: null,
            reason: $note ?? 'Admin-issued gift card',
            actorUserId: $actorUserId,
        );
        $card->transactions->add($tx);

        return $card;
    }

    // ── Business methods ──────────────────────────────────────────────

    /**
     * Activate the card after payment is confirmed.
     * Sets balance, expiry (12 months), and status = active.
     */
    public function activate(string $purchaseOrderReference): void
    {
        if ($this->status !== self::STATUS_PENDING_PAYMENT) {
            throw new \LogicException("Cannot activate a gift card with status '{$this->status}'.");
        }

        $this->status                 = self::STATUS_ACTIVE;
        $this->purchaseOrderReference = $purchaseOrderReference;
        $this->activatedAt            = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->expiresAt              = $this->activatedAt->modify('+12 months');
        $this->updatedAt              = $this->activatedAt;
    }

    /**
     * Debit an amount from this card's balance (e.g. at checkout).
     * Returns the GiftCardTransaction created.
     *
     * @throws \InvalidArgumentException  for malformed amount
     * @throws \LogicException            if card is not spendable
     * @throws \DomainException           if amount exceeds balance
     */
    public function debit(
        string $amount,
        string $orderReference,
        ?int $orderId = null,
    ): GiftCardTransaction {
        $this->assertSpendable();
        $this->assertValidMoney($amount, 'amount');

        $amountDec  = bcadd($amount, '0.00', 2);
        $balanceDec = bcadd($this->balance, '0.00', 2);

        if (bccomp($amountDec, '0.01', 2) < 0) {
            throw new \InvalidArgumentException('Debit amount must be at least 0.01.');
        }
        if (bccomp($amountDec, $balanceDec, 2) > 0) {
            throw new \DomainException(
                "Debit amount {$amount} exceeds available balance {$this->balance}."
            );
        }

        $newBalance = bcsub($balanceDec, $amountDec, 2);
        $this->balance = $newBalance;

        $this->status = bccomp($newBalance, '0.00', 2) === 0
            ? self::STATUS_EXHAUSTED
            : self::STATUS_PARTIALLY_USED;

        $tx = new GiftCardTransaction(
            giftCard: $this,
            type: GiftCardTransaction::TYPE_DEBIT,
            amount: $amount,
            balanceAfter: $this->balance,
            orderReference: $orderReference,
            orderId: $orderId,
        );
        $this->transactions->add($tx);

        return $tx;
    }

    /**
     * Reverse a debit (e.g. order cancelled). Restores the balance
     * and creates a credit transaction.
     */
    public function credit(
        string $amount,
        string $orderReference,
        ?int $orderId = null,
    ): GiftCardTransaction {
        $this->assertValidMoney($amount, 'amount');

        $newBalance    = bcadd($this->balance, $amount, 2);
        $denomDec      = bcadd($this->denomination, '0.00', 2);

        // Never exceed denomination on credit (guards double-credit bugs)
        if (bccomp($newBalance, $denomDec, 2) > 0) {
            throw new \DomainException(
                "Credit would push balance {$newBalance} above denomination {$this->denomination}."
            );
        }

        $this->balance = $newBalance;
        $this->status  = in_array($this->status, [self::STATUS_EXHAUSTED, self::STATUS_EXPIRED], true)
            ? self::STATUS_ACTIVE
            : self::STATUS_PARTIALLY_USED;

        $tx = new GiftCardTransaction(
            giftCard: $this,
            type: GiftCardTransaction::TYPE_CREDIT,
            amount: $amount,
            balanceAfter: $this->balance,
            orderReference: $orderReference,
            orderId: $orderId,
        );
        $this->transactions->add($tx);

        return $tx;
    }

    /** Admin-initiated void. */
    public function void(): void
    {
        $this->status  = self::STATUS_VOIDED;
        $this->balance = '0.00';
    }

    // ── Admin manual adjustments (M5 admin gift-card surface) ──────────
    //
    // These mirror debit()/credit() but are admin-driven (no order
    // reference; carry a reason + actor) and do NOT enforce the
    // customer-facing guards: an admin credit is NOT capped at the
    // original denomination (a comp top-up may legitimately raise the
    // balance), and an admin debit does NOT require the card to be
    // "spendable", but it still may NEVER push the balance below 0.

    /**
     * Admin credit (top-up / comp). Increases balance + writes a CREDIT
     * ledger row. Reactivates an exhausted/expired card. Refuses on a
     * voided card (a voided card is terminal).
     *
     * @throws \LogicException  if the card is voided
     */
    public function adminCredit(string $amount, string $reason, ?int $actorUserId = null): GiftCardTransaction
    {
        $this->assertNotVoided();
        $this->assertValidMoney($amount, 'amount');
        if (bccomp($amount, '0.01', 2) < 0) {
            throw new \InvalidArgumentException('Adjustment amount must be at least 0.01.');
        }

        $this->balance = bcadd($this->balance, $amount, 2);
        $this->status  = in_array($this->status, [self::STATUS_EXHAUSTED, self::STATUS_EXPIRED], true)
            ? self::STATUS_ACTIVE
            : ($this->status === self::STATUS_PENDING_PAYMENT ? $this->status : self::STATUS_PARTIALLY_USED);
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $tx = new GiftCardTransaction(
            giftCard: $this,
            type: GiftCardTransaction::TYPE_CREDIT,
            amount: $amount,
            balanceAfter: $this->balance,
            orderReference: null,
            orderId: null,
            reason: $reason,
            actorUserId: $actorUserId,
        );
        $this->transactions->add($tx);
        return $tx;
    }

    /**
     * Admin debit (manual deduction / clawback). Decreases balance +
     * writes a DEBIT ledger row. Rejects an overdraw (amount may not
     * exceed the current balance, balance never goes below 0).
     *
     * @throws \LogicException  if the card is voided
     * @throws \DomainException if the amount exceeds the balance
     */
    public function adminDebit(string $amount, string $reason, ?int $actorUserId = null): GiftCardTransaction
    {
        $this->assertNotVoided();
        $this->assertValidMoney($amount, 'amount');
        if (bccomp($amount, '0.01', 2) < 0) {
            throw new \InvalidArgumentException('Adjustment amount must be at least 0.01.');
        }
        if (bccomp($amount, $this->balance, 2) > 0) {
            throw new \DomainException(
                "Debit amount {$amount} exceeds available balance {$this->balance}."
            );
        }

        $this->balance = bcsub($this->balance, $amount, 2);
        $this->status  = bccomp($this->balance, '0.00', 2) === 0
            ? self::STATUS_EXHAUSTED
            : self::STATUS_PARTIALLY_USED;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $tx = new GiftCardTransaction(
            giftCard: $this,
            type: GiftCardTransaction::TYPE_DEBIT,
            amount: $amount,
            balanceAfter: $this->balance,
            orderReference: null,
            orderId: null,
            reason: $reason,
            actorUserId: $actorUserId,
        );
        $this->transactions->add($tx);
        return $tx;
    }

    /**
     * Admin void with audit trail. Zeroes the spendable balance, flips
     * status to voided, and writes a terminal VOID ledger row carrying
     * the reason + actor. Idempotent: calling void on an already-voided
     * card is a no-op (returns null), the caller surfaces a clear
     * "already voided" response rather than double-recording.
     */
    public function voidWithLedger(string $reason, ?int $actorUserId = null): ?GiftCardTransaction
    {
        if ($this->status === self::STATUS_VOIDED) {
            return null; // already voided, idempotent no-op
        }

        $this->status   = self::STATUS_VOIDED;
        $this->balance  = '0.00';
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $tx = new GiftCardTransaction(
            giftCard: $this,
            type: GiftCardTransaction::TYPE_VOID,
            amount: '0.00',
            balanceAfter: '0.00',
            orderReference: null,
            orderId: null,
            reason: $reason,
            actorUserId: $actorUserId,
        );
        $this->transactions->add($tx);
        return $tx;
    }

    private function assertNotVoided(): void
    {
        if ($this->status === self::STATUS_VOIDED) {
            throw new \LogicException('Cannot adjust a voided gift card.');
        }
    }

    /**
     * Link a recipient user account when they redeem the card.
     * Idempotent, safe to call again if same user.
     */
    public function assignRecipient(User $user): void
    {
        if ($this->recipientUser !== null && $this->recipientUser->getId() !== $user->getId()) {
            throw new \LogicException('Gift card is already assigned to a different recipient.');
        }
        $this->recipientUser = $user;
    }

    // ── Delivery ──────────────────────────────────────────────────────
    //
    // Each channel (email / SMS) is tracked independently for
    // idempotency. needs*Delivery() returns true only when a recipient
    // contact exists for that channel AND it has not yet been delivered.
    // The GiftCardDeliveryService consults these before sending and
    // calls mark*Delivered() after a successful send.

    /** True when there is a recipient email that has not yet been delivered. */
    public function needsEmailDelivery(): bool
    {
        return $this->recipientEmail !== null && $this->emailDeliveredAt === null;
    }

    /** True when there is a recipient phone that has not yet been delivered. */
    public function needsSmsDelivery(): bool
    {
        return $this->recipientPhone !== null && $this->smsDeliveredAt === null;
    }

    public function markEmailDelivered(DateTimeImmutable $at): void
    {
        $this->emailDeliveredAt = $at;
        $this->updatedAt = $at;
    }

    public function markSmsDelivered(DateTimeImmutable $at): void
    {
        $this->smsDeliveredAt = $at;
        $this->updatedAt = $at;
    }

    // ── Convenience queries ───────────────────────────────────────────

    public function isSpendable(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE && $this->status !== self::STATUS_PARTIALLY_USED) {
            return false;
        }
        if ($this->expiresAt !== null && $this->expiresAt <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            return false;
        }
        return bccomp($this->balance, '0.00', 2) > 0;
    }

    /**
     * Was this card bought FOR SOMEONE ELSE?
     *
     * True as soon as the buyer designated a recipient in any form, a name,
     * an email or a phone. Those cards are the recipient's to spend, so they
     * must NOT sit in the buyer's spendable wallet just because the buyer
     * paid for them. A card bought with no recipient details at all is a
     * self-purchase (top-up) and is immediately spendable by the buyer.
     */
    public function isGiftForSomeoneElse(): bool
    {
        return $this->recipientName !== null
            || $this->recipientEmail !== null
            || $this->recipientPhone !== null;
    }

    /**
     * May $user spend this card (wallet or code entry)?
     *
     * Rules:
     *   - The assigned recipient may always spend it. Claiming a card via
     *     POST /v3/gift-cards/redeem sets recipient_user, so this is also the
     *     escape hatch for a buyer who decides to keep a card they had bought
     *     as a gift.
     *   - The buyer may spend it only while it is NOT designated for someone
     *     else and nobody else has claimed it.
     *
     * Spendability of the card itself (status/expiry/balance) is a separate
     * concern, see isSpendable().
     */
    public function isSpendableBy(User $user): bool
    {
        $uid = $user->getId();
        if ($this->recipientUser !== null) {
            return $this->recipientUser->getId() === $uid;
        }
        return $this->buyerUser->getId() === $uid && !$this->isGiftForSomeoneElse();
    }

    /** How much of this card can actually be applied to a given order total. */
    public function applyableAmount(string $orderTotal): string
    {
        if (!$this->isSpendable()) return '0.00';
        $balanceDec = bcadd($this->balance, '0.00', 2);
        $totalDec   = bcadd($orderTotal, '0.00', 2);
        return bccomp($balanceDec, $totalDec, 2) <= 0 ? $balanceDec : $totalDec;
    }

    /** Format code as XXXX-XXXX-XXXX-XXXX */
    public function formattedCode(): string
    {
        return implode('-', str_split($this->code, 4));
    }

    // ── Accessors ─────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getDenomination(): string { return $this->denomination; }
    public function getBalance(): string { return $this->balance; }
    public function getCurrency(): string { return $this->currency; }
    public function getStatus(): string { return $this->status; }
    public function getTheme(): string { return $this->theme; }
    public function getBuyerUser(): User { return $this->buyerUser; }
    public function getRecipientUser(): ?User { return $this->recipientUser; }
    public function getRecipientName(): ?string { return $this->recipientName; }
    public function getRecipientMessage(): ?string { return $this->recipientMessage; }
    public function getRecipientPhotoUrl(): ?string { return $this->recipientPhotoUrl; }
    public function getRecipientEmail(): ?string { return $this->recipientEmail; }
    public function getRecipientPhone(): ?string { return $this->recipientPhone; }
    public function getEmailDeliveredAt(): ?DateTimeImmutable { return $this->emailDeliveredAt; }
    public function getSmsDeliveredAt(): ?DateTimeImmutable { return $this->smsDeliveredAt; }
    public function getScheduledDeliveryAt(): ?DateTimeImmutable { return $this->scheduledDeliveryAt; }
    public function getActivatedAt(): ?DateTimeImmutable { return $this->activatedAt; }
    public function getExpiresAt(): ?DateTimeImmutable { return $this->expiresAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getPurchaseOrderReference(): ?string { return $this->purchaseOrderReference; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * Store the order reference before payment so the webhook can find
     * this card by reference and activate it. Called by
     * initiateGiftCardPurchase() after the synthetic order is created.
     * Status stays pending_payment until Noon confirms payment.
     */
    public function setPurchaseOrderReferenceForPayment(string $orderReference): void
    {
        if ($this->status !== self::STATUS_PENDING_PAYMENT) {
            throw new \LogicException(
                "Cannot set purchase order reference on a card with status '{$this->status}'."
            );
        }
        $this->purchaseOrderReference = $orderReference;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** @return Collection<int, GiftCardTransaction> */
    public function getTransactions(): Collection { return $this->transactions; }

    // ── Private helpers ───────────────────────────────────────────────

    private function assertSpendable(): void
    {
        if (!$this->isSpendable()) {
            throw new \LogicException(
                "Gift card [{$this->formattedCode()}] is not spendable (status={$this->status}, balance={$this->balance})."
            );
        }
    }

    private function assertValidDenomination(string $d): void
    {
        $this->assertValidMoney($d, 'denomination');
        if (bccomp($d, self::MIN_DENOMINATION, 2) < 0) {
            throw new \InvalidArgumentException(
                "denomination must be at least " . self::MIN_DENOMINATION . " AED, got '{$d}'."
            );
        }
        if (bccomp($d, self::MAX_DENOMINATION, 2) > 0) {
            throw new \InvalidArgumentException(
                "denomination cannot exceed " . self::MAX_DENOMINATION . " AED, got '{$d}'."
            );
        }
    }

    private function assertValidTheme(string $t): void
    {
        if (!in_array($t, self::THEMES, true)) {
            throw new \InvalidArgumentException(
                "Invalid theme '{$t}'. Valid themes: " . implode(', ', self::THEMES) . '.'
            );
        }
    }

    private function assertValidMoney(string $v, string $field): void
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $v)) {
            throw new \InvalidArgumentException(
                "{$field} must be a non-negative DECIMAL(10,2) string, got '{$v}'."
            );
        }
    }

    private function assertValidEmail(string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("recipient_email '{$email}' is not a valid email address.");
        }
        if (mb_strlen($email) > 255) {
            throw new \InvalidArgumentException('recipient_email must be at most 255 characters.');
        }
    }

    /**
     * Light E.164-ish validation: an optional leading '+' followed by
     * 7–15 digits. We store the value exactly as given (no
     * normalisation), the SMS sender strips the country code itself.
     */
    private function assertValidPhone(string $phone): void
    {
        if (!preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
            throw new \InvalidArgumentException(
                "recipient_phone '{$phone}' is not a valid phone number (expected E.164-ish, 7-15 digits)."
            );
        }
    }
}
