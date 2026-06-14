<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A curated product collection (e.g. "Summer Sale", "New Arrivals").
 * Products are linked via the CollectionProduct join table.
 */
#[ORM\Entity(repositoryClass: ProductCollectionRepository::class)]
#[ORM\Table(name: 'product_collections')]
#[ORM\HasLifecycleCallbacks]
class ProductCollection
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 200)]
    private string $name;

    /**
     * Legacy `collections.collections_id`, preserved so the migration is
     * idempotent and legacy ids can be mapped to v3 ids. Null for
     * collections created natively in v3.
     */
    #[ORM\Column(name: 'legacy_collection_id', type: 'integer', nullable: true, unique: true)]
    private ?int $legacyCollectionId = null;

    #[ORM\Column(name: 'slug', type: 'string', length: 220, unique: true)]
    private string $slug;

    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'cover_image_url', type: 'string', length: 500, nullable: true)]
    private ?string $coverImageUrl = null;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'display_order', type: 'smallint', nullable: true)]
    private ?int $displayOrder = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $slug)
    {
        $this->name      = $name;
        $this->slug      = $slug;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void { $this->updatedAt = new DateTimeImmutable(); }

    public function getId(): ?int             { return $this->id; }
    public function getName(): string         { return $this->name; }
    public function getLegacyCollectionId(): ?int { return $this->legacyCollectionId; }
    public function setLegacyCollectionId(?int $id): void { $this->legacyCollectionId = $id; }
    public function getSlug(): string         { return $this->slug; }
    public function getDescription(): ?string { return $this->description; }
    public function getCoverImageUrl(): ?string{ return $this->coverImageUrl; }
    public function isActive(): bool          { return $this->isActive; }
    public function getDisplayOrder(): ?int   { return $this->displayOrder; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function setName(string $name): void             { $this->name = $name; }
    public function setSlug(string $slug): void             { $this->slug = $slug; }
    public function setDescription(?string $d): void        { $this->description = $d; }
    public function setCoverImageUrl(?string $u): void      { $this->coverImageUrl = $u; }
    public function setActive(bool $active): void           { $this->isActive = $active; }
    public function setDisplayOrder(?int $order): void      { $this->displayOrder = $order; }
}
