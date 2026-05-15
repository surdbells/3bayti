<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\Common\Timestamps;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A finalised order — created at checkout-initiate (with status
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
 * UNIQUE at the DB layer — defensive against the case where Noon's
 * merchant-side uniqueness is not enabled (per their docs that
 * setting requires manual support enablement).
 *
 * Monetary fields
 * ================
 * subtotal       — sum of (item.unit_price * item.quantity)
 * delivery_fee   — fixed at checkout, can vary by vendor/region
 * discount       — any promo/loyalty discount applied
 * total          — subtotal + delivery_fee - discount (or zero if negative)
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
     * @var Collection<int, OrderItem>
     */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    /**
     * @var Collection<int, OrderAddress>
     */
    #[ORM\OneToMany(targetEntity: OrderAddress::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $addresses;

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
     * Transition the order to 'paid' and stamp paid_at. Idempotent —
     * a second call (e.g. duplicate webhook) is a no-op. Throws if
     * called on a terminal order (DELIVERED already paid, REFUNDED
     * paid then reversed, etc.).
     */
    public function markPaid(?DateTimeImmutable $when = null): void
    {
        if ($this->status === self::STATUS_PAID || $this->status === self::STATUS_FULFILLING
            || $this->status === self::STATUS_SHIPPED || $this->status === self::STATUS_DELIVERED) {
            // Idempotent: already paid (or beyond). Don't double-stamp.
            return;
        }
        if ($this->isTerminal()) {
            throw new \DomainException(
                "Cannot mark order paid: terminal state '{$this->status}' (order_reference={$this->orderReference})"
            );
        }
        $this->status = self::STATUS_PAID;
        $this->paidAt = $when ?? new DateTimeImmutable();
        $this->touchUpdatedAt();
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
     * Designed to be safe to call repeatedly — same item-state input
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
        // Floor at zero — a discount can't make the order negative.
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
