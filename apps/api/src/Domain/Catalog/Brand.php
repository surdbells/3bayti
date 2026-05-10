<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Bayti\Api\Domain\Common\Timestamps;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A product brand — simple lookup table.
 *
 * Brand is intentionally lightweight. Many catalog brands are
 * "Nike, Adidas, etc." — just a name, a slug, a logo. No category
 * relationships, no per-brand configuration.
 *
 * Optional FK from products
 * -------------------------
 * Products have `brand_id BIGINT NULL`. Not every product has a
 * brand: made-to-order custom abayas, generic kitchenware, vendor's
 * own private label.
 *
 * Soft-delete via is_active (D1).
 */
#[ORM\Entity(repositoryClass: BrandRepository::class)]
#[ORM\Table(name: 'brands')]
#[ORM\HasLifecycleCallbacks]
class Brand
{
    use Timestamps;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 150)]
    private string $name;

    #[ORM\Column(name: 'logo_url', type: 'string', length: 500, nullable: true)]
    private ?string $logoUrl = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    public function __construct(string $slug, string $name)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): string { return $this->name; }
    public function getLogoUrl(): ?string { return $this->logoUrl; }
    public function isActive(): bool { return $this->isActive; }

    public function setSlug(string $slug): void { $this->slug = $slug; }
    public function setName(string $name): void { $this->name = $name; }
    public function setLogoUrl(?string $url): void { $this->logoUrl = $url; }
    public function setActive(bool $active): void { $this->isActive = $active; }
}
