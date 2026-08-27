<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Common\Timestamps;
use Bayti\Api\Domain\Promo\PromoRedemption;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A finalised order, created at checkout-initiate (with status
 * 'pending_payment') and transitioned by the payment-finalize flow.
 *
 * Status lifecycle
 * =================
 *   pending_payment ─┬─→ paid ─→ fulfilling ─→ shipped ─→ delivered
 *                    │                                       │
 *                    ├─→ failed (Noon SALE failed)           │
 *                    │                                       │
 *                    ├─→ cancelled (customer cancel; M3.1.7) │
 *                    │                                       │
 *                    └────────────────── refunded ←──────────┘
 *
 * Terminal states: delivered, cancelled, refunded, failed.
 * Once a state is terminal, the order's status is immutable; the
 * finalize webhook handler enforces this with a guard.
 *
 * `paid_at` is set when the order transitions to 'paid' (used for
 * "you have N days to return this" calculations downstream).
 *
 * order_reference
 * ================
 * Our v3-level idempotency key, sent to Noon as merchant_reference.
 * Format: '3B-<base36-timestamp>-<random-6>' giving ~32 chars max.
 * UNIQUE at the DB layer, defensive against the case where Noon's
 * merchant-side uniqueness is not enabled (per their docs that
 * setting requires manual support enablement).
 *
 * Monetary fields
 * ================
 * subtotal      , sum of (item.unit_price * item.quantity)
 * delivery_fee  , fixed at checkout, can vary by vendor/region
 * discount      , any promo/loyalty discount applied
 * total         , subtotal + delivery_fee - discount (or zero if negative)
 *
 * All DECIMAL(10, 2). Use bcmath / decimal arithmetic; never floats.
 *
 * Address relationship (1:N via type)
 * ====================================
 * billing + shipping addresses are stored as TWO rows in
 * order_addresses with type enum. Even when they match, both rows
 * exist (UNIQUE (order_id, type) enforces "exactly one of each").
 * Simplifies vendor-facing display.
 */
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\HasLifecycleCallbacks]
class Order
{
    use Timestamps;

    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_FULFILLING = 'fulfilling';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_FAILED = 'failed';

