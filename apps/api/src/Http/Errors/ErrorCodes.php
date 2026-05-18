<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Errors;

/**
 * Centralised inventory of every error code returned by the API.
 *
 * Why a single class instead of throwing string literals everywhere
 * --------------------------------------------------------------
 * Three reasons:
 *
 *   1. The `code` field is a public contract. Mobile + web clients
 *      switch on these strings (e.g. show "incorrect password" UI
 *      vs "account locked" UI). Drifting strings are a breaking change.
 *      Keeping them as constants means refactor + grep work.
 *
 *   2. We'll generate OpenAPI from this inventory in M5. Having a
 *      single class to introspect makes that trivial.
 *
 *   3. PHPStan + IDE autocomplete catch typos. `ErrorCodes::AUTH_INVALID_TOKEN`
 *      is a compile-time check; `'AUTH_INVALI_TOKEN'` (typo) ships and
 *      breaks a client matcher.
 *
 * Naming convention
 * -----------------
 * `DOMAIN_REASON` — domain prefix groups related codes; reason
 * describes the failure. E.g. AUTH_*, OTP_*, VALIDATION_*.
 *
 * NOT meant for user-visible messages — those are localised in the
 * front-end via the code-to-string mapping. Backend's role is to
 * give a stable code; localisation is the client's problem.
 */
final class ErrorCodes
{
    // -------------------------------------------------------------------
    // AUTH — token + login + session
    // -------------------------------------------------------------------

    /** Authorization header missing or wrong shape. */
    public const AUTH_MISSING_TOKEN = 'AUTH_MISSING_TOKEN';

    /** Token signature, audience, expiry, or claims rejected. */
    public const AUTH_INVALID_TOKEN = 'AUTH_INVALID_TOKEN';

    /** Email/password combo did not match a known user. */
    public const AUTH_INVALID_CREDENTIALS = 'AUTH_INVALID_CREDENTIALS';

    /** User exists but is_active = false (admin-disabled or self-disabled). */
    public const AUTH_ACCOUNT_INACTIVE = 'AUTH_ACCOUNT_INACTIVE';

    /** User exists but phone hasn't been OTP-verified yet (registration incomplete). */
    public const AUTH_PHONE_NOT_VERIFIED = 'AUTH_PHONE_NOT_VERIFIED';

    /** Refresh token unknown, already revoked, or expired in DB. */
    public const AUTH_REFRESH_TOKEN_INVALID = 'AUTH_REFRESH_TOKEN_INVALID';

    // -------------------------------------------------------------------
    // OTP
    // -------------------------------------------------------------------

    /** Per-phone hourly OTP send cap exceeded. */
    public const OTP_RATE_LIMITED = 'OTP_RATE_LIMITED';

    /**
     * Generic OTP verification failure. Covers: wrong code, expired,
     * already-consumed, unknown verificationId. Distinguishing
     * publicly leaks info; we collapse them all to one code.
     */
    public const OTP_VERIFICATION_FAILED = 'OTP_VERIFICATION_FAILED';

    /** CPaaS itself failed (network, upstream 5xx, malformed response). */
    public const OTP_PROVIDER_ERROR = 'OTP_PROVIDER_ERROR';

    // -------------------------------------------------------------------
    // VALIDATION
    // -------------------------------------------------------------------

    /** Request body failed schema validation. Details carry per-field errors. */
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    /** Body wasn't valid JSON or wasn't a JSON object. */
    public const VALIDATION_BAD_REQUEST = 'VALIDATION_BAD_REQUEST';

    // -------------------------------------------------------------------
    // CONFLICT — uniqueness violations
    // -------------------------------------------------------------------

    /** Email already in use (registration). */
    public const CONFLICT_EMAIL_TAKEN = 'CONFLICT_EMAIL_TAKEN';

    /** Phone already in use (registration). */
    public const CONFLICT_PHONE_TAKEN = 'CONFLICT_PHONE_TAKEN';

    // -------------------------------------------------------------------
    // GENERIC
    // -------------------------------------------------------------------

    /** Resource not found. */
    public const NOT_FOUND = 'NOT_FOUND';

    /** User isn't allowed to perform the action (role check failed). */
    public const FORBIDDEN = 'FORBIDDEN';

    /**
     * Authenticated user is a vendor (is_vendor=true) but owns no
     * vendor stores in the 'approved' lifecycle state. Returned by
     * VendorAuthMiddleware (M3.2.X.6-B) when a pending or suspended-
     * only vendor attempts to access /v3/vendor/* endpoints.
     *
     * Distinct from FORBIDDEN — FORBIDDEN is a role check ("you're
     * not a vendor"); VENDOR_NOT_APPROVED is a lifecycle gate
     * ("you're a vendor, but your store isn't approved yet").
     */
    public const VENDOR_NOT_APPROVED = 'VENDOR_NOT_APPROVED';

    /** Catch-all for unexpected failures (uncaught exception → 500). */
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    // -------------------------------------------------------------------
    // PAYMENT — checkout + refund + cancel
    // -------------------------------------------------------------------

    /** Payment provider (Noon, etc.) failed; HTTP 502. Retry-safe. */
    public const PAYMENT_PROVIDER_ERROR = 'PAYMENT_PROVIDER_ERROR';

    /** Business rule violated, e.g. cart empty at checkout; HTTP 422. */
    public const BUSINESS_RULE_VIOLATION = 'BUSINESS_RULE_VIOLATION';

    // -------------------------------------------------------------------
    // PROMO — promotional code redemption (M3.2.X.8)
    //
    // All PROMO_* codes are returned as HTTP 422 by the customer-facing
    // /v3/cart/quote + /v3/checkout/initiate endpoints. The codes are
    // intentionally specific (rather than collapsed to a generic
    // PROMO_INVALID) so client UI can show actionable messages — a
    // "not yet valid" code may warrant "comes back live on X" whereas
    // an "expired" code warrants a different prompt.
    // -------------------------------------------------------------------

    /** Code text doesn't match any catalog row (after normalization). */
    public const PROMO_NOT_FOUND = 'PROMO_NOT_FOUND';

    /** Code exists but has been admin-disabled (is_active = false). */
    public const PROMO_INACTIVE = 'PROMO_INACTIVE';

    /** Code's valid_from is in the future. */
    public const PROMO_NOT_YET_VALID = 'PROMO_NOT_YET_VALID';

    /** Code's valid_until is in the past. */
    public const PROMO_EXPIRED = 'PROMO_EXPIRED';

    /** Code's currency does not match the cart's currency. */
    public const PROMO_CURRENCY_MISMATCH = 'PROMO_CURRENCY_MISMATCH';

    /**
     * Cart subtotal is below the code's min_subtotal. Error details
     * payload includes the required min_subtotal so client UX can
     * show "Spend X more to unlock this code".
     */
    public const PROMO_MIN_SUBTOTAL_NOT_MET = 'PROMO_MIN_SUBTOTAL_NOT_MET';

    /** Code has hit its global usage cap; no more redemptions accepted. */
    public const PROMO_GLOBAL_LIMIT_REACHED = 'PROMO_GLOBAL_LIMIT_REACHED';

    /** This user has already redeemed the code up to its per-user cap. */
    public const PROMO_USER_LIMIT_REACHED = 'PROMO_USER_LIMIT_REACHED';
}
