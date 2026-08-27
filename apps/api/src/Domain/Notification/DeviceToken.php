<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single push-notification registration token for one device.
 *
 * One row per device token (the unique index on `token` enforces it).
 * Re-registration upserts this row: it refreshes last_seen_at, can
 * reactivate a previously-deactivated token, and reassigns user_id if
 * the device has since signed in as a different user.
 *
 * Net-new in M3.2.Z.4 (push notifications, Q-Z4=A: FCM-only, FCM
 * relays to APNs for iOS, so the platform column is informational
 * /analytics only; both platforms carry FCM tokens).
 *
 * Lives in Domain\Notification alongside NotificationLog: this is part
 * of the outbound-notification subsystem, not the user-profile domain.
 */
#[ORM\Entity(repositoryClass: DeviceTokenRepository::class)]
#[ORM\Table(name: 'device_tokens')]
#[ORM\UniqueConstraint(name: 'uq_device_tokens_token', columns: ['token'])]
#[ORM\Index(columns: ['user_id', 'is_active'], name: 'idx_device_tokens_user_active')]
class DeviceToken
{
    public const PLATFORM_IOS = 'ios';
    public const PLATFORM_ANDROID = 'android';

    /** @var list<string> Accepted platform values. */
    public const PLATFORMS = [self::PLATFORM_IOS, self::PLATFORM_ANDROID];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    /** @phpstan-ignore property.unusedType (Doctrine assigns the id via reflection on persist) */
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $token;

    #[ORM\Column(type: 'string', length: 16)]
    private string $platform;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'last_seen_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $lastSeenAt = null;

    public function __construct(User $user, string $token, string $platform)
    {
        $this->user = $user;
        $this->token = $token;
        $this->platform = $platform;
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->lastSeenAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastSeenAt(): ?DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    /**
     * Mark this token as seen again (re-registration / app open).
     * Reactivates it (a returning device is alive again), can move it
     * to a new owner, and may update the recorded platform. Re-stamps
     * updated_at + last_seen_at.
     */
    public function touch(User $user, string $platform): void
    {
        $this->user = $user;
        $this->platform = $platform;
        $this->isActive = true;
        $now = new DateTimeImmutable();
        $this->updatedAt = $now;
        $this->lastSeenAt = $now;
    }

    /** Deactivate (logout / opt-out / dead-token pruning). */
    public function deactivate(): void
    {
        if (!$this->isActive) {
            return;
        }
        $this->isActive = false;
        $this->updatedAt = new DateTimeImmutable();
    }
}
