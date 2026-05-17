<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use Bayti\Api\Domain\Common\Timestamps;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Platform user — the unified record that covers customers, vendors,
 * and admins. Roles are flag-driven (is_customer, is_vendor, is_admin,
 * is_finance, is_support, is_sub_admin) per the existing legacy schema.
 * One person can hold multiple roles simultaneously: a customer who
 * later opens a store keeps their customer history.
 *
 * Schema lineage
 * --------------
 * Mirrors the legacy MySQL `users` table. We carry forward every
 * column the legacy code reads or writes, plus new columns M1 needs
 * (refresh-token-rotation tracking, password change audit, soft
 * delete, last-login).
 *
 * Naming changes from legacy → new:
 *   - `_sub_admin` → `is_sub_admin`  (PHP property naming consistency)
 *   - `password` (the hash) → `password_hash` (clarifies it's never plaintext)
 *
 * Password hashes
 * ---------------
 * Legacy backend uses PHP's `password_hash()` / `password_verify()`
 * with the default bcrypt algorithm. These hashes are portable —
 * Doctrine can store them as VARCHAR(255) and verify with the same
 * password_verify() call, so existing mobile-app users will be able
 * to log into the new web without resetting their passwords.
 *
 * Soft delete
 * -----------
 * `deleted_at` is set when an account is deactivated rather than
 * physically removed. Orders, reviews, and other historical refs
 * stay valid. Repository queries filter `deleted_at IS NULL` by
 * default; an explicit method exposes deleted users for admin
 * recovery flows.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\Index(columns: ['email'], name: 'idx_users_email')]
#[ORM\Index(columns: ['phone'], name: 'idx_users_phone')]
#[ORM\Index(columns: ['legacy_user_id'], name: 'idx_users_legacy_id')]
#[ORM\HasLifecycleCallbacks]
class User
{
    use Timestamps;

    /**
     * Supported locales for email notifications (M3.2.X.7).
     *
     * Stored as BCP-47 short tags (Q-LocaleValues = A locked).
     * Used by OrderEmailTemplateRenderer + LocaleResolver for
     * routing notifications.
     */
    public const LOCALE_EN = 'en';
    public const LOCALE_AR = 'ar';

