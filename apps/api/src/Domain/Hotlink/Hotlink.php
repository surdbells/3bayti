<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Hotlink;

use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * A shareable short link (P9) that redirects to a store (vendor) or a Style
 * look, with click + conversion tracking. Canonical: exactly one hotlink per
 * (target_type, target_slug) — a unique constraint enforces it, so any number
 * of sharers reuse the same code and its aggregate stats.
 *
 * The short `code` resolves via GET /v3/hotlinks/{code}; the resolver records a
 * row in the hotlink_clicks ledger (HotlinkClickLogger) and the client then
 * navigates to /stores/{slug} or /styles/{slug}. Conversion attribution is an
 * admin-side event-window join (a click's user ordering within N days),
 * mirroring the AI revenue attribution.
 */
#[ORM\Entity(repositoryClass: HotlinkRepository::class)]
#[ORM\Table(name: 'hotlinks')]
#[ORM\UniqueConstraint(name: 'uniq_hotlink_target', columns: ['target_type', 'target_slug'])]
class Hotlink
{
    public const TARGET_STORE = 'store';
    public const TARGET_STYLE = 'style';

    public const ALL_TARGETS = [self::TARGET_STORE, self::TARGET_STYLE];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    /** Short opaque code used in the public short URL (…/s/{code}). */
    #[ORM\Column(type: 'string', length: 24, unique: true)]
    private string $code;

    /** 'store' | 'style'. */
    #[ORM\Column(name: 'target_type', type: 'string', length: 16)]
    private string $targetType;

    /** The vendor/style slug this hotlink resolves to. */
    #[ORM\Column(name: 'target_slug', type: 'string', length: 200)]
    private string $targetSlug;

    /** Who first created the hotlink (nullable; canonical link is shared by all). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdByUser = null;

    /** Denormalized aggregate click count (the hotlink_clicks ledger is authoritative). */
    #[ORM\Column(name: 'click_count', type: 'integer', options: ['default' => 0])]
    private int $clickCount = 0;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(string $code, string $targetType, string $targetSlug, ?User $createdByUser = null)
    {
        if (!in_array($targetType, self::ALL_TARGETS, true)) {
            throw new \InvalidArgumentException("Unknown hotlink target type '{$targetType}'.");
        }
        $this->code = $code;
        $this->targetType = $targetType;
        $this->targetSlug = $targetSlug;
        $this->createdByUser = $createdByUser;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Count one resolve (the ledger row is written separately). */
    public function recordClick(): void
    {
        $this->clickCount++;
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getTargetType(): string { return $this->targetType; }
    public function getTargetSlug(): string { return $this->targetSlug; }
    public function getCreatedByUser(): ?User { return $this->createdByUser; }
    public function getClickCount(): int { return $this->clickCount; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
