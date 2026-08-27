<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Promo;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * An append-only record of a successful promo code redemption.
 *
 * Lifecycle
 * ---------
 * Exactly one row is created when InitiateCheckoutController commits
 * an order with a valid promo_code. The row is written inside the same
 * EM transaction that creates the Order, atomicity guarantees the
 * order's discount and its redemption attribution land together or
 * not at all.
 *
 * Immutability
 * ------------
 * Once created, the redemption row is never updated by the application.
 * The snapshot columns (code_snapshot, discount_type_snapshot,
 * discount_value_snapshot) capture the promo's state at redemption
 * time so subsequent admin edits to the catalog don't rewrite history.
 *
 * No reverse-on-cancel in v1
 * ---------------------------
 * If an order is later canceled (M3.1.7-F flow), the redemption row
 * stays in place as a historical record. This means a one-time-use
 * code is "used up" even if the user never actually transacted with
 * it. This is a documented v1 limitation, admin can manually delete
 * the redemption via the (future) admin endpoint, or the cancel flow
 * can be extended in a future X-phase to auto-revoke. Out of v1 scope.
 *
 * For limit-counting (usage_limit_global, usage_limit_per_user), the
 * resolver in -B counts via PromoRedemptionRepository::countByPromoCodeIdEffective
 * which joins to orders.status to exclude orders in cancelled/failed
 * states. The row remains in the table; the count just excludes it.
 *
 * One-promo-per-order
 * --------------------
 * Q-ConflictPolicy = A locked: at most one redemption per order.
 * Enforced at the DB level via UNIQUE constraint on order_id; the
 * application layer additionally rejects a second different code on
 * the same cart at quote time so the client never gets to checkout
 * with a conflicting state.
 */
#[ORM\Entity(repositoryClass: PromoRedemptionRepository::class)]
#[ORM\Table(name: 'promo_redemptions')]
class PromoRedemption
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    /**
     * The promo code this redemption is against. FK ON DELETE RESTRICT
     *, admin cannot hard-delete a promo code that has at least one
     * redemption (must soft-delete via setActive(false) instead).
     */
    #[ORM\ManyToOne(targetEntity: PromoCode::class)]
    #[ORM\JoinColumn(name: 'promo_code_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private PromoCode $promoCode;

    /**
     * The customer who redeemed. FK ON DELETE RESTRICT, preserves
     * the redemption history; user deletion is not a flow we support
     * in v3 anyway (Q7 anonymity model).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    /**
     * The order this redemption belongs to. FK ON DELETE CASCADE, if
     * an order is hard-deleted (very rare), the redemption goes with
     * it (the inverse is on Order.promoRedemption with ON DELETE SET
     * NULL which leaves the order's discount line intact while
     * decoupling). Note: UNIQUE constraint on this column at DB level
     * enforces one-redemption-per-order.
     */
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE', unique: true)]
    private Order $order;

    /**
     * The computed discount amount in `currency` (snapshotted from the
     * order's currency at redemption time). This is the actual money
     * value that landed on Order.discount.
     */
    #[ORM\Column(name: 'discount_amount', type: 'decimal', precision: 10, scale: 2)]
    private string $discountAmount;

    /**
     * Code text as it was at redemption. Snapshotted so an admin
     * renaming WELCOME10 → WELCOME10_LEGACY later doesn't rewrite the
     * historical record.
     */
    #[ORM\Column(name: 'code_snapshot', type: 'string', length: PromoCode::CODE_MAX_LENGTH)]
    private string $codeSnapshot;

    /**
     * discount_type as it was at redemption. Same rationale as
     * code_snapshot, admin changes don't rewrite history.
     */
    #[ORM\Column(name: 'discount_type_snapshot', type: 'string', length: 16)]
    private string $discountTypeSnapshot;

    /**
     * discount_value as it was at redemption.
     */
    #[ORM\Column(name: 'discount_value_snapshot', type: 'decimal', precision: 10, scale: 2)]
    private string $discountValueSnapshot;

    /**
     * Timestamp of the redemption. Set at construction; immutable.
     */
    #[ORM\Column(name: 'redeemed_at', type: 'datetime_immutable')]
    private DateTimeImmutable $redeemedAt;

    /**
     * Construct a redemption record. Called only by
     * PromoCodeResolverService::recordRedemption inside the checkout
     * EM transaction, never by controllers directly.
     *
     * @throws \InvalidArgumentException for malformed money strings
     */
    public function __construct(
        PromoCode $promoCode,
        User $user,
        Order $order,
        string $discountAmount,
    ) {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $discountAmount)) {
            throw new \InvalidArgumentException(
                "PromoRedemption.discount_amount must be a non-negative DECIMAL(10,2) string, got '{$discountAmount}'",
            );
        }

        $this->promoCode = $promoCode;
        $this->user = $user;
        $this->order = $order;
        $this->discountAmount = $discountAmount;

        // Snapshot the catalog state at redemption time.
        $this->codeSnapshot = $promoCode->getCode();
        $this->discountTypeSnapshot = $promoCode->getDiscountType();
        $this->discountValueSnapshot = $promoCode->getDiscountValue();

        $this->redeemedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    // -----------------------------------------------------------------
    // Accessors, read-only by design (immutability)
    // -----------------------------------------------------------------

    public function getId(): ?int { return $this->id; }
    public function getPromoCode(): PromoCode { return $this->promoCode; }
    public function getUser(): User { return $this->user; }
    public function getOrder(): Order { return $this->order; }
    public function getDiscountAmount(): string { return $this->discountAmount; }
    public function getCodeSnapshot(): string { return $this->codeSnapshot; }
    public function getDiscountTypeSnapshot(): string { return $this->discountTypeSnapshot; }
    public function getDiscountValueSnapshot(): string { return $this->discountValueSnapshot; }
    public function getRedeemedAt(): DateTimeImmutable { return $this->redeemedAt; }
}
