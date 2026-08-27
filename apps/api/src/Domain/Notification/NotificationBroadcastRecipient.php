<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-device outcome of a broadcast, backs the recipient drill-down and
 * the "resend to failed" flow.
 *
 * status: pending (row created, not yet attempted) -> sent (FCM accepted)
 * or failed (FCM rejected; error_kind carries the reason). Only the
 * last 6 chars of the token are stored (token_suffix), never the secret.
 */
#[ORM\Entity(repositoryClass: NotificationBroadcastRecipientRepository::class)]
#[ORM\Table(name: 'notification_broadcast_recipients')]
#[ORM\Index(columns: ['broadcast_id', 'status'], name: 'idx_nbr_broadcast_status')]
#[ORM\Index(columns: ['broadcast_id', 'platform'], name: 'idx_nbr_broadcast_platform')]
class NotificationBroadcastRecipient
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\Column(name: 'broadcast_id', type: 'bigint')]
    private int $broadcastId;

    #[ORM\Column(name: 'user_id', type: 'bigint', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(name: 'device_token_id', type: 'bigint', nullable: true)]
    private ?int $deviceTokenId = null;

    #[ORM\Column(name: 'token_suffix', type: 'string', length: 16, nullable: true)]
    private ?string $tokenSuffix = null;

    #[ORM\Column(name: 'platform', type: 'string', length: 12)]
    private string $platform;

    #[ORM\Column(name: 'status', type: 'string', length: 12)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'error_kind', type: 'string', length: 24, nullable: true)]
    private ?string $errorKind = null;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        int $broadcastId,
        string $platform,
        ?int $userId = null,
        ?int $deviceTokenId = null,
        ?string $tokenSuffix = null,
    ) {
        $this->broadcastId = $broadcastId;
        $this->platform = $platform;
        $this->userId = $userId;
        $this->deviceTokenId = $deviceTokenId;
        $this->tokenSuffix = $tokenSuffix;
        $this->createdAt = new DateTimeImmutable();
    }

    public function markSent(): void
    {
        $this->status = self::STATUS_SENT;
        $this->sentAt = new DateTimeImmutable();
    }

    public function markFailed(string $kind, ?string $message): void
    {
        $this->status = self::STATUS_FAILED;
        $this->errorKind = $kind;
        $this->errorMessage = $message;
    }

    public function getId(): ?int { return $this->id; }
    public function getBroadcastId(): int { return $this->broadcastId; }
    public function getUserId(): ?int { return $this->userId; }
    public function getDeviceTokenId(): ?int { return $this->deviceTokenId; }
    public function getTokenSuffix(): ?string { return $this->tokenSuffix; }
    public function getPlatform(): string { return $this->platform; }
    public function getStatus(): string { return $this->status; }
    public function getErrorKind(): ?string { return $this->errorKind; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function getSentAt(): ?DateTimeImmutable { return $this->sentAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
