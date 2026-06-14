<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Product — the central catalog entity.
 *
 * Maps the legacy flat-product model into a richer v3 shape that
 * preserves all legacy data but exposes typed accessors.
 *
 * Key design notes (see migration Version20260512000001 for full rationale):
 *
 * - Sizes/colors stored as JSONB arrays (denormalised from legacy's
 *   22 size_* boolean columns + comma-separated colors varchar). Read-side
 *   filters use Postgres GIN index on the JSONB.
 *
 * - `status` is the canonical lifecycle field ('active', 'draft', 'soft_deleted').
 *   `is_active` is a denormalised boolean for cheap index filtering on hot
 *   paths. The two are kept in sync by setStatus() — no public setter for
 *   is_active.
 *
 * - `primary_image_url` is the "lead" image (shown in cards). `images` is
 *   the JSONB array of all images. We keep both because primary card
 *   rendering is the most-frequent read and doesn't want to parse the
 *   full images array every time.
 *
 * - `legacy_product_id` is preserved for traceability and idempotent
 *   re-migration. Migration script checks for existence by this column
 *   before INSERTing.
 */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
class Product
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SOFT_DELETED = 'soft_deleted';

    public const STOCK_IN = 'in_stock';
    public const STOCK_OUT = 'out_of_stock';
    public const STOCK_LIMITED = 'limited';
    /**
     * Backorder status — accepting orders despite zero on-hand stock,
     * but warning customers of delayed fulfillment. Preserved from
     * legacy where 11 products use this value.
     */
    public const STOCK_BACKORDER = 'on_backorder';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'legacy_product_id', type: 'integer', nullable: true, unique: true)]
    private ?int $legacyProductId = null;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Vendor $vendor;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\Column(type: 'string', length: 200, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_DRAFT;

    /**
     * Denormalised from status for cheap index filtering.
     * NOT independently settable — kept in sync via setStatus().
     */
    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = false;

    // ---- pricing ----

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price = '0.00';

    #[ORM\Column(name: 'sale_price', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $salePrice = null;

    #[ORM\Column(name: 'cost_per_item', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $costPerItem = null;

    // ---- stock ----

    #[ORM\Column(name: 'stock_quantity', type: 'integer')]
    private int $stockQuantity = 0;

    #[ORM\Column(name: 'allow_oversell', type: 'boolean')]
    private bool $allowOversell = false;

    #[ORM\Column(name: 'stock_status', type: 'string', length: 20)]
    private string $stockStatus = self::STOCK_IN;

    #[ORM\Column(name: 'min_order_qty', type: 'integer', nullable: true)]
    private ?int $minOrderQty = null;

    #[ORM\Column(name: 'max_order_qty', type: 'integer', nullable: true)]
    private ?int $maxOrderQty = null;

    // ---- images ----

    #[ORM\Column(name: 'primary_image_url', type: 'string', length: 500, nullable: true)]
    private ?string $primaryImageUrl = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $images = [];

    // ---- variants (sizes + colors) ----

    /** @var list<string> */
    #[ORM\Column(name: 'available_sizes', type: 'json')]
    private array $availableSizes = [];

    /** @var list<string> */
    #[ORM\Column(name: 'available_colors', type: 'json')]
    private array $availableColors = [];

    // ---- display flags ----

    #[ORM\Column(name: 'is_featured', type: 'boolean')]
    private bool $isFeatured = false;

    #[ORM\Column(name: 'is_new', type: 'boolean')]
    private bool $isNew = false;

    #[ORM\Column(name: 'is_hot', type: 'boolean')]
    private bool $isHot = false;

    #[ORM\Column(name: 'is_sale', type: 'boolean')]
    private bool $isSale = false;

    #[ORM\Column(name: 'try_on_enabled', type: 'boolean')]
    private bool $tryOnEnabled = false;

    // ---- measurements ----

    #[ORM\Column(name: 'requires_extra_msmt', type: 'boolean')]
    private bool $requiresExtraMsmt = false;

    #[ORM\Column(name: 'extra_msmt', type: 'string', length: 255, nullable: true)]
    private ?string $extraMsmt = null;

    // ---- delivery ----

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'delivery_info', type: 'json', nullable: true)]
    private ?array $deliveryInfo = null;

    // ---- collection / label ----

    #[ORM\Column(name: 'collection_id', type: 'bigint', nullable: true)]
    private ?int $collectionId = null;

    #[ORM\Column(name: 'label_id', type: 'integer', nullable: true)]
    private ?int $labelId = null;

    // ---- search (M3.1.5.5a) ----

    /**
     * PostgreSQL-managed tsvector for fulltext search.
     *
     * The DB column is GENERATED ALWAYS AS STORED — Postgres
     * recomputes it on every INSERT/UPDATE from `name` and
     * `description`. The application never writes to it.
     *
     * insertable: false / updatable: false reflects that contract.
     * Doctrine reads the column on entity hydration (so it CAN be
     * referenced by DQL — see TsMatchFunction + TsRankFunction in
     * `src/Doctrine/DqlFunction/`) but never includes it in INSERT
     * or UPDATE statements, leaving the DB free to compute it.
     *
     * Stored as `string` from Doctrine's perspective because there's
     * no native `tsvector` type in DBAL. Treating as opaque text is
     * fine — application code never inspects the value, only the
     * DQL functions pass it to the PG operators.
     *
     * Nullable in the entity even though the DB column is NOT NULL,
     * because the column was added AFTER initial product creation
     * (the migration is forward-only; the GENERATED ALWAYS clause
     * computes a value immediately on every existing row, but
     * Doctrine sees an unhydrated entity as having null until
     * the SELECT fires).
     */
    #[ORM\Column(name: 'search_tsv', type: 'string', nullable: true, insertable: false, updatable: false)]
    private ?string $searchTsv = null;

    // ---- timestamps ----

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(Vendor $vendor, string $slug, string $name)
    {
        $this->vendor = $vendor;
        $this->slug = $slug;
        $this->name = $name;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    // -----------------------------------------------------------
    // Getters
    // -----------------------------------------------------------

    public function getId(): ?int { return $this->id; }
    public function getLegacyProductId(): ?int { return $this->legacyProductId; }
    public function getVendor(): Vendor { return $this->vendor; }
    public function getCategory(): ?Category { return $this->category; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getStatus(): string { return $this->status; }
    public function isActive(): bool { return $this->isActive; }
    public function getPrice(): string { return $this->price; }
    public function getSalePrice(): ?string { return $this->salePrice; }
    public function getCostPerItem(): ?string { return $this->costPerItem; }
    public function getStockQuantity(): int { return $this->stockQuantity; }
    public function getAllowOversell(): bool { return $this->allowOversell; }
    public function getStockStatus(): string { return $this->stockStatus; }
    public function getMinOrderQty(): ?int { return $this->minOrderQty; }
    public function getMaxOrderQty(): ?int { return $this->maxOrderQty; }
    public function getPrimaryImageUrl(): ?string { return $this->primaryImageUrl; }

    /** @return list<string> */
    public function getImages(): array { return $this->images; }

    /** @return list<string> */
    public function getAvailableSizes(): array { return $this->availableSizes; }

    /** @return list<string> */
    public function getAvailableColors(): array { return $this->availableColors; }

    public function isFeatured(): bool { return $this->isFeatured; }
    public function isNew(): bool { return $this->isNew; }
    public function isHot(): bool { return $this->isHot; }
    public function isSale(): bool { return $this->isSale; }
    public function isTryOnEnabled(): bool { return $this->tryOnEnabled; }
    public function requiresExtraMsmt(): bool { return $this->requiresExtraMsmt; }
    public function getExtraMsmt(): ?string { return $this->extraMsmt; }

    /** @return array<string, mixed>|null */
    public function getDeliveryInfo(): ?array { return $this->deliveryInfo; }

    public function getCollectionId(): ?int { return $this->collectionId; }
    public function getLabelId(): ?int { return $this->labelId; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    // -----------------------------------------------------------
    // Setters / mutators
    // -----------------------------------------------------------

    public function setLegacyProductId(?int $id): void
    {
        $this->legacyProductId = $id;
    }

    public function setCategory(?Category $category): void
    {
        $this->category = $category;
        $this->touch();
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
        $this->touch();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    /**
     * Set the lifecycle status. Updates is_active accordingly so the two
     * fields are never out of sync.
     *
     * @throws \InvalidArgumentException for unknown status values
     */
    public function setStatus(string $status): void
    {
        // Tolerate common API/client aliases before validating against the
        // canonical set: 'published' is the customer-facing word for active,
        // and 'inactive' is the word for a hidden (soft-deleted) listing.
        $status = match ($status) {
            'published' => self::STATUS_ACTIVE,
            'inactive'  => self::STATUS_SOFT_DELETED,
            default     => $status,
        };
        $valid = [self::STATUS_ACTIVE, self::STATUS_DRAFT, self::STATUS_SOFT_DELETED];
        if (!in_array($status, $valid, true)) {
            throw new \InvalidArgumentException(
                "Invalid product status '{$status}'. Must be one of: " . implode(', ', $valid)
            );
        }
        $this->status = $status;
        $this->isActive = ($status === self::STATUS_ACTIVE);
        $this->touch();
    }

    public function softDelete(): void
    {
        $this->setStatus(self::STATUS_SOFT_DELETED);
    }

    public function setPrice(string $price): void
    {
        $this->price = $price;
        $this->touch();
    }

    public function setSalePrice(?string $price): void
    {
        $this->salePrice = $price;
        $this->touch();
    }

    public function setCostPerItem(?string $cost): void
    {
        $this->costPerItem = $cost;
        $this->touch();
    }

    public function setStockQuantity(int $qty): void
    {
        $this->stockQuantity = max(0, $qty);
        // Auto-update stock_status when quantity drops to zero (unless explicitly oversold).
        if ($this->stockQuantity === 0 && !$this->allowOversell) {
            $this->stockStatus = self::STOCK_OUT;
        } elseif ($this->stockQuantity > 0 && $this->stockStatus === self::STOCK_OUT) {
            $this->stockStatus = self::STOCK_IN;
        }
        $this->touch();
    }

    public function setAllowOversell(bool $allow): void
    {
        $this->allowOversell = $allow;
        $this->touch();
    }

    public function setStockStatus(string $status): void
    {
        $valid = [self::STOCK_IN, self::STOCK_OUT, self::STOCK_LIMITED, self::STOCK_BACKORDER];
        if (!in_array($status, $valid, true)) {
            throw new \InvalidArgumentException("Invalid stock_status '{$status}'");
        }
        $this->stockStatus = $status;
        $this->touch();
    }

    public function setMinOrderQty(?int $qty): void
    {
        $this->minOrderQty = $qty;
        $this->touch();
    }

    public function setMaxOrderQty(?int $qty): void
    {
        $this->maxOrderQty = $qty;
        $this->touch();
    }

    public function setPrimaryImageUrl(?string $url): void
    {
        $this->primaryImageUrl = $url;
        $this->touch();
    }

    /** @param list<string> $images */
    public function setImages(array $images): void
    {
        $this->images = array_values(array_filter($images, 'is_string'));
        $this->touch();
    }

    /** @param list<string> $sizes */
    public function setAvailableSizes(array $sizes): void
    {
        $this->availableSizes = array_values(array_filter($sizes, 'is_string'));
        $this->touch();
    }

    /** @param list<string> $colors */
    public function setAvailableColors(array $colors): void
    {
        $this->availableColors = array_values(array_filter($colors, 'is_string'));
        $this->touch();
    }

    public function setIsFeatured(bool $v): void { $this->isFeatured = $v; $this->touch(); }
    public function setIsNew(bool $v): void { $this->isNew = $v; $this->touch(); }
    public function setIsHot(bool $v): void { $this->isHot = $v; $this->touch(); }
    public function setIsSale(bool $v): void { $this->isSale = $v; $this->touch(); }
    public function setTryOnEnabled(bool $v): void { $this->tryOnEnabled = $v; $this->touch(); }

    public function setRequiresExtraMsmt(bool $v): void
    {
        $this->requiresExtraMsmt = $v;
        $this->touch();
    }

    public function setExtraMsmt(?string $v): void
    {
        $this->extraMsmt = $v;
        $this->touch();
    }

    /** @param array<string, mixed>|null $info */
    public function setDeliveryInfo(?array $info): void
    {
        $this->deliveryInfo = $info;
        $this->touch();
    }

    public function setCollectionId(?int $id): void
    {
        $this->collectionId = $id;
        $this->touch();
    }

    public function setLabelId(?int $id): void
    {
        $this->labelId = $id;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
