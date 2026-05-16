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
     * Approval status separate from is_active. is_active=true means
     * the vendor record exists (not soft-deleted). is_store_approved=true
     * means admin has reviewed and approved them. Legacy `store_approved`
     * tinyint maps here.
     */
    #[ORM\Column(name: 'is_store_approved', type: 'boolean')]
    private bool $isStoreApproved = false;

    /**
     * Curation flag for the home-page Designer Spotlight surface
     * (apps/web) and any other "featured vendors" UI. Admin-managed
     * via PUT /v3/admin/vendors/{id} with `is_featured: true`.
     *
     * M3.2.X.2 introduced this column. Defaults to false; existing
     * vendors retain the default until admin explicitly flags them.
     *
     * Filtering rule on the public endpoint:
     *   is_active = true AND is_featured = true
     * — a vendor flagged featured but later soft-deleted must NOT
     * appear on the Spotlight surface.
     */
    #[ORM\Column(name: 'is_featured', type: 'boolean')]
    private bool $isFeatured = false;

    /**
     * Owner User — the user record with is_vendor=1 that owns this store.
     * Optional because: (a) old M2.1 vendors have no owner since they
     * predate this field; (b) admin-created vendors don't always have a
     * user account at creation time.
     */
    #[ORM\ManyToOne(targetEntity: \Bayti\Api\Domain\User\User::class)]
    #[ORM\JoinColumn(name: 'owner_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?\Bayti\Api\Domain\User\User $ownerUser = null;

    // ---- store identity (legacy store_* columns) ----

    #[ORM\Column(name: 'legal_name', type: 'string', length: 255, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(name: 'store_email', type: 'string', length: 255, nullable: true)]
    private ?string $storeEmail = null;

    #[ORM\Column(name: 'store_phone_raw', type: 'text', nullable: true)]
    private ?string $storePhoneRaw = null;

    #[ORM\Column(name: 'store_address', type: 'string', length: 500, nullable: true)]
    private ?string $storeAddress = null;

    // ---- bank / payout ----

    #[ORM\Column(name: 'store_bank_name', type: 'string', length: 255, nullable: true)]
    private ?string $storeBankName = null;

    #[ORM\Column(name: 'store_bank_account_name', type: 'string', length: 255, nullable: true)]
    private ?string $storeBankAccountName = null;

    #[ORM\Column(name: 'store_bank_account_number', type: 'string', length: 40, nullable: true)]
    private ?string $storeBankAccountNumber = null;

    // ---- tax compliance (UAE-specific) ----

    #[ORM\Column(name: 'vat_status', type: 'string', length: 50, nullable: true)]
    private ?string $vatStatus = null;

    #[ORM\Column(name: 'trade_license_number', type: 'string', length: 255, nullable: true)]
    private ?string $tradeLicenseNumber = null;

    #[ORM\Column(name: 'licensing_authority', type: 'string', length: 50, nullable: true)]
    private ?string $licensingAuthority = null;

    #[ORM\Column(name: 'tax_registration_number', type: 'string', length: 50, nullable: true)]
    private ?string $taxRegistrationNumber = null;

    #[ORM\Column(name: 'vat_registration_effective_date', type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $vatRegistrationEffectiveDate = null;

    #[ORM\Column(name: 'registered_tax_address', type: 'string', length: 500, nullable: true)]
    private ?string $registeredTaxAddress = null;

    #[ORM\Column(name: 'tax_contact_email', type: 'string', length: 255, nullable: true)]
    private ?string $taxContactEmail = null;

    /**
     * Legacy logo as a data: URL preserved verbatim. Image migration (M5)
     * extracts these to Flysystem and populates logo_url with a CDN URL.
     * For demo, this field sits dormant — public APIs surface logo_url
     * (which is null for migrated vendors) OR fall back to a placeholder.
     */
    #[ORM\Column(name: 'legacy_logo_data_url', type: 'text', nullable: true)]
    private ?string $legacyLogoDataUrl = null;

    #[ORM\Column(name: 'legacy_cover_data_url', type: 'text', nullable: true)]
    private ?string $legacyCoverDataUrl = null;

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

    // ----- legacy store_* getters/setters -----

    public function isStoreApproved(): bool { return $this->isStoreApproved; }
    public function setStoreApproved(bool $approved): void { $this->isStoreApproved = $approved; }

    public function isFeatured(): bool { return $this->isFeatured; }
    public function setFeatured(bool $featured): void { $this->isFeatured = $featured; }

    public function getOwnerUser(): ?\Bayti\Api\Domain\User\User { return $this->ownerUser; }
    public function setOwnerUser(?\Bayti\Api\Domain\User\User $user): void { $this->ownerUser = $user; }

    public function getLegalName(): ?string { return $this->legalName; }
    public function setLegalName(?string $name): void { $this->legalName = $name; }

    public function getStoreEmail(): ?string { return $this->storeEmail; }
    public function setStoreEmail(?string $email): void { $this->storeEmail = $email; }

    public function getStorePhoneRaw(): ?string { return $this->storePhoneRaw; }
    public function setStorePhoneRaw(?string $phone): void { $this->storePhoneRaw = $phone; }

    public function getStoreAddress(): ?string { return $this->storeAddress; }
    public function setStoreAddress(?string $address): void { $this->storeAddress = $address; }

    public function getStoreBankName(): ?string { return $this->storeBankName; }
    public function setStoreBankName(?string $name): void { $this->storeBankName = $name; }

    public function getStoreBankAccountName(): ?string { return $this->storeBankAccountName; }
    public function setStoreBankAccountName(?string $name): void { $this->storeBankAccountName = $name; }

    public function getStoreBankAccountNumber(): ?string { return $this->storeBankAccountNumber; }
    public function setStoreBankAccountNumber(?string $num): void { $this->storeBankAccountNumber = $num; }

    public function getVatStatus(): ?string { return $this->vatStatus; }
    public function setVatStatus(?string $status): void { $this->vatStatus = $status; }

    public function getTradeLicenseNumber(): ?string { return $this->tradeLicenseNumber; }
    public function setTradeLicenseNumber(?string $num): void { $this->tradeLicenseNumber = $num; }

    public function getLicensingAuthority(): ?string { return $this->licensingAuthority; }
    public function setLicensingAuthority(?string $auth): void { $this->licensingAuthority = $auth; }

    public function getTaxRegistrationNumber(): ?string { return $this->taxRegistrationNumber; }
    public function setTaxRegistrationNumber(?string $num): void { $this->taxRegistrationNumber = $num; }

    public function getVatRegistrationEffectiveDate(): ?DateTimeImmutable { return $this->vatRegistrationEffectiveDate; }
    public function setVatRegistrationEffectiveDate(?DateTimeImmutable $date): void { $this->vatRegistrationEffectiveDate = $date; }

    public function getRegisteredTaxAddress(): ?string { return $this->registeredTaxAddress; }
    public function setRegisteredTaxAddress(?string $addr): void { $this->registeredTaxAddress = $addr; }

    public function getTaxContactEmail(): ?string { return $this->taxContactEmail; }
    public function setTaxContactEmail(?string $email): void { $this->taxContactEmail = $email; }

    public function getLegacyLogoDataUrl(): ?string { return $this->legacyLogoDataUrl; }
    public function setLegacyLogoDataUrl(?string $dataUrl): void { $this->legacyLogoDataUrl = $dataUrl; }

    public function getLegacyCoverDataUrl(): ?string { return $this->legacyCoverDataUrl; }
    public function setLegacyCoverDataUrl(?string $dataUrl): void { $this->legacyCoverDataUrl = $dataUrl; }

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
