<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Promo;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * A promotional discount code in the catalog (M3.2.X.8).
 *
 * Background
 * ==========
 * Before this phase, the checkout endpoint accepted a client-supplied
 * `discount` decimal which the server trusted (a security gap flagged
 * in InitiateCheckoutController:88-91). M3.2.X.8 closes that gap with
 * a server-authoritative promo code engine: admin defines codes here,
 * customers redeem by name, server computes the discount amount.
 *
 * Two discount types are supported in v1 (Q-DiscountTypes = A locked):
 *
 *   - DISCOUNT_TYPE_PERCENTAGE: discount_value is a 0-100 percentage
 *     applied to cart subtotal. Optional max_discount_amount caps the
 *     resulting amount in absolute money terms.
 *   - DISCOUNT_TYPE_FIXED_AMOUNT: discount_value is a fixed money
 *     amount in `currency` (defaults to AED).
 *
 * Code normalization
 * ------------------
 * Codes are stored in UPPER form (e.g. 'WELCOME10'). The constructor
 * normalizes incoming code strings via mb_strtoupper(trim(...)); the
 * DB layer additionally enforces uniqueness on UPPER(code) via a
 * functional index. Customers may type codes in any case (and with
 * leading/trailing whitespace); the resolver normalizes before lookup.
 *
 * Lifecycle
 * ---------
 * is_active boolean acts as soft-delete: admin "deletes" a code by
 * setting is_active=false, which preserves the FK from any historical
 * promo_redemptions rows. Codes with zero redemptions may be hard-
 * deleted by admin as a cleanup affordance (no FK rows to preserve).
 *
 * Time-bounding
 * -------------
 * valid_from / valid_until are nullable. Either bound may be omitted
 * to leave that side unbounded. The resolver checks `now()` against
 * both bounds at redemption time.
 *
 * Usage limits
 * ------------
 * usage_limit_global caps total redemptions across all users. Per-user
 * caps live on usage_limit_per_user. NULL on either side means no cap.
 * The resolver counts `promo_redemptions` rows to enforce.
 *
 * Q-Applicability = A locked: no per-scope (category/vendor/product)
 * targeting in v1. The schema is intentionally narrower than the
 * decision table considered; scope columns are not added until at
 * least one of those alternatives is approved in a future phase.
 *
 * Q-MinOrderSubtotal = A locked: min_subtotal nullable column gates
 * applicability on cart.subtotal before discount is applied.
 */
#[ORM\Entity(repositoryClass: PromoCodeRepository::class)]
#[ORM\Table(name: 'promo_codes')]
#[ORM\HasLifecycleCallbacks]
class PromoCode
{
    /**
     * Discount type taxonomy. Stored as VARCHAR + DB CHECK constraint
     * (not Postgres enum) so a new type can be added without ALTER TYPE.
     */
    public const DISCOUNT_TYPE_PERCENTAGE = 'percentage';
    public const DISCOUNT_TYPE_FIXED_AMOUNT = 'fixed_amount';

    public const ALL_DISCOUNT_TYPES = [
        self::DISCOUNT_TYPE_PERCENTAGE,
        self::DISCOUNT_TYPE_FIXED_AMOUNT,
    ];

    /**
     * Maximum length of a promo code. Picked to comfortably fit
     * marketing-friendly codes ('WELCOME10', 'SUMMER25', 'VIP-2026')
     * with headroom for tracking suffixes.
     */
    public const CODE_MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    /**
     * Code text in normalized UPPER form. UNIQUE at DB level via a
     * functional index on UPPER(code) — defense in depth against any
     * caller that bypasses the constructor normalization.
     */
    #[ORM\Column(name: 'code', type: 'string', length: self::CODE_MAX_LENGTH)]
    private string $code;

    /**
     * Admin-only free-text context for what this code is for. Surfaces
     * in the admin endpoints; never exposed to customers.
     */
    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    private ?string $description = null;

    /** One of self::ALL_DISCOUNT_TYPES. */
    #[ORM\Column(name: 'discount_type', type: 'string', length: 16)]
    private string $discountType;

    /**
     * For DISCOUNT_TYPE_PERCENTAGE: a 0.01-100.00 percentage applied to
     * cart subtotal (e.g. '10.00' = 10% off).
     * For DISCOUNT_TYPE_FIXED_AMOUNT: a money amount in `currency`.
     *
     * Stored as DECIMAL(10,2) string. assertMoneyNonNeg in the setter
     * validates the format.
     */
    #[ORM\Column(name: 'discount_value', type: 'decimal', precision: 10, scale: 2)]
    private string $discountValue;

    /**
     * ISO 4217 currency code. Always 'AED' at v3 launch. M3.2.X.15 will
     * exercise this for multi-currency display.
     */
    #[ORM\Column(name: 'currency', type: 'string', length: 3, options: ['default' => 'AED'])]
    private string $currency = 'AED';

