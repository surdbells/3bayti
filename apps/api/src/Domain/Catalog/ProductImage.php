<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * ProductImage, individual image record for a product.
 *
 * Separate from `Product.images` JSONB array because:
 *  - Alt text, dimensions, ordering need typed columns
 *  - We may attach derived data (CDN-resized URLs, EXIF) later
 *  - One product → many images; per-image metadata is per-image
 *
 * For backward compat with the legacy JSON array, Product still keeps a
 * denormalised `images` JSONB column of just the URLs. Migration populates
 * both during the catalog import. Going forward, new product creates
 * should write to ProductImage rows AND keep Product.images in sync; the
 * Product.images array exists for cheap card-rendering reads that don't
 * need full per-image metadata.
 *
 * Cascade rules: ON DELETE CASCADE, if a product is hard-deleted (rare;
 * normally we soft-delete), its images go with it. Soft-delete on Product
 * does NOT remove images (we keep them for forensic / audit purposes).
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_images')]
class ProductImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(type: 'string', length: 500)]
    private string $url;

    #[ORM\Column(name: 'alt_text', type: 'string', length: 255, nullable: true)]
    private ?string $altText = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $width = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $height = null;

    #[ORM\Column(name: 'display_order', type: 'integer')]
    private int $displayOrder = 0;

    #[ORM\Column(name: 'is_primary', type: 'boolean')]
    private bool $isPrimary = false;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(Product $product, string $url)
    {
        $this->product = $product;
        $this->url = $url;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): Product { return $this->product; }
    public function getUrl(): string { return $this->url; }
    public function getAltText(): ?string { return $this->altText; }
    public function getWidth(): ?int { return $this->width; }
    public function getHeight(): ?int { return $this->height; }
    public function getDisplayOrder(): int { return $this->displayOrder; }
    public function isPrimary(): bool { return $this->isPrimary; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }

    public function setAltText(?string $alt): void { $this->altText = $alt; }
    public function setWidth(?int $w): void { $this->width = $w; }
    public function setHeight(?int $h): void { $this->height = $h; }
    public function setDisplayOrder(int $order): void { $this->displayOrder = $order; }
    public function setPrimary(bool $isPrimary): void { $this->isPrimary = $isPrimary; }
}
