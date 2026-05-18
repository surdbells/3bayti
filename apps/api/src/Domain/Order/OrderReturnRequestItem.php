<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\Catalog\Vendor;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single OrderItem being returned within an OrderReturnRequest
 * (M3.2.X.18-A).
 *
 * Why a separate entity (vs JSONB on the parent)
 * ===============================================
 * Vendor-portal queries need to filter by vendor_id efficiently. With
 * a child row per returned item, we get a normal indexed lookup
 * (idx_orri_vendor_request) for "show me returns containing items I
 * sold". A JSONB-on-parent design would force every vendor query to
 * scan + json_each every return request. The child entity is one
 * Postgres row per returned item — cheap, indexed, queryable.
 *
 * Denormalized vendor_id
 * ======================
 * The vendor_id is denormalized here (we already have it via
 * order_item.vendor_id). Two reasons:
 *
 *   1. Fast vendor-portal filtering — no join through order_items
 *      every time a vendor lists their incoming returns.
 *   2. Vendor-of-record stability — if an admin ever reassigns an
 *      OrderItem to a different vendor (unlikely but possible in
 *      future ops scenarios), the return request's vendor
 *      attribution stays with the original vendor that fulfilled
 *      the order. Returns belong to whoever sold the goods.
 *
 * Snapshotted unit price + line subtotal
 * =======================================
 * Captured at return-create time (NOT pulled from the live OrderItem
 * at refund time). This matters because:
 *
 *   - If the product price changes after the order was placed, the
 *     refund is owed at the price the customer paid — not the
 *     current catalog price.
 *   - OrderItem.unit_price IS already a snapshot of the price at
 *     order time, so for v1 this is mostly belt-and-braces, but
 *     copying it here keeps the return-refund math self-contained
 *     (we never have to join back through OrderItem at refund time).
 *
 * Per-item quantity
 * =================
 * A customer can partially return an OrderItem — e.g., bought 3 of a
 * size-M shirt, returning 1. The quantity here is the count being
 * returned; the line_subtotal column is unit_price_snapshot * quantity
 * (computed at create time).
 *
 * DB constraint chk_orri_quantity_positive enforces quantity > 0.
 *
 * No status column
 * ================
 * The OrderReturnRequestItem's "status" is the parent
 * OrderReturnRequest's status — they all advance together. The
 * fine-grained OrderItem.item_status (RETURNED, REFUNDED) is set on
 * the originating OrderItem when the parent return request reaches
 * the corresponding terminal state.
 */
#[ORM\Entity(repositoryClass: OrderReturnRequestItemRepository::class)]
#[ORM\Table(name: 'order_return_request_items')]
class OrderReturnRequestItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrderReturnRequest::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'return_request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private OrderReturnRequest $returnRequest;

    #[ORM\ManyToOne(targetEntity: OrderItem::class)]
    #[ORM\JoinColumn(name: 'order_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private OrderItem $orderItem;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Vendor $vendor;

    #[ORM\Column(name: 'quantity', type: 'integer')]
    private int $quantity;

    #[ORM\Column(name: 'unit_price_snapshot', type: 'decimal', precision: 10, scale: 2)]
    private string $unitPriceSnapshot;

    #[ORM\Column(name: 'line_subtotal', type: 'decimal', precision: 10, scale: 2)]
    private string $lineSubtotal;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * Construct a return-request line item. The unit_price_snapshot
     * and vendor are pulled from the originating OrderItem at create
     * time so the return-refund math is self-contained.
     *
     * @throws \InvalidArgumentException if quantity <= 0 or exceeds
     *         the OrderItem's quantity (over-return guard).
     */
    public function __construct(
        OrderItem $orderItem,
        int $quantity,
    ) {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException(
                "Return quantity must be > 0; got {$quantity}."
            );
        }
        if ($quantity > $orderItem->getQuantity()) {
            throw new \InvalidArgumentException(
                "Return quantity {$quantity} exceeds order item quantity "
                . "{$orderItem->getQuantity()}."
            );
        }

        $this->orderItem = $orderItem;
        $this->vendor = $orderItem->getVendor();
        $this->quantity = $quantity;
        $this->unitPriceSnapshot = $orderItem->getUnitPrice();
        $this->lineSubtotal = self::multiplyMoney(
            $this->unitPriceSnapshot,
            $quantity,
        );
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Bidirectional collection setter — called from
     * OrderReturnRequest::addItem after the parent persists itself.
     */
    public function setReturnRequest(OrderReturnRequest $returnRequest): void
    {
        $this->returnRequest = $returnRequest;
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------

    public function getId(): ?int { return $this->id; }
    public function getReturnRequest(): OrderReturnRequest { return $this->returnRequest; }
    public function getOrderItem(): OrderItem { return $this->orderItem; }
    public function getVendor(): Vendor { return $this->vendor; }
    public function getQuantity(): int { return $this->quantity; }
    public function getUnitPriceSnapshot(): string { return $this->unitPriceSnapshot; }
    public function getLineSubtotal(): string { return $this->lineSubtotal; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }

    // -----------------------------------------------------------------
    // Money helpers
    // -----------------------------------------------------------------

    /**
     * Multiply a DECIMAL(10,2) money string by an integer count.
     * Uses bcmath to avoid floating-point drift on the final cents.
     */
    private static function multiplyMoney(string $money, int $count): string
    {
        return bcmul($money, (string) $count, 2);
    }
}