    /** @var list<string> Terminal states that cannot transition. */
    private const TERMINAL_STATES = [
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
        self::STATUS_FAILED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'legacy_order_id', type: 'bigint', nullable: true, unique: true)]
    private ?int $legacyOrderId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    /**
     * The cart this order was created from. Kept so the cart is converted only
     * when the order is PAID (not at checkout initiate, otherwise cancelling
     * out of the payment page would empty the cart). NULL for orders with no
     * cart, e.g. gift-card purchases, so nothing is converted for those.
     */
    #[ORM\ManyToOne(targetEntity: Cart::class)]
    #[ORM\JoinColumn(name: 'cart_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Cart $cart = null;

    #[ORM\Column(name: 'order_reference', type: 'string', length: 32, unique: true)]
    private string $orderReference;

    #[ORM\Column(type: 'string', length: 32)]
    private string $status = self::STATUS_PENDING_PAYMENT;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $subtotal;

    #[ORM\Column(name: 'delivery_fee', type: 'decimal', precision: 10, scale: 2)]
    private string $deliveryFee = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $discount = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $total;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency = 'AED';

    #[ORM\Column(name: 'paid_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $paidAt = null;

    /**
     * When the order FIRST reached the DELIVERED status. Set once by
     * recomputeStatusFromItems on the pending→delivered transition and
     * never overwritten (a re-delivery transition keeps the original
     * timestamp). Null for orders that have never been delivered.
     *
     * Consumed by the post-delivery review-prompt follow-up: the
     * marketing push fires N hours/days after delivered_at.
     */
    #[ORM\Column(name: 'delivered_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $deliveredAt = null;

    /**
     * @var Collection<int, OrderItem>
     */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    /**
     * @var Collection<int, OrderAddress>
     */
    #[ORM\OneToMany(targetEntity: OrderAddress::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $addresses;

    /**
     * The promo redemption applied to this order, if any (M3.2.X.8).
     *
     * Nullable: orders without a promo code applied have NULL here.
     * The DB-level UNIQUE constraint on promo_redemptions.order_id
     * makes this effectively a 0..1 → 1 relationship from the
     * PromoRedemption side; from Order's perspective it's a simple
     * nullable association.
     *
     * The `discount` column on this entity holds the snapshotted money
     * amount; the redemption row holds the WHY (which code, which type,
     * which value). Keeping the discount column independent of the
     * redemption row means deleting a redemption (admin override) does
     * NOT zero out the order's discount, that's a deliberate decision
     * tied to FK ON DELETE SET NULL on this column.
     *
     * Not persisted via cascade: the resolver in M3.2.X.8-D persists
     * the redemption explicitly inside the checkout EM transaction
     * BEFORE assigning it here, so Doctrine sees an already-managed
     * entity when this setter runs.
     */
    #[ORM\ManyToOne(targetEntity: PromoRedemption::class)]
    #[ORM\JoinColumn(name: 'promo_redemption_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PromoRedemption $promoRedemption = null;

    /**
     * Amount paid via gift card (M3.5 gift card feature).
     * '0.00' when no gift card was applied.
     * The gateway is charged (total - gift_card_amount) instead of total.
     * Stored as DECIMAL(10,2) string for bcmath precision.
     */
    #[ORM\Column(name: 'gift_card_amount', type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $giftCardAmount = '0.00';

    /**
     * The gift card code applied to this order (snapshot at checkout).
     * Null when no gift card was applied. Max 16 chars (raw code without hyphens).
     */
    #[ORM\Column(name: 'gift_card_code_snapshot', type: 'string', length: 16, nullable: true)]
    private ?string $giftCardCodeSnapshot = null;

    public function __construct(
        User $user,
        string $orderReference,
        string $subtotal,
        string $deliveryFee = '0.00',
        string $discount = '0.00',
        string $currency = 'AED',
    ) {
        if ($orderReference === '' || strlen($orderReference) > 32) {
            throw new \InvalidArgumentException(
                "Order reference must be 1-32 chars, got '{$orderReference}' (" . strlen($orderReference) . " chars)"
            );
        }
        $this->assertMoneyNonNeg($subtotal, 'subtotal');
        $this->assertMoneyNonNeg($deliveryFee, 'delivery_fee');
        $this->assertMoneyNonNeg($discount, 'discount');

        $this->user = $user;
        $this->orderReference = $orderReference;
        $this->subtotal = $subtotal;
        $this->deliveryFee = $deliveryFee;
        $this->discount = $discount;
        $this->total = $this->computeTotal($subtotal, $deliveryFee, $discount);
        $this->currency = $currency;
        $this->items = new ArrayCollection();
        $this->addresses = new ArrayCollection();
        $this->initTimestamps();
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->touchUpdatedAt();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLegacyOrderId(): ?int
    {
        return $this->legacyOrderId;
    }

    public function setLegacyOrderId(?int $legacyOrderId): void
    {
        $this->legacyOrderId = $legacyOrderId;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCart(): ?Cart
    {
        return $this->cart;
    }

    public function setCart(?Cart $cart): void
    {
        $this->cart = $cart;
    }

    public function getOrderReference(): string
    {
        return $this->orderReference;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getSubtotal(): string
    {
        return $this->subtotal;
    }

    public function getDeliveryFee(): string
    {
        return $this->deliveryFee;
    }

    public function getDiscount(): string
    {
        return $this->discount;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getPaidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getDeliveredAt(): ?DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    /**
     * @return Collection<int, OrderItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /**
     * @return Collection<int, OrderAddress>
     */
    public function getAddresses(): Collection
    {
        return $this->addresses;
    }

    public function getBillingAddress(): ?OrderAddress
    {
        foreach ($this->addresses as $address) {
            if ($address->getType() === OrderAddress::TYPE_BILLING) {
                return $address;
            }
        }
        return null;
    }

    public function getShippingAddress(): ?OrderAddress
    {
        foreach ($this->addresses as $address) {
            if ($address->getType() === OrderAddress::TYPE_SHIPPING) {
                return $address;
            }
        }
        return null;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATES, true);
    }

    /**
     * Transition the order to 'paid' and stamp paid_at. Idempotent -
     * a second call (e.g. duplicate webhook) is a no-op. Throws if
     * called on a terminal order (DELIVERED already paid, REFUNDED
     * paid then reversed, etc.).
     *
     * @return bool True if this call performed the transition (the order
     *              was not already paid/beyond); false on an idempotent
     *              no-op. Callers use this to run once-per-order side
     *              effects (e.g. flash-campaign stock) exactly once.
     */
    public function markPaid(?DateTimeImmutable $when = null): bool
    {
        if ($this->status === self::STATUS_PAID || $this->status === self::STATUS_FULFILLING
            || $this->status === self::STATUS_SHIPPED || $this->status === self::STATUS_DELIVERED) {
            // Idempotent: already paid (or beyond). Don't double-stamp.
            return false;
        }
        if ($this->isTerminal()) {
            throw new \DomainException(
                "Cannot mark order paid: terminal state '{$this->status}' (order_reference={$this->orderReference})"
            );
        }
        $this->status = self::STATUS_PAID;
        $this->paidAt = $when ?? new DateTimeImmutable();
        $this->touchUpdatedAt();
        return true;
    }

    /**
     * Transition to 'failed'. Used by the webhook handler when Noon
     * reports a non-recoverable payment failure. Idempotent.
     */
    public function markFailed(): void
    {
        if ($this->status === self::STATUS_FAILED) {
            return;
        }
        if ($this->isTerminal()) {
            throw new \DomainException(
                "Cannot mark order failed: terminal state '{$this->status}' (order_reference={$this->orderReference})"
            );
        }
        $this->status = self::STATUS_FAILED;
        $this->touchUpdatedAt();
    }

    /**
     * Admin-driven order status override. Bypasses normal state-
     * machine validation, used by safety overrides (e.g. unsticking
     * an order, manually marking failed). Caller MUST audit the
     * override; this method does NOT itself record an audit row.
     *
     * Returns the previous status so caller can record before→after.
     */
    public function overrideStatus(string $newStatus): string
    {
        $previous = $this->status;
        // Validate enum membership but skip transition validation.
        $valid = [
            self::STATUS_PENDING_PAYMENT,
            self::STATUS_PAID,
            self::STATUS_FULFILLING,
            self::STATUS_SHIPPED,
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED,
            self::STATUS_REFUNDED,
            self::STATUS_FAILED,
        ];
        if (!in_array($newStatus, $valid, true)) {
            throw new \InvalidArgumentException("Unknown Order status: '{$newStatus}'");
        }
        $this->status = $newStatus;
        $this->touchUpdatedAt();
        return $previous;
    }

    /**
     * Recompute order-level status from item-level statuses. Called
     * by vendor + admin transition controllers after they advance an
     * individual item. Order rolls up as follows:
     *
     *   - All items DELIVERED  → order DELIVERED (terminal-ish)
     *   - All items SHIPPED or later (delivered/returned/refunded)
     *     → order SHIPPED
     *   - Any item ACCEPTED, PREPARING, SHIPPED, DELIVERED, RETURNED
     *     or REFUNDED while at least one item still active → FULFILLING
     *
     *   - Order in PENDING_PAYMENT, FAILED, CANCELLED, REFUNDED is
     *     left untouched (terminal or pre-fulfilment states; vendor
     *     item transitions don't apply).
     *
     * Designed to be safe to call repeatedly, same item-state input
     * always produces the same order-state output.
     *
     * NOTE: this method does NOT advance the order beyond DELIVERED
     * into REFUNDED; that's a separate flow (Phase E refund handler
     * sets the order to REFUNDED explicitly).
     */
    public function recomputeStatusFromItems(): void
    {
        // Only recompute for orders that have entered fulfilment.
        // Pre-fulfilment (pending_payment, paid not-yet-fulfilling)
        // and post-fulfilment terminal states aren't affected by
        // vendor item-level transitions.
        if (
            $this->status === self::STATUS_PENDING_PAYMENT
            || $this->status === self::STATUS_CANCELLED
            || $this->status === self::STATUS_REFUNDED
            || $this->status === self::STATUS_FAILED
        ) {
            return;
        }

        $items = $this->items->toArray();
        if ($items === []) {
            return;
        }

        $allDelivered = true;
        $allShippedOrLater = true;
        $anyActive = false;

        foreach ($items as $item) {
            /** @var OrderItem $item */
            $s = $item->getItemStatus();

            if ($s !== OrderItem::ITEM_STATUS_DELIVERED) {
                $allDelivered = false;
            }
            // "Shipped or later" = shipped, delivered, returned, refunded.
            // Pre-shipped items (pending, accepted, preparing) break this.
            $shippedOrLater = in_array($s, [
                OrderItem::ITEM_STATUS_SHIPPED,
                OrderItem::ITEM_STATUS_DELIVERED,
                OrderItem::ITEM_STATUS_RETURNED,
                OrderItem::ITEM_STATUS_REFUNDED,
            ], true);
            if (!$shippedOrLater) {
                $allShippedOrLater = false;
            }

            // An item is "active" (driving fulfilment) if it's past
            // PENDING and not rejected/cancelled.
            $active = in_array($s, [
                OrderItem::ITEM_STATUS_ACCEPTED,
                OrderItem::ITEM_STATUS_PREPARING,
                OrderItem::ITEM_STATUS_SHIPPED,
                OrderItem::ITEM_STATUS_DELIVERED,
                OrderItem::ITEM_STATUS_RETURNED,
                OrderItem::ITEM_STATUS_REFUNDED,
            ], true);
            if ($active) {
                $anyActive = true;
            }
        }

        $newStatus = null;
        if ($allDelivered) {
            $newStatus = self::STATUS_DELIVERED;
        } elseif ($allShippedOrLater) {
            $newStatus = self::STATUS_SHIPPED;
        } elseif ($anyActive) {
            $newStatus = self::STATUS_FULFILLING;
        }

        if ($newStatus !== null && $newStatus !== $this->status) {
            $this->status = $newStatus;
            // Stamp delivered_at the first time the order reaches
            // DELIVERED. Set once, a later re-transition (e.g. an item
            // returned then re-delivered) keeps the original timestamp.
            if ($newStatus === self::STATUS_DELIVERED && $this->deliveredAt === null) {
                $this->deliveredAt = new DateTimeImmutable();
            }
            $this->touchUpdatedAt();
        }
    }

    /**
     * Add an OrderItem to the order. Updates subtotal + total to
     * stay consistent. Caller is responsible for snapshotting
     * product_name, product_image, etc. before construction.
     */
    public function addItem(OrderItem $item): void
    {
        $this->items->add($item);
        $item->setOrder($this);
        // Recompute money fields:
        $this->recomputeTotals();
    }

    public function addAddress(OrderAddress $address): void
    {
        // Defensive: caller might try to add a duplicate type. The
        // DB UNIQUE (order_id, type) would reject it anyway, but
        // failing fast is friendlier.
        foreach ($this->addresses as $existing) {
            if ($existing->getType() === $address->getType()) {
                throw new \DomainException(
                    "Order already has a {$address->getType()} address; remove first if replacing."
                );
            }
        }
        $this->addresses->add($address);
        $address->setOrder($this);
        $this->touchUpdatedAt();
    }

    /**
     * Force-update delivery_fee + recompute total. Used at checkout
     * if delivery fee is calculated after items are added (the legacy
     * pattern: mobile lays out items, then queries delivery, then
     * finalises).
     */
    public function setDeliveryFee(string $deliveryFee): void
    {
        $this->assertMoneyNonNeg($deliveryFee, 'delivery_fee');
        $this->deliveryFee = $deliveryFee;
        $this->recomputeTotals();
    }

    public function setDiscount(string $discount): void
    {
        $this->assertMoneyNonNeg($discount, 'discount');
        $this->discount = $discount;
        $this->recomputeTotals();
    }

    // -----------------------------------------------------------------
    // Promo redemption association (M3.2.X.8)
    // -----------------------------------------------------------------

    public function getPromoRedemption(): ?PromoRedemption
    {
        return $this->promoRedemption;
    }

    /**
     * Attach a promo redemption to this order. Called by
     * InitiateCheckoutController inside the checkout EM transaction
     * AFTER the redemption row has been persisted (so Doctrine has a
     * managed entity to reference).
     *
     * Does NOT touch the discount column, the caller is expected to
     * call setDiscount($redemption->getDiscountAmount()) separately
     * since the redemption amount and the order discount, while
     * usually equal, are logically distinct (the order discount could
     * in principle combine other lines in a future engine).
     */
    public function setPromoRedemption(?PromoRedemption $redemption): void
    {
        $this->promoRedemption = $redemption;
    }

    public function getGiftCardAmount(): string { return $this->giftCardAmount; }
    public function getGiftCardCodeSnapshot(): ?string { return $this->giftCardCodeSnapshot; }

    /**
     * Record that a gift card was applied to this order.
     * The amount is the portion of the total covered by the gift card.
     * The gateway is charged (total - amount) after this.
     *
     * Called by InitiateCheckoutController inside the checkout EM
     * transaction, after GiftCard::debit() succeeds.
     */
    public function applyGiftCard(string $amount, string $codeSnapshot): void
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException(
                "gift_card_amount must be a non-negative DECIMAL(10,2) string, got '{$amount}'."
            );
        }
        if (bccomp($amount, $this->total, 2) > 0) {
            throw new \DomainException(
                "Gift card amount {$amount} exceeds order total {$this->total}."
            );
        }
        $this->giftCardAmount      = $amount;
        $this->giftCardCodeSnapshot = substr(strtoupper(str_replace('-', '', $codeSnapshot)), 0, 16);
    }

    /** Amount the payment gateway must charge (total minus any gift card applied). */
    public function gatewayChargeAmount(): string
    {
        $diff = bcsub($this->total, $this->giftCardAmount, 2);
        return bccomp($diff, '0.00', 2) < 0 ? '0.00' : $diff;
    }

    private function recomputeTotals(): void
    {
        // subtotal stays as the sum of item subtotals (immutable
        // after items are locked in). The total is the only piece
        // that recomputes on fee/discount changes.
        $itemSum = '0.00';
        foreach ($this->items as $item) {
            $itemSum = bcadd($itemSum, $item->getSubtotal(), 2);
        }
        $this->subtotal = $itemSum;
        $this->total = $this->computeTotal($this->subtotal, $this->deliveryFee, $this->discount);
        $this->touchUpdatedAt();
    }

    private function computeTotal(string $subtotal, string $deliveryFee, string $discount): string
    {
        $gross = bcadd($subtotal, $deliveryFee, 2);
        $net = bcsub($gross, $discount, 2);
        // Floor at zero, a discount can't make the order negative.
        return bccomp($net, '0.00', 2) < 0 ? '0.00' : $net;
    }

    private function assertMoneyNonNeg(string $value, string $field): void
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException(
                "Order.{$field} must be a non-negative DECIMAL(10,2) string, got '{$value}'"
            );
        }
    }
}
