<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Support;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'support_tickets')]
#[ORM\HasLifecycleCallbacks]
class Ticket
{
    const STATUS_OPEN      = 'open';
    const STATUS_PENDING   = 'pending';
    const STATUS_RESOLVED  = 'resolved';
    const STATUS_CLOSED    = 'closed';

    const PRIORITY_LOW     = 'low';
    const PRIORITY_MEDIUM  = 'medium';
    const PRIORITY_HIGH    = 'high';
    const PRIORITY_URGENT  = 'urgent';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(name: 'vendor_id', type: 'bigint', nullable: true)]
    private ?int $vendorId = null;

    #[ORM\Column(name: 'user_id', type: 'bigint', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(name: 'subject', type: 'string', length: 255)]
    private string $subject;

    #[ORM\Column(name: 'body', type: 'text')]
    private string $body;

    #[ORM\Column(name: 'status', type: 'string', length: 20, options: ['default' => self::STATUS_OPEN])]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(name: 'priority', type: 'string', length: 20, options: ['default' => self::PRIORITY_MEDIUM])]
    private string $priority = self::PRIORITY_MEDIUM;

    /** @var Collection<int, TicketMessage> */
    #[ORM\OneToMany(targetEntity: TicketMessage::class, mappedBy: 'ticket', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $messages;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $subject, string $body, ?int $vendorId = null, ?int $userId = null)
    {
        $this->subject   = $subject;
        $this->body      = $body;
        $this->vendorId  = $vendorId;
        $this->userId    = $userId;
        $this->messages  = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void { $this->updatedAt = new DateTimeImmutable(); }

    public function getId(): ?int         { return $this->id; }
    public function getVendorId(): ?int   { return $this->vendorId; }
    public function getUserId(): ?int     { return $this->userId; }
    public function getSubject(): string  { return $this->subject; }
    public function getBody(): string     { return $this->body; }
    public function getStatus(): string   { return $this->status; }
    public function getPriority(): string { return $this->priority; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, TicketMessage> */
    public function getMessages(): Collection { return $this->messages; }

    public function setStatus(string $status): void   { $this->status   = $status;   $this->updatedAt = new DateTimeImmutable(); }
    public function setPriority(string $priority): void { $this->priority = $priority; $this->updatedAt = new DateTimeImmutable(); }
    public function setSubject(string $subject): void { $this->subject  = $subject; }
    public function setBody(string $body): void       { $this->body     = $body; }
}
