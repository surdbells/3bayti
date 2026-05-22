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

    /**
     * Vendor lifecycle status values (M3.2.X.6).
     *
     * - STATUS_PENDING:   Newly submitted, not yet admin-approved
     * - STATUS_APPROVED:  Admin has approved; can transact
     * - STATUS_SUSPENDED: Previously approved, then suspended for
     *                     policy reasons; cannot transact
     *
     * See class docblock for full state-machine + relationship
     * to legacy boolean flags.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SUSPENDED = 'suspended';

    public const ALL_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_SUSPENDED,
    ];

    /**
     * Supported locales for vendor email notifications (M3.2.X.7).
     * Mirrors User::SUPPORTED_LOCALES; we reference these via the
     * User class constants throughout (DRY) but redeclare on Vendor
     * for clarity when working with Vendor entities directly.
     */
    public const SUPPORTED_LOCALES = \Bayti\Api\Domain\User\User::SUPPORTED_LOCALES;

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
     * Vendor lifecycle status (M3.2.X.6).
     *
     * Three values: pending, approved, suspended.
     *
     * - pending:   Newly-submitted, not yet admin-approved. Hidden
     *              from all public surfaces. Vendor user can see
     *              their own pending state via the onboarding-status
     *              endpoint.
     * - approved:  Admin has approved. Visible per the existing
     *              is_active / is_featured rules.
     * - suspended: Was approved, then suspended for policy reasons.
     *              Hidden from public surfaces. Vendor user can no
     *              longer manage orders. Re-approval requires admin
     *              action (no self-serve reactivation in M3.2.X.6).
     *
     * Relationship to legacy boolean flags (Q-LegacyFlags = A locked)
     * ----------------------------------------------------------------
     * The status enum is the NEW source of truth for public visibility.
     * The legacy booleans (is_active, is_store_approved, is_verified)
     * remain in place for backwards compatibility:
     *
     *   - is_store_approved tracks status approval atomically:
     *     approve() / reactivate() sets it to true; suspend() leaves
     *     it as-is (a suspended vendor was previously approved).
     *   - is_active continues to mean soft-delete (independent of
     *     status; though backfill correlates them).
     *   - is_verified is purely cosmetic (admin-attested badge).
     *
     * State transitions are performed via the approve() / suspend() /
     * reactivate() methods which validate the legal transition graph
     * and update statusChangedAt + (for approvals) is_store_approved
     * atomically. Direct property mutation should be avoided.
     */
    #[ORM\Column(name: 'status', type: 'string', length: 16, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    /**
     * Timestamp of the most recent status transition. Null for
     * vendors that have never transitioned (i.e. still in their
     * initial pending state).
     *
     * Used for forensic queries ("when was this vendor approved?")
     * without needing to grep audit logs. Audit logs remain the
     * canonical history; this column is convenience.
     */
    #[ORM\Column(name: 'status_changed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $statusChangedAt = null;

    /**
     * Free-text reason for the most recent status transition.
     * Populated when admin provides one during approve/suspend/
     * reactivate; null otherwise.
     *
     * Stored as TEXT (not enum) — reasons are operational, not
     * categorical. Examples: "Quality complaints from 5 customers",
     * "Initial approval after KYC review".
     */
    #[ORM\Column(name: 'status_reason', type: 'text', nullable: true)]
    private ?string $statusReason = null;

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

    /**
     * Vendor's preferred locale for email notifications (M3.2.X.7).
     *
     * - `en` → English (current default, also used when null)
     * - `ar` → Arabic
     * - null → no preference; falls back to English per
     *          Q-FallbackBehavior = A locked
     *
     * Distinct from the OWNER user's preferredLocale — a single
     * vendor may have multiple staff members receiving notifications,
     * and the locale should match the business's preference rather
     * than any one staff member's. Q-VendorAdminLocale = A locked.
     */
    #[ORM\Column(name: 'preferred_locale', type: 'string', length: 8, nullable: true)]
    private ?string $preferredLocale = null;

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

    /**
     * Vendor notification preference flags (M3.4-B).
     * Stored as JSON so new preference keys can be added without
     * ALTER TABLE. Null = all defaults (treat as all-true on read).
     *
     * @var array<string,bool>|null
     */
    #[ORM\Column(name: 'notification_prefs', type: 'json', nullable: true)]
    private ?array $notificationPrefs = null;

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

    // -----------------------------------------------------------------
    // Lifecycle status (M3.2.X.6)
    // -----------------------------------------------------------------

    public function getStatus(): string { return $this->status; }
    public function getStatusChangedAt(): ?\DateTimeImmutable { return $this->statusChangedAt; }
    public function getStatusReason(): ?string { return $this->statusReason; }

    /** Convenience predicate: is the vendor in pending state? */
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }

    /** Convenience predicate: is the vendor in approved state? */
    public function isApproved(): bool { return $this->status === self::STATUS_APPROVED; }

    /** Convenience predicate: is the vendor in suspended state? */
    public function isSuspended(): bool { return $this->status === self::STATUS_SUSPENDED; }

    /**
     * Transition this vendor to STATUS_APPROVED.
     *
     * Valid from: STATUS_PENDING (initial admin approval),
     *             STATUS_SUSPENDED (reactivation — though
     *             reactivate() is the semantic alias preferred
     *             for that case for clearer audit semantics).
     *
     * Invalid from: STATUS_APPROVED (already approved; no-op
     *               raises to surface unintentional duplicate calls).
     *
     * Side effects (atomic):
     *   - status → STATUS_APPROVED
     *   - statusChangedAt → now (UTC)
     *   - statusReason → $reason (overwrites any previous value)
     *   - is_store_approved → true (legacy flag stays in sync per
     *     Q-LegacyFlags = A)
     *
     * @throws \InvalidArgumentException on invalid transition
     */
    public function approve(?string $reason = null): void
    {
        if ($this->status === self::STATUS_APPROVED) {
            throw new \InvalidArgumentException(
                'Cannot approve vendor: already in approved state. '
                . 'Use reactivate() for suspended → approved transitions if '
                . 'distinguishing audit semantics matter.',
            );
        }
        $this->transitionTo(self::STATUS_APPROVED, $reason);
        $this->isStoreApproved = true;
    }

    /**
     * Transition this vendor to STATUS_SUSPENDED.
     *
     * Valid from: STATUS_APPROVED only — a vendor must have been
     *             approved before being suspended. Suspending a
     *             pending vendor would be operationally confusing
     *             (admin should simply not approve them).
     *
     * Invalid from: STATUS_PENDING (use admin rejection path; not
     *               in M3.2.X.6 scope), STATUS_SUSPENDED (already
     *               suspended).
     *
     * Side effects (atomic):
     *   - status → STATUS_SUSPENDED
     *   - statusChangedAt → now (UTC)
     *   - statusReason → $reason
     *
     * is_store_approved is NOT toggled by suspension — the vendor
     * WAS approved historically; the suspension is the more recent
     * operational state.
     *
     * @throws \InvalidArgumentException on invalid transition
     */
    public function suspend(?string $reason = null): void
    {
        if ($this->status !== self::STATUS_APPROVED) {
            throw new \InvalidArgumentException(
                "Cannot suspend vendor from {$this->status} state. "
                . 'Vendors must be approved before they can be suspended.',
            );
        }
        $this->transitionTo(self::STATUS_SUSPENDED, $reason);
    }

    /**
     * Transition this vendor from STATUS_SUSPENDED back to
     * STATUS_APPROVED. Semantically distinct from approve() so
     * audit trails can differentiate "initial approval" from
     * "reactivation after suspension".
     *
     * Valid from: STATUS_SUSPENDED only.
     *
     * Side effects (atomic):
     *   - status → STATUS_APPROVED
     *   - statusChangedAt → now (UTC)
     *   - statusReason → $reason
     *   - is_store_approved → true (re-set in case it was somehow
     *     toggled off via direct property mutation)
     *
     * @throws \InvalidArgumentException on invalid transition
     */
    public function reactivate(?string $reason = null): void
    {
        if ($this->status !== self::STATUS_SUSPENDED) {
            throw new \InvalidArgumentException(
                "Cannot reactivate vendor from {$this->status} state. "
                . 'Reactivation is only valid from suspended state.',
            );
        }
        $this->transitionTo(self::STATUS_APPROVED, $reason);
        $this->isStoreApproved = true;
    }

    /**
     * Internal: perform the state mutation + timestamp/reason update.
     * Called only by the three public transition methods above.
     */
    private function transitionTo(string $newStatus, ?string $reason): void
    {
        $this->status = $newStatus;
        $this->statusChangedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->statusReason = $reason;
    }

    public function getOwnerUser(): ?\Bayti\Api\Domain\User\User { return $this->ownerUser; }
    public function setOwnerUser(?\Bayti\Api\Domain\User\User $user): void { $this->ownerUser = $user; }

    public function getLegalName(): ?string { return $this->legalName; }
    public function setLegalName(?string $name): void { $this->legalName = $name; }

    // -----------------------------------------------------------------
    // Preferred locale (M3.2.X.7)
    // -----------------------------------------------------------------

    public function getPreferredLocale(): ?string
    {
        return $this->preferredLocale;
    }

    /**
     * Set the vendor's preferred locale for email notifications.
     *
     * Pass null to clear the preference (falls back to English at
     * send time per Q-FallbackBehavior = A).
     *
     * @throws \InvalidArgumentException if $locale is non-null and
     *         not in self::SUPPORTED_LOCALES
     */
    public function setPreferredLocale(?string $locale): void
    {
        if ($locale !== null && !in_array($locale, self::SUPPORTED_LOCALES, true)) {
            throw new \InvalidArgumentException(
                "Invalid locale '{$locale}'. Supported: "
                . implode(', ', self::SUPPORTED_LOCALES),
            );
        }
        $this->preferredLocale = $locale;
    }

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

    /** @return array<string,bool> */
    public function getNotificationPrefs(): array
    {
        return $this->notificationPrefs ?? [];
    }

    /** @param array<string,bool> $prefs */
    public function setNotificationPrefs(array $prefs): void
    {
        $this->notificationPrefs = $prefs;
    }

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
