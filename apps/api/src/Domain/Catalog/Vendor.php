<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Bayti\Api\Domain\Common\Timestamps;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A marketplace participant who supplies products.
 *
 * M2.1 scope
 * ----------
 * Vendor is a static data record. Admin creates / updates / soft-deletes
 * via /v3/admin/vendors endpoints. Vendor authentication, dashboard,
 * self-onboarding, KYC, settlement details — all M4.
 *
 * Why we need the entity in M2 anyway
 * ------------------------------------
 * Products carry a `vendor_id` FK (Q3 = multi-vendor from day 1).
 * If we deferred Vendor to M4, every product row would either need
 * vendor_id NULL initially (then a giant data migration when M4
 * lands) or a magic sentinel vendor (ugly). Better: create the
 * entity now with just the fields M2 needs.
 *
 * Soft-delete via is_active
 * -------------------------
 * Per D1, we don't hard-delete records that products may reference.
 * A vendor with is_active=false is hidden from public storefront
 * listings but their products may still exist for order-history
 * lookups.
 *
 * Slug uniqueness
 * ---------------
 * Slug is the URL identifier. UNIQUE constraint at DB level so we
 * can never end up with two vendors at /vendors/almas-fashion.
 * Application layer generates slugs from name (kebab-case) with
 * collision-handling that appends -2, -3, etc.
 */
#[ORM\Entity(repositoryClass: VendorRepository::class)]
#[ORM\Table(name: 'vendors')]
#[ORM\HasLifecycleCallbacks]
class Vendor
{
    use Timestamps;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'legacy_vendor_id', type: 'integer', nullable: true, unique: true)]
    private ?int $legacyVendorId = null;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 200)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'logo_url', type: 'string', length: 500, nullable: true)]
    private ?string $logoUrl = null;

    #[ORM\Column(name: 'cover_image_url', type: 'string', length: 500, nullable: true)]
    private ?string $coverImageUrl = null;

    #[ORM\Column(name: 'contact_email', type: 'string', length: 255)]
    private string $contactEmail;

    #[ORM\Column(name: 'contact_phone', type: 'string', length: 20, nullable: true)]
    private ?string $contactPhone = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(name: 'is_verified', type: 'boolean')]
    private bool $isVerified = false;

    /**
     * Commission percentage. Stored as decimal — DB CHECK enforces 0-100.
     * Doctrine maps decimal to string by default to preserve precision;
     * we expose a float accessor for callers who need numeric ops.
     */
    #[ORM\Column(name: 'commission_rate', type: 'decimal', precision: 5, scale: 2)]
    private string $commissionRate = '10.00';

    public function __construct(
        string $slug,
        string $name,
        string $contactEmail,
    ) {
        $this->slug = $slug;
        $this->name = $name;
        $this->contactEmail = $contactEmail;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------

    public function getId(): ?int { return $this->id; }
    public function getLegacyVendorId(): ?int { return $this->legacyVendorId; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getLogoUrl(): ?string { return $this->logoUrl; }
    public function getCoverImageUrl(): ?string { return $this->coverImageUrl; }
    public function getContactEmail(): string { return $this->contactEmail; }
    public function getContactPhone(): ?string { return $this->contactPhone; }
    public function isActive(): bool { return $this->isActive; }
    public function isVerified(): bool { return $this->isVerified; }

    public function getCommissionRate(): float
    {
        return (float) $this->commissionRate;
    }

    // -----------------------------------------------------------------
    // Mutators — all explicit; no public general-purpose setter
    // -----------------------------------------------------------------

    public function setSlug(string $slug): void { $this->slug = $slug; }
    public function setName(string $name): void { $this->name = $name; }
    public function setDescription(?string $description): void { $this->description = $description; }
    public function setLogoUrl(?string $url): void { $this->logoUrl = $url; }
    public function setCoverImageUrl(?string $url): void { $this->coverImageUrl = $url; }
    public function setContactEmail(string $email): void { $this->contactEmail = $email; }
    public function setContactPhone(?string $phone): void { $this->contactPhone = $phone; }
    public function setActive(bool $active): void { $this->isActive = $active; }
    public function setVerified(bool $verified): void { $this->isVerified = $verified; }

    /**
     * Set commission rate. Float input is converted to fixed-precision string
     * for DB storage. Out-of-range values throw — DB CHECK constraint would
     * catch them anyway but we want a clear application-layer error.
     */
    public function setCommissionRate(float $rate): void
    {
        if ($rate < 0.0 || $rate > 100.0) {
            throw new \InvalidArgumentException(
                "Commission rate must be 0-100, got {$rate}",
            );
        }
        // Format with 2 decimals so DB sees consistent values.
        $this->commissionRate = number_format($rate, 2, '.', '');
    }
}
