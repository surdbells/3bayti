<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Support;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'support_ticket_messages')]
class TicketMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'ticket_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Ticket $ticket;

    #[ORM\Column(name: 'user_id', type: 'bigint', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(name: 'author_name', type: 'string', length: 150, nullable: true)]
    private ?string $authorName = null;

    #[ORM\Column(name: 'body', type: 'text')]
    private string $body;

    #[ORM\Column(name: 'is_admin_reply', type: 'boolean', options: ['default' => false])]
    private bool $isAdminReply = false;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(Ticket $ticket, string $body, ?int $userId = null, bool $isAdminReply = false, ?string $authorName = null)
    {
        $this->ticket       = $ticket;
        $this->body         = $body;
        $this->userId       = $userId;
        $this->isAdminReply = $isAdminReply;
        $this->authorName   = $authorName;
        $this->createdAt    = new DateTimeImmutable();
    }

    public function getId(): ?int           { return $this->id; }
    public function getTicket(): Ticket     { return $this->ticket; }
    public function getUserId(): ?int       { return $this->userId; }
    public function getAuthorName(): ?string{ return $this->authorName; }
    public function getBody(): string       { return $this->body; }
    public function isAdminReply(): bool    { return $this->isAdminReply; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
