<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cart;

use Bayti\Api\Domain\Common\Timestamps;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Server-side cart for an authenticated user OR a legacy-migrated
 * cart with no v3 user mapping.
 *
 * Persistence model (locked Q7=B)
 * ================================
 * 3bayti's cart model has two storage layers:
 *
 *   - Server-side for logged-in users (this entity)
 *   - Device-local for guests (mobile holds it in storage)
 *
 * When a guest signs in, the mobile app calls `POST /v3/cart/merge`
 * with the device-local payload; the server merges it into the user's
 * server cart (creating one if it doesn't exist) and the device-local
 * copy is cleared.
 *
 * One active cart per user
 * =========================
 * Enforced by the partial unique index `uq_carts_user_active` at the
 * DB layer, only one row per user_id can have status='active'. When
 * a cart is checked out, status flips to 'converted' (cart frozen,
 * order created); when it expires (M3.1.7+ cleanup), it flips to
 * 'archived'. Both terminal states release the unique constraint so
 * a fresh active cart can be created for the same user.
 *
 * Why user_id is NULLABLE
 * ========================
 * Legacy carts migrated from the CodeIgniter schema may not have a
 * resolvable v3 user_id (the original customer might have never
 * been migrated, or the cart row pre-dates user creation). Those
 * carts carry user_id NULL and legacy_cart_code populated.
 *
 * New v3 carts ALWAYS have a user_id. The application layer enforces
 * this via the AddCartItem controller (rejects requests without
 * authenticated user).
 */
#[ORM\Entity(repositoryClass: CartRepository::class)]
#[ORM\Table(name: 'carts')]
#[ORM\HasLifecycleCallbacks]
class Cart
{
    use Timestamps;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'legacy_cart_code', type: 'string', length: 32, nullable: true)]
    private ?string $legacyCartCode = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $status = self::STATUS_ACTIVE;

    /**
     * ISO 4217 currency code. Always 'AED' at v3 launch; column is
     * here for the eventual multi-currency expansion (M4+).
     */
    #[ORM\Column(type: 'string', length: 3)]
    private string $currency = 'AED';

    /**
     * @var Collection<int, CartItem>
     */
    #[ORM\OneToMany(targetEntity: CartItem::class, mappedBy: 'cart', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    public function __construct(?User $user = null, ?string $legacyCartCode = null)
    {
        if ($user === null && $legacyCartCode === null) {
            throw new \InvalidArgumentException(
                'Cart must have either a user or a legacy_cart_code; cannot be fully anonymous.'
            );
        }
        $this->user = $user;
        $this->legacyCartCode = $legacyCartCode;
        $this->items = new ArrayCollection();
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getLegacyCartCode(): ?string
    {
        return $this->legacyCartCode;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * @return Collection<int, CartItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Is this cart eligible to be considered abandoned at the
     * given moment with the given threshold? (M3.2.X.11)
     *
     * A cart is abandoned when:
     *   - status is 'active' (not converted, not archived)
     *   - it has at least one item
     *   - updated_at is older than ($now - $threshold)
     *
     * The PreUpdate hook refreshes updated_at on every mutation
     * (addItem, removeItem, etc.), so updated_at staleness is
     * the right signal for "customer hasn't touched this".
     *
     * This helper centralises the policy but the actual eligibility
     * query in CartAbandonmentFinder also enforces the "hasn't
     * been emailed yet" guard via a NOT EXISTS subquery on
     * notification_logs, that part can't be answered from the
     * Cart entity alone.
     */
    public function isAbandoned(DateTimeImmutable $now, \DateInterval $threshold): bool
    {
        if (!$this->isActive()) {
            return false;
        }
        if ($this->items->isEmpty()) {
            return false;
        }
        $cutoff = $now->sub($threshold);
        return $this->updatedAt < $cutoff;
    }

    public function markConverted(): void
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            throw new \DomainException(
                "Cannot convert cart in status '{$this->status}' — only active carts can be converted."
            );
        }
        $this->status = self::STATUS_CONVERTED;
        $this->touchUpdatedAt();
    }

    /**
     * Restore a converted cart to active. Used when an order created from
     * this cart could not be handed off to the payment provider (e.g. the
     * gateway rejected or timed out at INITIATE): payment never started, so
     * the customer must keep their cart and be able to retry. A transient
     * provider error must never empty someone's cart.
     *
     * Strict inverse of markConverted(): only a converted cart can be
     * reactivated. touchUpdatedAt() bumps the timestamp so a retry derives a
     * fresh idempotency key rather than echoing the failed attempt.
     */
    public function reactivate(): void
    {
        if ($this->status !== self::STATUS_CONVERTED) {
            throw new \DomainException(
                "Cannot reactivate cart in status '{$this->status}' — only converted carts can be reactivated."
            );
        }
        $this->status = self::STATUS_ACTIVE;
        $this->touchUpdatedAt();
    }

    public function markArchived(): void
    {
        $this->status = self::STATUS_ARCHIVED;
        $this->touchUpdatedAt();
    }

    /**
     * Add a CartItem. Caller is responsible for snapshotting the
     * unit price from products.price BEFORE construction.
     */
    public function addItem(CartItem $item): void
    {
        $this->items->add($item);
        $item->setCart($this);
        $this->touchUpdatedAt();
    }

    public function removeItem(CartItem $item): void
    {
        $this->items->removeElement($item);
        $this->touchUpdatedAt();
    }

    /**
     * Find an existing item matching (product_id, size, color,
     * is_custom, measurement, extra_measurement, note). Used by the
     * AddCartItem controller to decide whether to increment quantity
     * on an existing line vs create a new line.
     *
     * Strict tuple match: same product but different size → DIFFERENT
     * line. This mirrors the legacy behaviour and keeps variant
     * stock semantics correct.
     */
    public function findEquivalentItem(CartItem $candidate): ?CartItem
    {
        foreach ($this->items as $existing) {
            if ($existing->isEquivalentTo($candidate)) {
                return $existing;
            }
        }
        return null;
    }

    /**
     * Compute subtotal across all items at current snapshotted prices.
     * Returned as a DECIMAL-safe string (bcmath); callers should never
     * convert to float for money math.
     */
    public function computeSubtotal(): string
    {
        $sum = '0.00';
        foreach ($this->items as $item) {
            $lineTotal = bcmul($item->getUnitPriceSnapshot(), (string) $item->getQuantity(), 2);
            $sum = bcadd($sum, $lineTotal, 2);
        }
        return $sum;
    }

    /**
     * Subtotal across only the items sold by a given vendor (v3 Vendor PK),
     * at current snapshotted prices. Same per-line math as computeSubtotal(),
     * filtered by each item's product vendor.
     *
     * Used by the promo resolver so a vendor-scoped coupon discounts only that
     * vendor's items rather than the whole cart. Returns '0.00' when the cart
     * holds nothing from that vendor.
     */
    public function computeSubtotalForVendor(int $vendorId): string
    {
        $sum = '0.00';
        foreach ($this->items as $item) {
            if ($item->getProduct()->getVendor()->getId() !== $vendorId) {
                continue;
            }
            $lineTotal = bcmul($item->getUnitPriceSnapshot(), (string) $item->getQuantity(), 2);
            $sum = bcadd($sum, $lineTotal, 2);
        }
        return $sum;
    }

    public function itemCount(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->getQuantity();
        }
        return $total;
    }
}
