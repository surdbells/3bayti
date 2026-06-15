<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A time-boxed merchandising campaign that puts a curated set of products
 * on promotion for a window (e.g. an "Anniversary Sale" or a short
 * "Flash Sale").
 *
 * Two types drive distinct homepage treatments:
 *   - 'anniversary' — celebratory deals, event countdown to ends_at
 *   - 'flash'       — urgency: live countdown + per-item stock bars
 *
 * Pricing
 * -------
 * `discount_percent` here is the campaign-wide default applied to every
 * product in the campaign. An individual CampaignItem may override it.
 * The effective discount is resolved per item (item override ?? campaign
 * default) and applied to the product's current price by the serializer —
 * the campaign never stores a per-product money amount, so it stays
 * correct as product prices change.
 *
 * Active window
 * -------------
 * A campaign is "live" when is_active is true AND now is within
 * [starts_at, ends_at]. The repository's findActiveByType() enforces this.
 */
#[ORM\Entity(repositoryClass: CampaignRepository::class)]
#[ORM\Table(name: 'campaigns')]
#[ORM\Index(name: 'idx_campaigns_type_active', columns: ['type', 'is_active'])]
#[ORM\HasLifecycleCallbacks]
class Campaign
{
    public const TYPE_ANNIVERSARY = 'anniversary';
    public const TYPE_FLASH = 'flash';

    /** @var list<string> */
    public const TYPES = [self::TYPE_ANNIVERSARY, self::TYPE_FLASH];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(name: 'slug', type: 'string', length: 220, unique: true)]
    private string $slug;

    #[ORM\Column(name: 'type', type: 'string', length: 20)]
    private string $type;

    #[ORM\Column(name: 'title', type: 'string', length: 200)]
    private string $title;

    #[ORM\Column(name: 'subtitle', type: 'string', length: 300, nullable: true)]
    private ?string $subtitle = null;

    /** Campaign-wide default discount (0-100), applied to items that don't override it. */
    #[ORM\Column(name: 'discount_percent', type: 'smallint', options: ['default' => 0])]
    private int $discountPercent = 0;

    #[ORM\Column(name: 'starts_at', type: 'datetime_immutable')]
    private DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at', type: 'datetime_immutable')]
    private DateTimeImmutable $endsAt;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    /** Lower number = higher priority when multiple campaigns of a type are live. */
    #[ORM\Column(name: 'priority', type: 'smallint', nullable: true)]
    private ?int $priority = null;

    /** @var Collection<int, CampaignItem> */
    #[ORM\OneToMany(mappedBy: 'campaign', targetEntity: CampaignItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $items;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $slug,
        string $type,
        string $title,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ) {
        $this->slug      = $slug;
        $this->type      = self::normalizeType($type);
        $this->title     = $title;
        $this->startsAt  = $startsAt;
        $this->endsAt    = $endsAt;
        $this->items     = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /** Whether the campaign is live at the given moment. */
    public function isLiveAt(DateTimeImmutable $now): bool
    {
        return $this->isActive && $now >= $this->startsAt && $now <= $this->endsAt;
    }

    private static function normalizeType(string $type): string
    {
        $t = strtolower(trim($type));
        return in_array($t, self::TYPES, true) ? $t : self::TYPE_ANNIVERSARY;
    }

    public function getId(): ?int                  { return $this->id; }
    public function getSlug(): string              { return $this->slug; }
    public function getType(): string              { return $this->type; }
    public function getTitle(): string             { return $this->title; }
    public function getSubtitle(): ?string         { return $this->subtitle; }
    public function getDiscountPercent(): int      { return $this->discountPercent; }
    public function getStartsAt(): DateTimeImmutable { return $this->startsAt; }
    public function getEndsAt(): DateTimeImmutable { return $this->endsAt; }
    public function isActive(): bool               { return $this->isActive; }
    public function getPriority(): ?int            { return $this->priority; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, CampaignItem> */
    public function getItems(): Collection { return $this->items; }

    public function addItem(CampaignItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setCampaign($this);
        }
    }

    public function removeItem(CampaignItem $item): void
    {
        $this->items->removeElement($item);
    }

    public function clearItems(): void
    {
        $this->items->clear();
    }

    public function setSlug(string $slug): void            { $this->slug = $slug; }
    public function setType(string $type): void            { $this->type = self::normalizeType($type); }
    public function setTitle(string $title): void          { $this->title = $title; }
    public function setSubtitle(?string $subtitle): void   { $this->subtitle = $subtitle; }
    public function setDiscountPercent(int $pct): void     { $this->discountPercent = max(0, min(100, $pct)); }
    public function setStartsAt(DateTimeImmutable $d): void { $this->startsAt = $d; }
    public function setEndsAt(DateTimeImmutable $d): void   { $this->endsAt = $d; }
    public function setActive(bool $active): void          { $this->isActive = $active; }
    public function setPriority(?int $priority): void      { $this->priority = $priority; }
}
