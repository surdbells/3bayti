<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * An order-item-scoped chat thread between the buying customer and the
 * selling vendor. Exactly one conversation exists per order item (mirrors
 * the legacy chat_conversations.order_item_id uniqueness): each custom
 * piece keeps its own thread and its own measurements.
 *
 * Per-party unread counters let each side show an accurate badge without a
 * COUNT query per request; last_message_* powers the conversation list.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'chat_conversations')]
#[ORM\Index(name: 'idx_chat_conv_vendor', columns: ['vendor_id', 'last_message_at'])]
#[ORM\Index(name: 'idx_chat_conv_customer', columns: ['customer_id', 'last_message_at'])]
#[ORM\UniqueConstraint(name: 'uniq_chat_conv_order_item', columns: ['order_item_id'])]
class Conversation
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';

    public const PARTY_CUSTOMER = 'customer';
    public const PARTY_VENDOR   = 'vendor';
    public const PARTY_SYSTEM   = 'system';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Vendor $vendor;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: OrderItem::class)]
    #[ORM\JoinColumn(name: 'order_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private OrderItem $orderItem;

    #[ORM\Column(type: 'string', length: 16, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'customer_unread_count', type: 'integer', options: ['default' => 0])]
    private int $customerUnreadCount = 0;

    #[ORM\Column(name: 'vendor_unread_count', type: 'integer', options: ['default' => 0])]
    private int $vendorUnreadCount = 0;

    #[ORM\Column(name: 'last_message_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    #[ORM\Column(name: 'last_message_preview', type: 'string', length: 200, nullable: true)]
    private ?string $lastMessagePreview = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $customer, Vendor $vendor, Order $order, OrderItem $orderItem)
    {
        $this->uuid = (string) Uuid::uuid7();
        $this->customer = $customer;
        $this->vendor = $vendor;
        $this->order = $order;
        $this->orderItem = $orderItem;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getCustomer(): User { return $this->customer; }
    public function getVendor(): Vendor { return $this->vendor; }
    public function getOrder(): Order { return $this->order; }
    public function getOrderItem(): OrderItem { return $this->orderItem; }
    public function getStatus(): string { return $this->status; }
    public function getCustomerUnreadCount(): int { return $this->customerUnreadCount; }
    public function getVendorUnreadCount(): int { return $this->vendorUnreadCount; }
    public function getLastMessageAt(): ?\DateTimeImmutable { return $this->lastMessageAt; }
    public function getLastMessagePreview(): ?string { return $this->lastMessagePreview; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function close(): void { $this->status = self::STATUS_CLOSED; $this->touch(); }

    /**
     * Record that a (delivered) message was added. Bumps the recipient's
     * unread counter and refreshes the list preview. A system message is
     * unread for both parties (e.g. the order-details seed alerts the
     * vendor to a new order).
     */
    public function recordMessage(string $senderType, string $preview): void
    {
        $this->lastMessageAt = new \DateTimeImmutable();
        $this->lastMessagePreview = mb_substr(trim($preview), 0, 200) ?: null;

        switch ($senderType) {
            case self::PARTY_CUSTOMER:
                $this->vendorUnreadCount++;
                break;
            case self::PARTY_VENDOR:
                $this->customerUnreadCount++;
                break;
            case self::PARTY_SYSTEM:
                $this->vendorUnreadCount++;
                $this->customerUnreadCount++;
                break;
        }
        $this->touch();
    }

    public function markReadFor(string $party): void
    {
        if ($party === self::PARTY_CUSTOMER) {
            $this->customerUnreadCount = 0;
        } elseif ($party === self::PARTY_VENDOR) {
            $this->vendorUnreadCount = 0;
        }
        $this->touch();
    }

    public function unreadFor(string $party): int
    {
        return $party === self::PARTY_CUSTOMER ? $this->customerUnreadCount : $this->vendorUnreadCount;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
