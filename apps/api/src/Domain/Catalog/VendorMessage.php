<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * A direct message from a platform admin to a vendor (the vendor's inbox).
 * One-directional; threading/status lives in support tickets, not here.
 */
#[ORM\Entity(repositoryClass: VendorMessageRepository::class)]
#[ORM\Table(name: 'vendor_messages')]
class VendorMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Vendor $vendor;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'sender_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $sender = null;

    #[ORM\Column(name: 'subject', type: 'string', length: 200, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(name: 'body', type: 'text')]
    private string $body;

    #[ORM\Column(name: 'is_read', type: 'boolean', options: ['default' => false])]
    private bool $isRead = false;

    #[ORM\Column(name: 'read_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Vendor $vendor, ?User $sender, string $body, ?string $subject = null)
    {
        $this->vendor = $vendor;
        $this->sender = $sender;
        $this->body = $body;
        $this->subject = $subject;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getVendor(): Vendor { return $this->vendor; }
    public function getSender(): ?User { return $this->sender; }
    public function getSubject(): ?string { return $this->subject; }
    public function getBody(): string { return $this->body; }
    public function isRead(): bool { return $this->isRead; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function markRead(): void
    {
        if (!$this->isRead) {
            $this->isRead = true;
            $this->readAt = new \DateTimeImmutable();
        }
    }
}
