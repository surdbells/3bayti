import { describe, it, expect } from 'vitest';
import { AUTH_ERROR_CODES } from './auth.types';

/**
 * Tests for the auth-types module's RUNTIME surface.
 *
 * The module is mostly type-only (interfaces and type aliases). The only
 * value-level export is AUTH_ERROR_CODES, a const-asserted object that
 * pairs symbolic names with the exact string codes the API emits. These
 * tests pin those strings so any drift away from the API contract fails
 * loudly here rather than silently at the UI surface.
 *
 * Source-of-truth references in apps/api:
 *   - Bayti\Api\Http\Errors\ErrorCodes, string constants used by all
 *     controllers via HttpException::*().
 *   - apps/api/src/Http/Controllers/Auth/*Controller.php, which codes
 *     each endpoint emits.
 */
describe('AUTH_ERROR_CODES', () => {
  it('mirrors the API ErrorCodes for the auth subset', () => {
    /* These exact strings come from apps/api/src/Http/Errors/ErrorCodes.php.
       Drift here means the UI maps an API error to a code that no API
       endpoint actually emits, silent presentation failure. */
    expect(AUTH_ERROR_CODES.INVALID_CREDENTIALS).toBe('AUTH_INVALID_CREDENTIALS');
    expect(AUTH_ERROR_CODES.ACCOUNT_INACTIVE).toBe('AUTH_ACCOUNT_INACTIVE');
    expect(AUTH_ERROR_CODES.CONFLICT_EMAIL_TAKEN).toBe('CONFLICT_EMAIL_TAKEN');
    expect(AUTH_ERROR_CODES.CONFLICT_PHONE_TAKEN).toBe('CONFLICT_PHONE_TAKEN');
    expect(AUTH_ERROR_CODES.OTP_RATE_LIMITED).toBe('OTP_RATE_LIMITED');
    expect(AUTH_ERROR_CODES.OTP_PROVIDER_ERROR).toBe('OTP_PROVIDER_ERROR');
    expect(AUTH_ERROR_CODES.OTP_INVALID_CODE).toBe('OTP_INVALID_CODE');
    expect(AUTH_ERROR_CODES.VALIDATION_FAILED).toBe('VALIDATION_FAILED');
    expect(AUTH_ERROR_CODES.REFRESH_TOKEN_INVALID).toBe('AUTH_REFRESH_TOKEN_INVALID');
    expect(AUTH_ERROR_CODES.REFRESH_TOKEN_EXPIRED).toBe('AUTH_REFRESH_TOKEN_EXPIRED');
  });

  it('has unique values (no two symbolic names share the same code)', () => {
    const values = Object.values(AUTH_ERROR_CODES);
    const unique = new Set(values);
    expect(unique.size).toBe(values.length);
  });

  it('all values are SCREAMING_SNAKE_CASE strings (catches typos like trailing whitespace)', () => {
    for (const value of Object.values(AUTH_ERROR_CODES)) {
      expect(value).toMatch(/^[A-Z][A-Z0-9_]*$/);
    }
  });
});
