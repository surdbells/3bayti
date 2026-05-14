<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Bayti\Api\Domain\Common\Timestamps;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A curated outfit / look — community or editorial style.
 *
 * Domain shape
 * ============
 * A Style is a curated grouping of products presented as an outfit.
 * Two scopes (controlled by `style_type` smallint):
 *
 *   - TYPE_COMMUNITY = 1: user-generated styles (legacy mobile users
 *     could create "looks" by combining products from vendors). In
 *     v3 these are read-only from mobile's perspective; creation
 *     is admin-only until a user-facing v3 endpoint ships.
 *
 *   - TYPE_EDITORIAL = 2: admin-curated editorial spreads (e.g.
 *     "Eid Looks 2026", "Office Wardrobe"). Lighter set than
 *     community; explicitly editorial.
 *
 * The legacy backend appears to mix both under `styles_list?type=`.
 * v3 surfaces them through the same endpoint with type filtering.
 *
 * total_price
 * ===========
 * Denormalised sum of contained products' prices. Computed at
 * style-creation time (admin UI) and NOT auto-recomputed when
 * product prices change. Acceptable because mobile uses total_price
 * as a display-only "outfit total" hint, not a checkout amount.
 * If product prices drift, the displayed total may differ from a
 * checkout total — by design.
 *
 * Slug
 * ====
 * Globally unique (UNIQUE constraint on slug alone). Same URL
 * design as Category: /styles/<slug>.
 *
 * Products embed
 * ==============
 * Many-to-many through `style_products` join table with explicit
 * display_order. Eager-fetched in the list endpoint via DQL JOIN to
 * avoid N+1 — see ListStylesController.
 */
#[ORM\Entity(repositoryClass: StyleRepository::class)]
#[ORM\Table(name: 'styles')]
#[ORM\HasLifecycleCallbacks]
class Style
{
    use Timestamps;

    public const TYPE_COMMUNITY = 1;
    public const TYPE_EDITORIAL = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /**
     * Legacy `styles.id` reference. Nullable for v3-native styles.
     * UNIQUE for idempotent re-migration safety.
     */
    #[ORM\Column(name: 'legacy_style_id', type: 'bigint', nullable: true, unique: true)]
    private ?int $legacyStyleId = null;

    #[ORM\Column(name: 'slug', type: 'string', length: 150, unique: true)]
    private string $slug;

    #[ORM\Column(name: 'name', type: 'string', length: 200)]
    private string $name;

    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'cover_image_url', type: 'string', length: 500, nullable: true)]
    private ?string $coverImageUrl = null;

    /**
     * Style type. 1 = community, 2 = editorial.
     * DB-level CHECK constraint enforces the valid set.
     */
    #[ORM\Column(name: 'style_type', type: 'smallint')]
    private int $styleType = self::TYPE_COMMUNITY;

    #[ORM\Column(name: 'total_price', type: 'decimal', precision: 10, scale: 2)]
    private string $totalPrice = '0.00';

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(name: 'display_order', type: 'smallint', nullable: true)]
    private ?int $displayOrder = null;

    /**
     * Products in this style. The many-to-many goes through the
     * `style_products` join table with display_order. Doctrine's
     * default many-to-many requires that the join table have
     * exactly two columns (the two FKs); since we added
     * display_order, we COULD use it as a many-to-many with
     * `inverseJoinColumns`, but DQL ordering by the join's
     * display_order requires the explicit StyleProduct entity
     * pattern.
     *
     * Tradeoff locked: skip the StyleProduct entity for now; use
     * a simple many-to-many. Mobile's response transform will
     * receive products in whatever order Doctrine returns (which
     * by default is by product.id ASC — not display_order).
     *
     * If display_order matters for UX (and it does — mobile reads
     * style.products[0], [1], [2]), we either:
     *   (a) Add the StyleProduct association entity (more code)
     *   (b) Use raw SQL with ORDER BY in the repository's load
     *       method (less Doctrine-y but simpler)
     *
     * Locked: option (b) — see StyleRepository::loadProductsOrdered.
     * The $products association below is for cascade ops + the
     * Doctrine metadata, but the controller bypasses it and uses
     * the ordered loader.
     *
     * @var Collection<int, Product>
     */
    #[ORM\ManyToMany(targetEntity: Product::class)]
    #[ORM\JoinTable(name: 'style_products')]
    #[ORM\JoinColumn(name: 'style_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'product_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $products;

    public function __construct(string $slug, string $name, int $styleType = self::TYPE_COMMUNITY)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->styleType = $styleType;
        $this->products = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLegacyStyleId(): ?int
    {
        return $this->legacyStyleId;
    }

    public function setLegacyStyleId(?int $id): void
    {
        $this->legacyStyleId = $id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getCoverImageUrl(): ?string
    {
        return $this->coverImageUrl;
    }

    public function setCoverImageUrl(?string $url): void
    {
        $this->coverImageUrl = $url;
    }

    public function getStyleType(): int
    {
        return $this->styleType;
    }

    public function setStyleType(int $type): void
    {
        if (!in_array($type, [self::TYPE_COMMUNITY, self::TYPE_EDITORIAL], true)) {
            throw new \InvalidArgumentException("Invalid style type: $type");
        }
        $this->styleType = $type;
    }

    public function getTotalPrice(): string
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(string $price): void
    {
        $this->totalPrice = $price;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
    }

    public function getDisplayOrder(): ?int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(?int $order): void
    {
        $this->displayOrder = $order;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }
}
