<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reusable push message definition. Title/body may contain {{variables}}
 * (see TemplateVariableResolver) that are resolved per-recipient when a
 * broadcast built from the template is sent.
 */
#[ORM\Entity(repositoryClass: NotificationTemplateRepository::class)]
#[ORM\Table(name: 'notification_templates')]
#[ORM\Index(columns: ['status'], name: 'idx_notification_templates_status')]
class NotificationTemplate
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 200)]
    private string $name;

    #[ORM\Column(name: 'title', type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(name: 'body', type: 'text')]
    private string $body;

    #[ORM\Column(name: 'image_url', type: 'string', length: 1000, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(name: 'deep_link', type: 'string', length: 1000, nullable: true)]
    private ?string $deepLink = null;

    #[ORM\Column(name: 'status', type: 'string', length: 16)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'created_by_user_id', type: 'bigint', nullable: true)]
    private ?int $createdByUserId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $name,
        string $title,
        string $body,
        ?int $createdByUserId = null,
        ?string $imageUrl = null,
        ?string $deepLink = null,
    ) {
        $now = new DateTimeImmutable();
        $this->name = $name;
        $this->title = $title;
        $this->body = $body;
        $this->createdByUserId = $createdByUserId;
        $this->imageUrl = $imageUrl;
        $this->deepLink = $deepLink;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function update(string $name, string $title, string $body, ?string $imageUrl, ?string $deepLink): void
    {
        $this->name = $name;
        $this->title = $title;
        $this->body = $body;
        $this->imageUrl = $imageUrl;
        $this->deepLink = $deepLink;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        $this->status = $status === self::STATUS_INACTIVE ? self::STATUS_INACTIVE : self::STATUS_ACTIVE;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getTitle(): string { return $this->title; }
    public function getBody(): string { return $this->body; }
    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function getDeepLink(): ?string { return $this->deepLink; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedByUserId(): ?int { return $this->createdByUserId; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
}