    /**
     * Cart subtotal must be ≥ this amount for the code to apply.
     * NULL = no minimum.
     */
    #[ORM\Column(name: 'min_subtotal', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $minSubtotal = null;

    /**
     * Hard cap on the computed discount amount for percentage codes
     * (e.g. "10% off, max 50 AED"). For fixed_amount codes this column
     * is functionally redundant — the resolver clamps fixed_amount
     * discounts to cart.subtotal anyway. NULL = no cap.
     */
    #[ORM\Column(name: 'max_discount_amount', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $maxDiscountAmount = null;

    /**
     * Maximum total redemptions across all users. NULL = unlimited.
     */
    #[ORM\Column(name: 'usage_limit_global', type: 'integer', nullable: true)]
    private ?int $usageLimitGlobal = null;

    /**
     * Maximum redemptions per user. NULL = unlimited. Default admin
     * UX in the create endpoint suggests 1 (typical promo behavior),
     * but the schema permits NULL.
     */
    #[ORM\Column(name: 'usage_limit_per_user', type: 'integer', nullable: true)]
    private ?int $usageLimitPerUser = null;

    /** Lower time bound. NULL = no lower bound. */
    #[ORM\Column(name: 'valid_from', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $validFrom = null;

    /** Upper time bound. NULL = no upper bound. */
    #[ORM\Column(name: 'valid_until', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $validUntil = null;

    /**
     * Soft-delete flag. Setting to false hides the code from new
     * redemptions while preserving FK integrity from historical
     * promo_redemptions rows.
     */
    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    /**
     * Optional vendor scope. NULL = sitewide (admin-created) promo code
     * visible to all vendors. Non-null = vendor-owned coupon visible and
     * manageable only by that vendor's authenticated user.
     *
     * Stores the v3 Vendor entity PK (not legacy_vendor_id). Nullable
     * so existing promo codes are unaffected by this schema addition.
     */
    #[ORM\Column(name: 'vendor_id', type: 'bigint', nullable: true)]
    private ?int $vendorId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * Construct a promo code with required fields. Optional fields use
     * dedicated setters so we never have a constructor with a dozen
     * optional parameters (anti-pattern; positional-arg confusion).
     *
     * @throws \InvalidArgumentException for invalid code, discount type,
     *         or discount value format
     */
    public function __construct(
        string $code,
        string $discountType,
        string $discountValue,
    ) {
        $normalizedCode = self::normalizeCode($code);
        if ($normalizedCode === '') {
            throw new \InvalidArgumentException('Promo code must not be empty after normalization.');
        }
        if (mb_strlen($normalizedCode) > self::CODE_MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'Promo code exceeds maximum length of ' . self::CODE_MAX_LENGTH
                . ' chars (got ' . mb_strlen($normalizedCode) . ').',
            );
        }
        if (!in_array($discountType, self::ALL_DISCOUNT_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unknown discount type '{$discountType}'. "
                . 'Must be one of: ' . implode(', ', self::ALL_DISCOUNT_TYPES),
            );
        }
        self::assertMoneyNonNeg($discountValue, 'discount_value');
        if ($discountType === self::DISCOUNT_TYPE_PERCENTAGE) {
            self::assertPercentageInRange($discountValue);
        }

