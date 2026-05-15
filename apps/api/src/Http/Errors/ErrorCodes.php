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

    /** Catch-all for unexpected failures (uncaught exception → 500). */
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    // -------------------------------------------------------------------
    // PAYMENT — checkout + refund + cancel
    // -------------------------------------------------------------------

    /** Payment provider (Noon, etc.) failed; HTTP 502. Retry-safe. */
    public const PAYMENT_PROVIDER_ERROR = 'PAYMENT_PROVIDER_ERROR';

    /** Business rule violated, e.g. cart empty at checkout; HTTP 422. */
    public const BUSINESS_RULE_VIOLATION = 'BUSINESS_RULE_VIOLATION';
}
