import { describe, it, expect } from 'vitest';
import { HttpErrorResponse } from '@angular/common/http';
import {
  AUTH_REFRESH_TOKEN_INVALID,
  isSessionEndedError,
  isStaleAccessToken401,
  readAuthErrorCode,
} from './auth-errors';

/**
 * Tests for the auth error classifier, the client half of the session
 * contract: ONLY 401 + AUTH_REFRESH_TOKEN_INVALID ends a session, and only
 * stale-token 401s (AUTH_INVALID_TOKEN / AUTH_MISSING_TOKEN / no code)
 * trigger a refresh.
 */

function httpError(status: number, error: unknown): HttpErrorResponse {
  return new HttpErrorResponse({ status, error, url: '/x' });
}

describe('readAuthErrorCode', () => {
  it('reads the API-native nested { error: { code } } envelope', () => {
    expect(readAuthErrorCode({ error: { code: 'AUTH_INVALID_TOKEN', message: 'x' } })).toBe(
      'AUTH_INVALID_TOKEN',
    );
  });

  it('reads the legacy flat { error_code } and bare { code } shapes', () => {
    expect(readAuthErrorCode({ error_code: 'OTP_VERIFICATION_FAILED' })).toBe('OTP_VERIFICATION_FAILED');
    expect(readAuthErrorCode({ code: 'X' })).toBe('X');
  });

  it('returns undefined for string / HTML / null / code-less bodies', () => {
    expect(readAuthErrorCode('Unauthorized')).toBeUndefined();
    expect(readAuthErrorCode('<html>502</html>')).toBeUndefined();
    expect(readAuthErrorCode(null)).toBeUndefined();
    expect(readAuthErrorCode({ error: 'Unauthorized' })).toBeUndefined();
    expect(readAuthErrorCode({ message: 'x' })).toBeUndefined();
  });
});

describe('isSessionEndedError', () => {
  it('is true ONLY for 401 + AUTH_REFRESH_TOKEN_INVALID', () => {
    expect(
      isSessionEndedError(httpError(401, { error: { code: AUTH_REFRESH_TOKEN_INVALID, message: 'x' } })),
    ).toBe(true);
    /* Legacy flat shape from an older BFF build. */
    expect(isSessionEndedError(httpError(401, { error_code: AUTH_REFRESH_TOKEN_INVALID }))).toBe(true);
  });

  it.each([
    ['network (status 0)', 0, null],
    ['401 without a body', 401, null],
    ['401 with another code', 401, { error: { code: 'AUTH_INVALID_TOKEN' } }],
    ['403 proxy block', 403, '<html>Forbidden</html>'],
    ['429 rate limit', 429, { error: { code: 'RATE_LIMITED' } }],
    ['503 BFF transient', 503, { error: { code: 'AUTH_REFRESH_UNAVAILABLE' } }],
    ['500', 500, { error: { code: AUTH_REFRESH_TOKEN_INVALID } }],
    ['422', 422, { error: { code: 'VALIDATION_FAILED' } }],
  ])('is false for %s (transient: keep the session)', (_label, status, body) => {
    expect(isSessionEndedError(httpError(status, body))).toBe(false);
  });

  it('is false for non-HTTP errors', () => {
    expect(isSessionEndedError(new Error('boom'))).toBe(false);
    expect(isSessionEndedError(undefined)).toBe(false);
  });
});

describe('isStaleAccessToken401', () => {
  it.each([
    ['AUTH_INVALID_TOKEN', { error: { code: 'AUTH_INVALID_TOKEN' } }],
    ['AUTH_MISSING_TOKEN', { error: { code: 'AUTH_MISSING_TOKEN' } }],
    ['no code (string body)', 'Unauthorized'],
    ['no code (null body)', null],
  ])('refreshes on a 401 with %s', (_label, body) => {
    expect(isStaleAccessToken401(httpError(401, body))).toBe(true);
  });

  it.each([
    ['AUTH_INVALID_CREDENTIALS', { error: { code: 'AUTH_INVALID_CREDENTIALS' } }],
    ['OTP_VERIFICATION_FAILED', { error: { code: 'OTP_VERIFICATION_FAILED' } }],
    ['legacy flat business code', { error_code: 'AUTH_INVALID_CREDENTIALS' }],
  ])('does NOT refresh on a business 401 %s', (_label, body) => {
    expect(isStaleAccessToken401(httpError(401, body))).toBe(false);
  });

  it('is false for non-401 statuses', () => {
    expect(isStaleAccessToken401(httpError(403, { error: { code: 'AUTH_INVALID_TOKEN' } }))).toBe(false);
    expect(isStaleAccessToken401(httpError(500, null))).toBe(false);
  });
});