        $this->code = $normalizedCode;
        $this->discountType = $discountType;
        $this->discountValue = $discountValue;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Canonical normalization for promo codes: trim whitespace, then
     * upper-case using multibyte-safe transformation. Both the
     * constructor and the resolver's lookup path go through this so
     * 'welcome10', '  WELCOME10 ', and 'Welcome10' all collapse to
     * the same stored value.
     */
    public static function normalizeCode(string $raw): string
    {
        return mb_strtoupper(trim($raw));
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getDescription(): ?string { return $this->description; }
    public function getDiscountType(): string { return $this->discountType; }
    public function getDiscountValue(): string { return $this->discountValue; }
    public function getCurrency(): string { return $this->currency; }
    public function getMinSubtotal(): ?string { return $this->minSubtotal; }
    public function getMaxDiscountAmount(): ?string { return $this->maxDiscountAmount; }
    public function getUsageLimitGlobal(): ?int { return $this->usageLimitGlobal; }
    public function getUsageLimitPerUser(): ?int { return $this->usageLimitPerUser; }
    public function getValidFrom(): ?DateTimeImmutable { return $this->validFrom; }
    public function getValidUntil(): ?DateTimeImmutable { return $this->validUntil; }
    public function isActive(): bool { return $this->isActive; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    // -----------------------------------------------------------------
    // Mutators — explicit; all validate
    // -----------------------------------------------------------------

    public function setCode(string $code): void
    {
        $normalized = self::normalizeCode($code);
        if ($normalized === '') {
            throw new \InvalidArgumentException('Promo code must not be empty after normalization.');
        }
        if (mb_strlen($normalized) > self::CODE_MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'Promo code exceeds maximum length of ' . self::CODE_MAX_LENGTH
                . ' chars (got ' . mb_strlen($normalized) . ').',
            );
        }
        $this->code = $normalized;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function setDiscountType(string $type): void
    {
        if (!in_array($type, self::ALL_DISCOUNT_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unknown discount type '{$type}'. "
                . 'Must be one of: ' . implode(', ', self::ALL_DISCOUNT_TYPES),
            );
        }
        // If switching to percentage, re-validate the existing value
        // is within the 0.01-100 range.
        if ($type === self::DISCOUNT_TYPE_PERCENTAGE) {
            self::assertPercentageInRange($this->discountValue);
        }
        $this->discountType = $type;
    }

    public function setDiscountValue(string $value): void
    {
        self::assertMoneyNonNeg($value, 'discount_value');
        if ($this->discountType === self::DISCOUNT_TYPE_PERCENTAGE) {
            self::assertPercentageInRange($value);
        }
        $this->discountValue = $value;
    }

    public function setCurrency(string $currency): void
    {
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException(
                "Currency must be a 3-letter ISO 4217 code, got '{$currency}'.",
            );
        }
        $this->currency = strtoupper($currency);
    }

    public function setMinSubtotal(?string $minSubtotal): void
    {
        if ($minSubtotal !== null) {
            self::assertMoneyNonNeg($minSubtotal, 'min_subtotal');
        }
        $this->minSubtotal = $minSubtotal;
    }

    public function setMaxDiscountAmount(?string $maxDiscountAmount): void
    {
        if ($maxDiscountAmount !== null) {
            self::assertMoneyNonNeg($maxDiscountAmount, 'max_discount_amount');
        }
        $this->maxDiscountAmount = $maxDiscountAmount;
    }

    public function setUsageLimitGlobal(?int $limit): void
    {
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException(
                "usage_limit_global must be a non-negative integer or null, got {$limit}.",
            );
        }
        $this->usageLimitGlobal = $limit;
    }

    public function setUsageLimitPerUser(?int $limit): void
    {
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException(
                "usage_limit_per_user must be a non-negative integer or null, got {$limit}.",
            );
        }
        $this->usageLimitPerUser = $limit;
    }

    public function setValidFrom(?DateTimeImmutable $validFrom): void
    {
        $this->validFrom = $validFrom;
    }

    public function setValidUntil(?DateTimeImmutable $validUntil): void
    {
        $this->validUntil = $validUntil;
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
    }

    public function getVendorId(): ?int { return $this->vendorId; }
    public function setVendorId(?int $vendorId): void { $this->vendorId = $vendorId; }

    /**
     * Convenience predicate: is this code time-window-valid at $at?
     * Returns true when both bounds (if set) bracket $at; defaults
     * to "now (UTC)" when no argument is supplied.
     *
     * Used by the resolver as part of the validation chain; exposed
     * here for the admin serializer to surface a computed "currently
     * valid" flag in list payloads.
     */
    public function isCurrentlyTimeValid(?DateTimeImmutable $at = null): bool
    {
        $at = $at ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($this->validFrom !== null && $at < $this->validFrom) {
            return false;
        }
        if ($this->validUntil !== null && $at > $this->validUntil) {
            return false;
        }
        return true;
    }

    // -----------------------------------------------------------------
    // Validation helpers
    // -----------------------------------------------------------------

    /**
     * Validates a money-formatted decimal string matches our DECIMAL(10,2)
     * shape: non-negative, 0-2 fractional digits. Mirrors the same helper
     * on Order to keep the format contract identical across modules.
     */
    private static function assertMoneyNonNeg(string $value, string $field): void
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException(
                "PromoCode.{$field} must be a non-negative DECIMAL(10,2) string, got '{$value}'",
            );
        }
    }

    /**
     * For percentage codes, the value must be in (0, 100]. Zero or
     * negative would make the code a no-op; over 100 would imply
     * paying customers to take goods (the floor-at-zero in Order's
     * computeTotal protects the total, but the engine should reject
     * inputs that don't make business sense).
     */
    private static function assertPercentageInRange(string $value): void
    {
        // bccomp with 2 scale matches our DECIMAL(10,2) precision.
        if (bccomp($value, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException(
                "Percentage discount must be > 0, got '{$value}'.",
            );
        }
        if (bccomp($value, '100.00', 2) > 0) {
            throw new \InvalidArgumentException(
                "Percentage discount must be ≤ 100, got '{$value}'.",
            );
        }
    }
}