    public const SUPPORTED_LOCALES = [
        self::LOCALE_EN,
        self::LOCALE_AR,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /**
     * Legacy MySQL `users.user_id`. Populated for users migrated from
     * the legacy backend; null for users created natively in v3.
     * Indexed because CDC sync (M1.8) looks up the new-side row by
     * legacy id when applying writes from the legacy side.
     */
    #[ORM\Column(name: 'legacy_user_id', type: 'integer', nullable: true, unique: true)]
    private ?int $legacyUserId = null;

    // -------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $lastName = null;

    /**
     * Primary identifier. Unique across the platform. Required for
     * login (per Decision A.6.4 — email-first identifier).
     */
    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $email;

    /**
     * Phone number, E.164-ish format (with country code). Used for
     * OTP delivery during registration confirmation and password reset.
     * Unique across the platform — no two accounts share a phone.
     */
    /**
     * Phone — nullable + non-unique to preserve legacy data fidelity.
     *
     * v3 originally enforced phone NOT NULL UNIQUE. Migration relaxed
     * both constraints (see Version20260512000003) because:
     *  - 4 legacy users have NULL phone (admin/test accounts)
     *  - 69 legacy users share phones with another user (family accounts)
     *
     * Future tightening is application-layer-only: new signups must
     * supply a phone, migrated rows may stay legacy-shaped.
     */
    #[ORM\Column(type: 'string', length: 25, nullable: true)]
    private ?string $phone = null;

    /**
     * ISO 3166-1 alpha-2 country code (e.g. 'AE', 'SA'). Stored
     * separately from phone so we can prefix-strip when displaying
     * and country-format when sending SMS.
     */
    #[ORM\Column(name: 'country_code', type: 'string', length: 2)]
    private string $countryCode = 'AE';

    // -------------------------------------------------------------------
    // Profile fields (M1.7.0+) — additive, mostly nullable
    // -------------------------------------------------------------------

    /**
     * User's self-reported gender. NULL means "never asked";
     * 'prefer_not_to_say' means "asked and declined."
     *
     * Stored as VARCHAR with a CHECK constraint at the DB level
     * (see migration Version20260509000001). The Gender enum class
     * provides the typed PHP API, but Doctrine maps it back to string.
     *
     * Why string column not enumType
     * ------------------------------
     * Doctrine 3 supports `enumType: Gender::class` for native PHP
     * enums. We deliberately don't use it here because:
     *   1. The Gender enum lives in our domain layer; coupling
     *      Doctrine config to it makes the entity harder to test
     *      with raw strings.
     *   2. Gender::fromStringOrNull() handles NULL and unknown values
     *      gracefully; Doctrine's enumType would throw on either.
     */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $gender = null;

    /**
     * Birth date. DATE type — no time component, no timezone.
     * Used for birthday promotions (M3) and age-gated products.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $dob = null;

    /**
     * BCP 47 locale identifier — 'en', 'ar', 'ar-AE', etc. Used to:
     *   - Localise transactional emails (M3)
     *   - Pick SMS templates (M1.3 already supports per-locale)
     *   - Inform UI direction hints (LTR vs RTL)
     *
     * Default 'en' for users created before M1.7.0 (backfilled by
     * the migration's DEFAULT clause) and any future user who doesn't
     * specify one at registration.
     */
    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'en'])]
    private string $locale = 'en';

    /**
     * IANA timezone identifier — 'Asia/Dubai', 'Europe/London', etc.
     * Used to display order timestamps in the user's local time and
     * for "ordered N hours ago" relative formatting.
     *
     * Default 'Asia/Dubai' to match our primary launch market.
     */
    #[ORM\Column(type: 'string', length: 50, options: ['default' => 'Asia/Dubai'])]
    private string $timezone = 'Asia/Dubai';

    // -------------------------------------------------------------------
    // Auth fields
    // -------------------------------------------------------------------

    /**
     * Bcrypt hash from PHP's password_hash() with PASSWORD_DEFAULT.
     * Never plaintext. Length 255 to accommodate future rehash to
     * argon2id without a schema change.
     */
    #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
    private string $passwordHash;

    /**
     * Audit field — when did the user last change their password?
     * Used by:
     *   - "Change password" notifications ("password last changed 90 days ago")
     *   - Force-rotate flow (admin can require a user to reset on next login)
     *   - Refresh-token invalidation (all refresh tokens issued before this
     *     timestamp are forced to refresh + reauthenticate)
     */
    #[ORM\Column(name: 'password_changed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $passwordChangedAt = null;

    // -------------------------------------------------------------------
    // Vendor-specific fields (only populated when is_vendor = true)
    // -------------------------------------------------------------------

    #[ORM\Column(name: 'store_legal_name', type: 'string', length: 255, nullable: true)]
    private ?string $storeLegalName = null;

    #[ORM\Column(name: 'trade_license_number', type: 'string', length: 100, nullable: true)]
    private ?string $tradeLicenseNumber = null;

    // -------------------------------------------------------------------
    // Role flags (per legacy schema; multiple can be true simultaneously)
    // -------------------------------------------------------------------

    #[ORM\Column(name: 'is_customer', type: 'boolean', options: ['default' => true])]
    private bool $isCustomer = true;

    #[ORM\Column(name: 'is_vendor', type: 'boolean', options: ['default' => false])]
    private bool $isVendor = false;

    #[ORM\Column(name: 'is_admin', type: 'boolean', options: ['default' => false])]
    private bool $isAdmin = false;

    #[ORM\Column(name: 'is_finance', type: 'boolean', options: ['default' => false])]
    private bool $isFinance = false;

    #[ORM\Column(name: 'is_support', type: 'boolean', options: ['default' => false])]
    private bool $isSupport = false;

    /**
     * "Sub-admin" — admin with reduced scope (legacy concept).
     * Renamed from `_sub_admin` for consistency with other booleans.
     */
    #[ORM\Column(name: 'is_sub_admin', type: 'boolean', options: ['default' => false])]
    private bool $isSubAdmin = false;

    // -------------------------------------------------------------------
    // Vendor lifecycle (only meaningful when is_vendor = true)
    // -------------------------------------------------------------------

    /**
     * Has this vendor been approved by an admin?
     * False during the application-pending state. Customers cannot
     * see unapproved vendors in catalog browse.
     */
    #[ORM\Column(name: 'is_store_approved', type: 'boolean', options: ['default' => false])]
    private bool $isStoreApproved = false;

    /**
     * Is the vendor's store currently visible/active?
     * Vendors can self-toggle (vacation mode); admins can force
     * deactivate. Distinct from approval — an approved store can
     * still be inactive.
     */
    #[ORM\Column(name: 'is_store_active', type: 'boolean', options: ['default' => false])]
    private bool $isStoreActive = false;

    // -------------------------------------------------------------------
    // Account lifecycle
    // -------------------------------------------------------------------

    /**
     * Has the user verified their phone number via OTP?
     * False during the registration-pending state. Users cannot
     * place orders or perform sensitive actions until verified.
     */
    #[ORM\Column(name: 'is_phone_verified', type: 'boolean', options: ['default' => false])]
    private bool $isPhoneVerified = false;

    /**
     * Optional: has the user verified their email?
     * Not currently required for login but useful for trust signals
     * and a future email-link password reset path.
     */
    #[ORM\Column(name: 'is_email_verified', type: 'boolean', options: ['default' => false])]
    private bool $isEmailVerified = false;

    /**
     * Master active flag. Account-level disable (e.g. fraud,
     * policy violation, user-requested deactivation). Distinct from
     * soft-delete (`deleted_at`) — an inactive account can be
     * reactivated by an admin without restoring data, while a deleted
     * account requires a separate restore flow.
     */
    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    /**
     * Has the user enabled 2FA? Currently a placeholder — 2FA
     * implementation is post-M1 (M5 hardening). Carried forward from
     * legacy schema so column-mapping pgloader migration doesn't
     * complain about missing fields.
     */
    #[ORM\Column(name: 'is_2fa', type: 'boolean', options: ['default' => false])]
    private bool $is2fa = false;

    // -------------------------------------------------------------------
    // Audit fields
    // -------------------------------------------------------------------

    #[ORM\Column(name: 'last_login_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $lastLoginAt = null;

    /**
     * IP address of last login attempt (success or failure). Null
     * for users who haven't logged in via v3 yet. Used by the
     * "new device login" alert email.
     */
    #[ORM\Column(name: 'last_login_ip', type: 'string', length: 45, nullable: true)]
    private ?string $lastLoginIp = null;

    /**
     * Soft delete. Null = active or inactive (see is_active);
     * non-null = deleted, queries should hide unless explicitly asked.
     */
    #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    // -------------------------------------------------------------------
    // Relationships (lazy-loaded by default)
    // -------------------------------------------------------------------

    /**
     * @var Collection<int, Address>
     */
    #[ORM\OneToMany(targetEntity: Address::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $addresses;

    /**
     * @var Collection<int, Measurement>
     */
    #[ORM\OneToMany(targetEntity: Measurement::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $measurements;

    /**
     * @var Collection<int, RefreshToken>
     */
    #[ORM\OneToMany(targetEntity: RefreshToken::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $refreshTokens;

    // -------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------

    public function __construct(string $email, ?string $phone, string $passwordHash, string $countryCode = 'AE')
    {
        $this->email = strtolower(trim($email));
        $this->phone = $phone !== null && $phone !== '' ? $phone : null;
        $this->passwordHash = $passwordHash;
        $this->countryCode = strtoupper($countryCode);

        $this->addresses = new ArrayCollection();
        $this->measurements = new ArrayCollection();
        $this->refreshTokens = new ArrayCollection();

        $this->initTimestamps();
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->touchUpdatedAt();
    }

    // -------------------------------------------------------------------
    // Public accessors
    // -------------------------------------------------------------------

    public function getId(): ?int                  { return $this->id; }
    public function getLegacyUserId(): ?int        { return $this->legacyUserId; }
    public function getEmail(): string             { return $this->email; }
    public function getPhone(): ?string            { return $this->phone; }
    public function getCountryCode(): string       { return $this->countryCode; }
    public function getFirstName(): ?string        { return $this->firstName; }
    public function getLastName(): ?string         { return $this->lastName; }
    public function getGender(): ?string           { return $this->gender; }
    public function getDob(): ?DateTimeImmutable   { return $this->dob; }
    public function getLocale(): string            { return $this->locale; }
    public function getTimezone(): string          { return $this->timezone; }
    public function getStoreLegalName(): ?string   { return $this->storeLegalName; }
    public function getTradeLicenseNumber(): ?string { return $this->tradeLicenseNumber; }
    public function getPasswordHash(): string      { return $this->passwordHash; }
    public function getPasswordChangedAt(): ?DateTimeImmutable { return $this->passwordChangedAt; }
    public function getLastLoginAt(): ?DateTimeImmutable       { return $this->lastLoginAt; }
    public function getLastLoginIp(): ?string                  { return $this->lastLoginIp; }
    public function getDeletedAt(): ?DateTimeImmutable         { return $this->deletedAt; }

    public function isCustomer(): bool       { return $this->isCustomer; }
    public function isVendor(): bool         { return $this->isVendor; }
    public function isAdmin(): bool          { return $this->isAdmin; }
    public function isFinance(): bool        { return $this->isFinance; }
    public function isSupport(): bool        { return $this->isSupport; }
    public function isSubAdmin(): bool       { return $this->isSubAdmin; }
    public function isStoreApproved(): bool  { return $this->isStoreApproved; }
    public function isStoreActive(): bool    { return $this->isStoreActive; }
    public function isPhoneVerified(): bool  { return $this->isPhoneVerified; }
    public function isEmailVerified(): bool  { return $this->isEmailVerified; }
    public function isActive(): bool         { return $this->isActive; }
    public function is2faEnabled(): bool     { return $this->is2fa; }
    public function isDeleted(): bool        { return $this->deletedAt !== null; }

    /** @return Collection<int, Address> */
    public function getAddresses(): Collection { return $this->addresses; }

    /** @return Collection<int, Measurement> */
    public function getMeasurements(): Collection { return $this->measurements; }

    /** @return Collection<int, RefreshToken> */
    public function getRefreshTokens(): Collection { return $this->refreshTokens; }

    // -------------------------------------------------------------------
    // Mutators (deliberately narrow — no public setId, no setEmail
    // until we've thought through what email-change requires)
    // -------------------------------------------------------------------

    public function setName(?string $firstName, ?string $lastName): void
    {
        $this->firstName = $firstName !== null ? trim($firstName) : null;
        $this->lastName  = $lastName  !== null ? trim($lastName)  : null;
    }

    /**
     * Set gender. Accepts a Gender enum or null. Callers that have
     * a raw string from user input should validate via
     * Gender::fromStringOrNull() first; this setter trusts its input.
     *
     * Why ?Gender, not ?string
     * ------------------------
     * Stops typos from reaching the DB. The CHECK constraint catches
     * them too, but better to fail at the validator/controller boundary
     * than at flush() time (where the error is just a Postgres exception).
     */
    public function setGender(?Gender $gender): void
    {
        $this->gender = $gender?->value;
    }

    public function setDob(?DateTimeImmutable $dob): void
    {
        // Normalise to midnight in case caller passed a time component.
        // DATE columns drop the time anyway but we want consistent
        // PHP-side equality (two birthdays read back as identical).
        //
        // setTime(0, 0, 0) is the unambiguous way to do this.
        // (Earlier we tried createFromFormat('Y-m-d', ...) which applies
        // the CURRENT time when no time format token is given — bit us
        // in CI.)
        $this->dob = $dob?->setTime(0, 0, 0);
    }

    /**
     * Set the user's preferred locale (BCP 47 like 'en' or 'ar-AE').
     * Validation of allowed values is at the controller layer; this
     * setter trusts its input but trims whitespace.
     */
    public function setLocale(string $locale): void
    {
        $this->locale = trim($locale);
    }

    /**
     * Set the user's preferred timezone (IANA identifier like
     * 'Asia/Dubai'). Validation against known timezones happens at
     * the controller layer.
     */
    public function setTimezone(string $timezone): void
    {
        $this->timezone = trim($timezone);
    }

    public function setPasswordHash(string $hash): void
    {
        $this->passwordHash = $hash;
        $this->passwordChangedAt = new DateTimeImmutable();
    }

    public function markPhoneVerified(): void
    {
        $this->isPhoneVerified = true;
    }

    public function markEmailVerified(): void
    {
        $this->isEmailVerified = true;
    }

    public function recordLogin(?string $ip): void
    {
        $this->lastLoginAt = new DateTimeImmutable();
        $this->lastLoginIp = $ip;
    }

    public function deactivate(): void { $this->isActive = false; }
    public function reactivate(): void { $this->isActive = true; }

    public function softDelete(): void { $this->deletedAt = new DateTimeImmutable(); }
    public function restore(): void    { $this->deletedAt = null; }

    public function setRoles(
        ?bool $customer = null,
        ?bool $vendor = null,
        ?bool $admin = null,
        ?bool $finance = null,
        ?bool $support = null,
        ?bool $subAdmin = null,
    ): void {
        if ($customer !== null) { $this->isCustomer = $customer; }
        if ($vendor   !== null) { $this->isVendor   = $vendor; }
        if ($admin    !== null) { $this->isAdmin    = $admin; }
        if ($finance  !== null) { $this->isFinance  = $finance; }
        if ($support  !== null) { $this->isSupport  = $support; }
        if ($subAdmin !== null) { $this->isSubAdmin = $subAdmin; }
    }

    public function setStoreState(?bool $approved = null, ?bool $active = null): void
    {
        if ($approved !== null) { $this->isStoreApproved = $approved; }
        if ($active   !== null) { $this->isStoreActive   = $active; }
    }

    public function setStoreInfo(?string $legalName, ?string $licenseNumber): void
    {
        $this->storeLegalName = $legalName !== null ? trim($legalName) : null;
        $this->tradeLicenseNumber = $licenseNumber !== null ? trim($licenseNumber) : null;
    }

    /**
     * For the migration/CDC layer only — sets the legacy id once.
     * Once set, it doesn't change.
     */
    public function bindLegacyId(int $legacyUserId): void
    {
        if ($this->legacyUserId !== null) {
            throw new \LogicException('legacyUserId is already set; cannot rebind');
        }
        $this->legacyUserId = $legacyUserId;
    }
}
