<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cart;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Common\Timestamps;
use Doctrine\ORM\Mapping as ORM;

/**
 * Line item within a Cart.
 *
 * Snapshotting pattern
 * =====================
 * The `unit_price_snapshot` column captures `products.price` at the
 * moment the item was added to the cart. If the vendor later changes
 * the price, existing cart items retain the old price until the user
 * removes and re-adds the item. This matches legacy behaviour and
 * the customer's reasonable expectation ("I saw it at 199, I expect
 * to pay 199").
 *
 * At checkout, the snapshot is carried forward into order_items so
 * the order amount is immutable regardless of later product edits.
 *
 * Variant attributes
 * ===================
 * Mirrors the legacy mobile `add_cart` body shape:
 *   size, color, is_custom, measurement, extra_measurement, note
 *
 * `is_custom=true` typically means the customer requested custom
 * tailoring; `measurement` + `extra_measurement` are free-text
 * notes captured at add-to-cart time (NOT mid-checkout). The legacy
 * design conflates "tell the tailor" notes and "tell delivery" notes;
 * v3 keeps them in two columns to make M3.1.7's vendor handling
 * cleaner if we ever want to surface them separately.
 *
 * Equivalence semantics
 * ======================
 * Two CartItems are equivalent when ALL of these match:
 *   product_id, size, color, is_custom, measurement,
 *   extra_measurement, note
 *
 * If the customer adds the same product with a different note, those
 * are different lines (the tailor needs different instructions).
 */
#[ORM\Entity]
#[ORM\Table(name: 'cart_items')]
#[ORM\HasLifecycleCallbacks]
class CartItem
{
    use Timestamps;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Cart::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'cart_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Cart $cart;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    #[ORM\Column(type: 'smallint')]
    private int $quantity;

    #[ORM\Column(name: 'unit_price_snapshot', type: 'decimal', precision: 10, scale: 2)]
    private string $unitPriceSnapshot;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $size = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(name: 'is_custom', type: 'boolean')]
    private bool $isCustom = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $measurement = null;

    #[ORM\Column(name: 'extra_measurement', type: 'text', nullable: true)]
    private ?string $extraMeasurement = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /**
     * @param Product $product           the catalog product
     * @param int     $quantity          must be >= 1
     * @param string  $unitPriceSnapshot DECIMAL string, e.g. '199.00'
     */
    public function __construct(
        Product $product,
        int $quantity,
        string $unitPriceSnapshot,
        ?string $size = null,
        ?string $color = null,
        bool $isCustom = false,
        ?string $measurement = null,
        ?string $extraMeasurement = null,
        ?string $note = null,
    ) {
        if ($quantity < 1) {
            throw new \InvalidArgumentException("CartItem quantity must be >= 1, got {$quantity}");
        }
        if (bccomp($unitPriceSnapshot, '0.00', 2) < 0) {
            throw new \InvalidArgumentException("CartItem unit_price_snapshot must be >= 0, got '{$unitPriceSnapshot}'");
        }

        $this->product = $product;
        $this->quantity = $quantity;
        $this->unitPriceSnapshot = $unitPriceSnapshot;
        $this->size = $this->normaliseOptionalString($size);
        $this->color = $this->normaliseOptionalString($color);
        $this->isCustom = $isCustom;
        $this->measurement = $this->normaliseOptionalString($measurement);
        $this->extraMeasurement = $this->normaliseOptionalString($extraMeasurement);
        $this->note = $this->normaliseOptionalString($note);
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

    public function getCart(): Cart
    {
        return $this->cart;
    }

    public function setCart(Cart $cart): void
    {
        $this->cart = $cart;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException("CartItem quantity must be >= 1, got {$quantity}");
        }
        $this->quantity = $quantity;
        $this->touchUpdatedAt();
    }

    public function incrementQuantity(int $by = 1): void
    {
        $this->setQuantity($this->quantity + $by);
    }

    public function getUnitPriceSnapshot(): string
    {
        return $this->unitPriceSnapshot;
    }

    public function getSize(): ?string
    {
        return $this->size;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function isCustom(): bool
    {
        return $this->isCustom;
    }

    public function getMeasurement(): ?string
    {
        return $this->measurement;
    }

    public function getExtraMeasurement(): ?string
    {
        return $this->extraMeasurement;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * Compare ALL variant attributes (not the cart binding, not the
     * timestamps, not the quantity, not the price snapshot). Used by
     * Cart::findEquivalentItem to decide "increment quantity" vs
     * "add new line".
     */
    public function isEquivalentTo(CartItem $other): bool
    {
        return $this->product->getId() === $other->product->getId()
            && $this->size === $other->size
            && $this->color === $other->color
            && $this->isCustom === $other->isCustom
            && $this->measurement === $other->measurement
            && $this->extraMeasurement === $other->extraMeasurement
            && $this->note === $other->note;
    }

    /**
     * Empty / whitespace-only strings → null. Saves a row of '' values
     * being indistinguishable from null in equivalence checks.
     */
    private function normaliseOptionalString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
