<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Common\Timestamps;
use Doctrine\ORM\Mapping as ORM;

/**
 * Line item within an Order. Snapshotted from a CartItem at checkout.
 *
 * Why so many *_snapshot fields
 * ==============================
 * The order_items table is the source of truth for what the customer
 * bought, regardless of later edits to the underlying catalog row.
 * If a vendor renames a product after the order is placed, the order
 * detail page still shows what the customer remembers buying. If a
 * vendor changes the primary image, the order detail page still shows
 * the image at purchase time. This is contractual: "the order is for
 * THIS thing".
 *
 * Snapshotted fields:
 *   product_name_snapshot  , display name at purchase time
 *   product_image_snapshot , primary image URL at purchase time
 *   unit_price             , price at purchase time
 *   subtotal               , unit_price * quantity at purchase time
 *   vendor_id              , vendor relationship FROZEN at purchase
 *                              (even though products.vendor_id is
 *                              technically mutable)
 *
 * NOT snapshotted (still references the catalog row):
 *   product_id             , FK to products(id), so we can navigate
 *                              to the live product page for "buy
 *                              again", "leave review", etc.
 *
 * item_status lifecycle
 * ======================
 *   pending → accepted → preparing → shipped → delivered
 *        \                                  \
 *         → rejected                         → returned → refunded
 *                                            → cancelled
 *
 * Per-item status (not per-order) because each line might be from
 * a different vendor, and one vendor's rejection doesn't fail the
 * whole order, the others can still ship.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_items')]
#[ORM\HasLifecycleCallbacks]
class OrderItem
{
    use Timestamps;

    public const ITEM_STATUS_PENDING = 'pending';
    public const ITEM_STATUS_ACCEPTED = 'accepted';
    public const ITEM_STATUS_REJECTED = 'rejected';
    public const ITEM_STATUS_PREPARING = 'preparing';
    public const ITEM_STATUS_SHIPPED = 'shipped';
    public const ITEM_STATUS_DELIVERED = 'delivered';
    public const ITEM_STATUS_CANCELLED = 'cancelled';
    public const ITEM_STATUS_RETURNED = 'returned';
    public const ITEM_STATUS_REFUNDED = 'refunded';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Vendor $vendor;

    #[ORM\Column(type: 'smallint')]
    private int $quantity;

    #[ORM\Column(name: 'unit_price', type: 'decimal', precision: 10, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $subtotal;

    #[ORM\Column(name: 'product_name_snapshot', type: 'string', length: 255)]
    private string $productNameSnapshot;

    #[ORM\Column(name: 'product_image_snapshot', type: 'string', length: 500, nullable: true)]
    private ?string $productImageSnapshot = null;

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

    #[ORM\Column(name: 'item_status', type: 'string', length: 32)]
    private string $itemStatus = self::ITEM_STATUS_PENDING;

    public function __construct(
        Product $product,
        Vendor $vendor,
        int $quantity,
        string $unitPrice,
        string $productNameSnapshot,
        ?string $productImageSnapshot = null,
        ?string $size = null,
        ?string $color = null,
        bool $isCustom = false,
        ?string $measurement = null,
        ?string $extraMeasurement = null,
        ?string $note = null,
    ) {
        if ($quantity < 1) {
            throw new \InvalidArgumentException("OrderItem quantity must be >= 1, got {$quantity}");
        }
        if (bccomp($unitPrice, '0.00', 2) < 0) {
            throw new \InvalidArgumentException("OrderItem unit_price must be >= 0, got '{$unitPrice}'");
        }
        if ($productNameSnapshot === '') {
            throw new \InvalidArgumentException('OrderItem product_name_snapshot cannot be empty.');
        }

        $this->product = $product;
        $this->vendor = $vendor;
        $this->quantity = $quantity;
        $this->unitPrice = $unitPrice;
        $this->subtotal = bcmul($unitPrice, (string) $quantity, 2);
        $this->productNameSnapshot = mb_substr($productNameSnapshot, 0, 255);
        $this->productImageSnapshot = $productImageSnapshot !== null
            ? mb_substr($productImageSnapshot, 0, 500) : null;
        $this->size = $size;
        $this->color = $color;
        $this->isCustom = $isCustom;
        $this->measurement = $measurement;
        $this->extraMeasurement = $extraMeasurement;
        $this->note = $note;
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

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function setOrder(Order $order): void
    {
        $this->order = $order;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getVendor(): Vendor
    {
        return $this->vendor;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function getSubtotal(): string
    {
        return $this->subtotal;
    }

    public function getProductNameSnapshot(): string
    {
        return $this->productNameSnapshot;
    }

    public function getProductImageSnapshot(): ?string
    {
        return $this->productImageSnapshot;
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

    public function getItemStatus(): string
    {
        return $this->itemStatus;
    }

    /**
     * Vendor-side state transition. Validates the move is legal in
     * the lifecycle. Used by M3.1.7 vendor + admin controllers.
     *
     * Allowed transitions:
     *   pending    → accepted | rejected | cancelled
     *   accepted   → preparing | cancelled
     *   preparing  → shipped | cancelled
     *   shipped    → delivered | returned
     *   delivered  → returned (post-delivery returns flow)
     *   rejected   → (terminal)
     *   cancelled  → refunded (when refund processes)
     *   returned   → refunded
     *   refunded   → (terminal)
     *
     * Same-state transitions (e.g. preparing → preparing) are no-ops
     * rather than errors, idempotent for retries and dual-tap UI.
     *
     * @param bool $adminOverride When true, skip transition validation but keep enum validation. Used by admin controllers that need to force a status for safety overrides (e.g. unstick a stuck order). Caller must audit the override.
     */
    public function setItemStatus(string $newStatus, bool $adminOverride = false): void
    {
        $allowed = [
            self::ITEM_STATUS_PENDING,
            self::ITEM_STATUS_ACCEPTED,
            self::ITEM_STATUS_REJECTED,
            self::ITEM_STATUS_PREPARING,
            self::ITEM_STATUS_SHIPPED,
            self::ITEM_STATUS_DELIVERED,
            self::ITEM_STATUS_CANCELLED,
            self::ITEM_STATUS_RETURNED,
            self::ITEM_STATUS_REFUNDED,
        ];
        if (!in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException("Unknown OrderItem status: '{$newStatus}'");
        }

        // Same-state is idempotent no-op
        if ($newStatus === $this->itemStatus) {
            return;
        }

        if (!$adminOverride) {
            $legalTransitions = self::legalTransitions();
            $allowedFromHere = $legalTransitions[$this->itemStatus] ?? [];
            if (!in_array($newStatus, $allowedFromHere, true)) {
                throw new \InvalidArgumentException(sprintf(
                    "Illegal item status transition: '%s' → '%s'. Allowed: [%s]",
                    $this->itemStatus,
                    $newStatus,
                    implode(', ', $allowedFromHere),
                ));
            }
        }

        $this->itemStatus = $newStatus;
        $this->touchUpdatedAt();
    }

    /**
     * Legal transition map. Public+static for controller pre-validation
     * (so we can return a 422 with the allowed list instead of letting
     * the entity throw a 500).
     *
     * @return array<string, list<string>>
     */
    public static function legalTransitions(): array
    {
        return [
            self::ITEM_STATUS_PENDING => [
                self::ITEM_STATUS_ACCEPTED,
                self::ITEM_STATUS_REJECTED,
                self::ITEM_STATUS_CANCELLED,
            ],
            self::ITEM_STATUS_ACCEPTED => [
                self::ITEM_STATUS_PREPARING,
                self::ITEM_STATUS_CANCELLED,
            ],
            self::ITEM_STATUS_PREPARING => [
                self::ITEM_STATUS_SHIPPED,
                self::ITEM_STATUS_CANCELLED,
            ],
            self::ITEM_STATUS_SHIPPED => [
                self::ITEM_STATUS_DELIVERED,
                self::ITEM_STATUS_RETURNED,
            ],
            self::ITEM_STATUS_DELIVERED => [
                self::ITEM_STATUS_RETURNED,
            ],
            self::ITEM_STATUS_REJECTED => [],
            self::ITEM_STATUS_CANCELLED => [
                self::ITEM_STATUS_REFUNDED,
            ],
            self::ITEM_STATUS_RETURNED => [
                self::ITEM_STATUS_REFUNDED,
            ],
            self::ITEM_STATUS_REFUNDED => [],
        ];
    }
}
