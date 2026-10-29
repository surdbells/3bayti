import { HttpErrorResponse } from '@angular/common/http';

import {
  NO_REFRESH_TOKEN,
  SESSION_CHANGED,
  apiErrorCode,
  isSessionChanged,
  isSessionOver,
  needsTokenRefresh,
} from './session-errors';

/** A failed v3 call with the standard { error: { code, message } } envelope. */
function apiError(status: number, code?: string): HttpErrorResponse {
  return new HttpErrorResponse({
    status,
    error: code ? { error: { code, message: 'x' } } : null,
  });
}

/**
 * The session contract the portal shares with the API, web and mobile: only
 * 401 + AUTH_REFRESH_TOKEN_INVALID from the refresh endpoint ends a session,
 * and only stale/missing-token 401s trigger refresh-and-retry.
 */
describe('session-errors', () => {
  describe('apiErrorCode', () => {
    it('reads the nested v3 error code', () => {
      expect(apiErrorCode(apiError(401, 'AUTH_INVALID_TOKEN'))).toBe('AUTH_INVALID_TOKEN');
    });

    it('returns null for a non-JSON body, an empty envelope, or a non-HTTP error', () => {
      expect(apiErrorCode(new HttpErrorResponse({ status: 502, error: '<html>Bad gateway</html>' }))).toBeNull();
      expect(apiErrorCode(apiError(401))).toBeNull();
      expect(apiErrorCode(new Error('boom'))).toBeNull();
    });
  });

  describe('isSessionOver (refresh failures)', () => {
    it('is definitive ONLY for 401 + AUTH_REFRESH_TOKEN_INVALID', () => {
      expect(isSessionOver(apiError(401, 'AUTH_REFRESH_TOKEN_INVALID'))).toBeTrue();
    });

    it('treats a missing stored refresh token as definitive (nothing to retry with)', () => {
      expect(isSessionOver(new Error(NO_REFRESH_TOKEN))).toBeTrue();
    });

    it('treats network, timeout, 403, 429, 5xx, 422 and code-less 401s as transient', () => {
      const transient: unknown[] = [
        new HttpErrorResponse({ status: 0, statusText: 'Unknown Error' }),
        apiError(403),
        apiError(403, 'FORBIDDEN'),
        apiError(429, 'RATE_LIMITED'),
        apiError(500),
        apiError(503),
        apiError(422, 'VALIDATION_FAILED'),
        apiError(401),
        apiError(401, 'AUTH_INVALID_TOKEN'),
        new HttpErrorResponse({ status: 401, error: 'Unauthorized' }),
        new Error('refresh_failed'),
        new Error(SESSION_CHANGED),
      ];
      for (const err of transient) {
        expect(isSessionOver(err)).withContext(JSON.stringify(err)).toBeFalse();
      }
    });
  });

  describe('isSessionChanged', () => {
    it('recognises only the dropped-outcome error', () => {
      expect(isSessionChanged(new Error(SESSION_CHANGED))).toBeTrue();
      expect(isSessionChanged(new Error(NO_REFRESH_TOKEN))).toBeFalse();
      expect(isSessionChanged(apiError(401, 'AUTH_REFRESH_TOKEN_INVALID'))).toBeFalse();
    });
  });

  describe('needsTokenRefresh (normal endpoints)', () => {
    it('refreshes on 401 AUTH_INVALID_TOKEN, AUTH_MISSING_TOKEN, or no code', () => {
      expect(needsTokenRefresh(apiError(401, 'AUTH_INVALID_TOKEN'))).toBeTrue();
      expect(needsTokenRefresh(apiError(401, 'AUTH_MISSING_TOKEN'))).toBeTrue();
      expect(needsTokenRefresh(apiError(401))).toBeTrue();
    });

    it('passes business 401s and non-401s through untouched', () => {
      expect(needsTokenRefresh(apiError(401, 'AUTH_INVALID_CREDENTIALS'))).toBeFalse();
      expect(needsTokenRefresh(apiError(401, 'OTP_VERIFICATION_FAILED'))).toBeFalse();
      expect(needsTokenRefresh(apiError(403, 'AUTH_INVALID_TOKEN'))).toBeFalse();
      expect(needsTokenRefresh(apiError(500))).toBeFalse();
      expect(needsTokenRefresh(new Error('x'))).toBeFalse();
    });
  });
});
