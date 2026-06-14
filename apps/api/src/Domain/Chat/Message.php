<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * A single chat message within a conversation. Senders are the customer,
 * the vendor, or the system (auto-generated order details / status
 * notices). Moderation state is carried on the row: a message that tries
 * to share personal contact info is stored with STATUS_BLOCKED (kept for
 * the audit trail and shown back to its sender with a warning) and is
 * never delivered to the other party.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'chat_messages')]
#[ORM\Index(name: 'idx_chat_msg_conversation', columns: ['conversation_id', 'id'])]
class Message
{
    public const TYPE_TEXT   = 'text';
    public const TYPE_IMAGE  = 'image';
    public const TYPE_SYSTEM = 'system';

    public const STATUS_SENT     = 'sent';
    public const STATUS_BLOCKED  = 'blocked';
    public const STATUS_REDACTED = 'redacted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(name: 'conversation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    /** Null for system messages. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'sender_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $sender = null;

    /** 'customer' | 'vendor' | 'system'. */
    #[ORM\Column(name: 'sender_type', type: 'string', length: 16)]
    private string $senderType;

    #[ORM\Column(type: 'string', length: 16, options: ['default' => self::TYPE_TEXT])]
    private string $type = self::TYPE_TEXT;

    #[ORM\Column(type: 'text')]
    private string $content;

    /** Optional Arabic rendering (system messages are bilingual). */
    #[ORM\Column(name: 'content_ar', type: 'text', nullable: true)]
    private ?string $contentAr = null;

    #[ORM\Column(name: 'is_flagged', type: 'boolean', options: ['default' => false])]
    private bool $isFlagged = false;

    /** Comma-separated detected categories, e.g. 'phone,email'. */
    #[ORM\Column(name: 'flag_type', type: 'string', length: 64, nullable: true)]
    private ?string $flagType = null;

    #[ORM\Column(type: 'string', length: 16, options: ['default' => self::STATUS_SENT])]
    private string $status = self::STATUS_SENT;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    private function __construct(Conversation $conversation, string $senderType, string $content)
    {
        $this->uuid = (string) Uuid::uuid7();
        $this->conversation = $conversation;
        $this->senderType = $senderType;
        $this->content = $content;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function fromCustomer(Conversation $c, User $sender, string $content, string $type = self::TYPE_TEXT): self
    {
        $m = new self($c, Conversation::PARTY_CUSTOMER, $content);
        $m->sender = $sender;
        $m->type = $type;
        return $m;
    }

    public static function fromVendor(Conversation $c, User $sender, string $content, string $type = self::TYPE_TEXT): self
    {
        $m = new self($c, Conversation::PARTY_VENDOR, $content);
        $m->sender = $sender;
        $m->type = $type;
        return $m;
    }

    public static function system(Conversation $c, string $content, ?string $contentAr = null): self
    {
        $m = new self($c, Conversation::PARTY_SYSTEM, $content);
        $m->type = self::TYPE_SYSTEM;
        $m->contentAr = $contentAr;
        return $m;
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getConversation(): Conversation { return $this->conversation; }
    public function getSender(): ?User { return $this->sender; }
    public function getSenderType(): string { return $this->senderType; }
    public function getType(): string { return $this->type; }
    public function getContent(): string { return $this->content; }
    public function getContentAr(): ?string { return $this->contentAr; }
    public function isFlagged(): bool { return $this->isFlagged; }
    public function getFlagType(): ?string { return $this->flagType; }
    public function getStatus(): string { return $this->status; }
    public function isDelivered(): bool { return $this->status !== self::STATUS_BLOCKED; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Mark this message as blocked by moderation (not delivered). */
    public function block(string $flagType): void
    {
        $this->status = self::STATUS_BLOCKED;
        $this->isFlagged = true;
        $this->flagType = $flagType;
    }

    /** Replace content with a redacted version (delivered, but masked). */
    public function redact(string $redactedContent, string $flagType): void
    {
        $this->content = $redactedContent;
        $this->status = self::STATUS_REDACTED;
        $this->isFlagged = true;
        $this->flagType = $flagType;
    }
}
