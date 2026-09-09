<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Common\Timestamps;
use Doctrine\ORM\Mapping as ORM;

/**
 * One courier shipment for ONE vendor's slice of an order.
 *
 * 3bayti is multi-vendor, so an order splits into one shipment per store
 * (pickup from that vendor → the customer). The (order, vendor) pair is unique
 * and identifies which order items the shipment covers, so no join table is
 * needed. The provider (OTO) id + tracking are filled in when the shipment is
 * booked; the webhook later advances `status` and back-fills tracking.
 *
 * order_id is ON DELETE CASCADE, so a deleted order (incl. the admin hard
 * delete) takes its shipments with it.
 */
#[ORM\Entity(repositoryClass: OrderShipmentRepository::class)]
#[ORM\Table(name: 'order_shipments')]
#[ORM\UniqueConstraint(name: 'uniq_order_vendor_shipment', columns: ['order_id', 'vendor_id'])]
#[ORM\HasLifecycleCallbacks]
class OrderShipment
{
    use Timestamps;

    // Local shipment lifecycle (provider-agnostic; OTO statuses map onto these).
    public const STATUS_BOOKED      = 'booked';      // pushed to the provider, courier assigned/awaiting pickup
    public const STATUS_IN_TRANSIT  = 'in_transit';  // picked up / on the way
    public const STATUS_DELIVERED   = 'delivered';
    public const STATUS_RETURNED    = 'returned';
    public const STATUS_CANCELLED   = 'cancelled';
    public const STATUS_FAILED      = 'failed';

    public const PROVIDER_OTO = 'oto';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Vendor $vendor;

    #[ORM\Column(name: 'provider', type: 'string', length: 20, options: ['default' => self::PROVIDER_OTO])]
    private string $provider = self::PROVIDER_OTO;

    /** The provider's own order/shipment id (OTO `otoId`), echoed by the webhook. */
    #[ORM\Column(name: 'provider_order_id', type: 'string', length: 64, nullable: true)]
    private ?string $providerOrderId = null;

    #[ORM\Column(name: 'tracking_number', type: 'string', length: 120, nullable: true)]
    private ?string $trackingNumber = null;

    #[ORM\Column(name: 'dc_tracking_number', type: 'string', length: 120, nullable: true)]
    private ?string $dcTrackingNumber = null;

    #[ORM\Column(name: 'delivery_company', type: 'string', length: 120, nullable: true)]
    private ?string $deliveryCompany = null;

    /** The carrier option chosen at booking, when the vendor/admin forced one. */
    #[ORM\Column(name: 'delivery_option_id', type: 'string', length: 120, nullable: true)]
    private ?string $deliveryOptionId = null;

    #[ORM\Column(name: 'status', type: 'string', length: 20, options: ['default' => self::STATUS_BOOKED])]
    private string $status = self::STATUS_BOOKED;

    /** The provider's raw status string from the last webhook, for support/debug. */
    #[ORM\Column(name: 'last_status_raw', type: 'string', length: 120, nullable: true)]
    private ?string $lastStatusRaw = null;

    public function __construct(Order $order, Vendor $vendor, string $provider = self::PROVIDER_OTO)
    {
        $this->order = $order;
        $this->vendor = $vendor;
        $this->provider = $provider;
        $this->initTimestamps();
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->touchUpdatedAt();
    }

    /** Record a successful booking with the provider. */
    public function markBooked(
        string $providerOrderId,
        ?string $trackingNumber = null,
        ?string $deliveryCompany = null,
        ?string $deliveryOptionId = null,
    ): void {
        $this->providerOrderId = $providerOrderId;
        $this->status = self::STATUS_BOOKED;
        if ($trackingNumber !== null && $trackingNumber !== '') {
            $this->trackingNumber = $trackingNumber;
        }
        if ($deliveryCompany !== null && $deliveryCompany !== '') {
            $this->deliveryCompany = $deliveryCompany;
        }
        if ($deliveryOptionId !== null && $deliveryOptionId !== '') {
            $this->deliveryOptionId = $deliveryOptionId;
        }
        $this->touchUpdatedAt();
    }

    /**
     * Apply a provider (OTO) status update, mapping it onto the local lifecycle
     * and back-filling any tracking details the webhook carried.
     */
    public function applyOtoStatus(
        string $rawStatus,
        ?string $trackingNumber = null,
        ?string $dcTrackingNumber = null,
        ?string $deliveryCompany = null,
    ): void {
        $this->lastStatusRaw = $rawStatus;
        $this->status = self::mapOtoStatus($rawStatus);
        if ($trackingNumber !== null && $trackingNumber !== '') {
            $this->trackingNumber = $trackingNumber;
        }
        if ($dcTrackingNumber !== null && $dcTrackingNumber !== '') {
            $this->dcTrackingNumber = $dcTrackingNumber;
        }
        if ($deliveryCompany !== null && $deliveryCompany !== '') {
            $this->deliveryCompany = $deliveryCompany;
        }
        $this->touchUpdatedAt();
    }

    /**
     * Map an OTO status string onto our local lifecycle. Defensive substring
     * matching (OTO's exact strings vary by carrier/account); order matters so
     * "out for delivery" isn't caught by the "deliver" → delivered rule.
     */
    public static function mapOtoStatus(string $raw): string
    {
        $s = strtolower(trim($raw));
        return match (true) {
            str_contains($s, 'return') => self::STATUS_RETURNED,
            str_contains($s, 'cancel') => self::STATUS_CANCELLED,
            str_contains($s, 'fail') || str_contains($s, 'reject') => self::STATUS_FAILED,
            str_contains($s, 'out for delivery') => self::STATUS_IN_TRANSIT,
            str_contains($s, 'deliver') => self::STATUS_DELIVERED,
            str_contains($s, 'transit')
                || str_contains($s, 'shipped')
                || str_contains($s, 'pick')
                || str_contains($s, 'warehouse')
                || str_contains($s, 'received')
                || str_contains($s, 'assigned') => self::STATUS_IN_TRANSIT,
            default => self::STATUS_BOOKED,
        };
    }

    /**
     * The order-item status this shipment status implies, or null when it
     * shouldn't move the items. The webhook uses this to sync the line items.
     */
    public function impliedItemStatus(): ?string
    {
        return match ($this->status) {
            self::STATUS_BOOKED, self::STATUS_IN_TRANSIT => OrderItem::ITEM_STATUS_SHIPPED,
            self::STATUS_DELIVERED => OrderItem::ITEM_STATUS_DELIVERED,
            self::STATUS_RETURNED => OrderItem::ITEM_STATUS_RETURNED,
            self::STATUS_CANCELLED => OrderItem::ITEM_STATUS_CANCELLED,
            default => null,
        };
    }

    // ── Accessors ─────────────────────────────────────────────────────
    public function getId(): ?int { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function getVendor(): Vendor { return $this->vendor; }
    public function getProvider(): string { return $this->provider; }
    public function getProviderOrderId(): ?string { return $this->providerOrderId; }
    public function getTrackingNumber(): ?string { return $this->trackingNumber; }
    public function getDcTrackingNumber(): ?string { return $this->dcTrackingNumber; }
    public function getDeliveryCompany(): ?string { return $this->deliveryCompany; }
    public function getDeliveryOptionId(): ?string { return $this->deliveryOptionId; }
    public function getStatus(): string { return $this->status; }
    public function getLastStatusRaw(): ?string { return $this->lastStatusRaw; }
}
