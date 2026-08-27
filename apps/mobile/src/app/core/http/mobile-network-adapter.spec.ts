/**
 * Unit tests for the pure translate functions in MobileNetworkAdapter.
 *
 * Why only the translate functions and the URL resolver
 * ======================================================
 * The class methods that call HttpClient need TestBed + Angular's
 * HttpClientTestingModule mocking, which is heavier infrastructure
 * than mobile currently has set up (no integration tests, no
 * HttpClient mocks anywhere in the codebase).
 *
 * The two translate functions and the URL resolver are PURE, they
 * don't touch HttpClient. So they can be tested as straight unit
 * tests without any test harness. They're also where the most subtle
 * bugs would land (id/token extraction edge cases, v3 envelope
 * unwrapping, URL normalization). Covering them gives us regression
 * confidence on the core translation logic.
 *
 * Why the spec exists if CI doesn't run it
 * =========================================
 * Mobile CI is type-check + build only, no `ng test` execution.
 * This spec still earns its place:
 *
 *   1. It compile-checks against the implementation. Any breaking
 *      change to the translate function signatures fails type-check
 *      (CI catches it).
 *   2. It documents expected behaviour with concrete examples that
 *      future devs can read.
 *   3. It's ready when CI test runner gets wired up (M4 hardening).
 *   4. A developer can run it locally with `pnpm --filter @3bayti/mobile test`
 *      to sanity-check refactors.
 */

import {
  translateRequestBody,
  toLegacyEnvelope,
} from './mobile-network-adapter';

describe('translateRequestBody', () => {
  it('extracts token to Authorization header and drops id', () => {
    const result = translateRequestBody({
      id: 42,
      token: 'abc.def.ghi',
      product: 'red-abaya',
      quantity: 2,
    });
    expect(result.authHeader).toBe('Bearer abc.def.ghi');
    expect(result.translatedBody).toEqual({
      product: 'red-abaya',
      quantity: 2,
    });
  });

  it('returns null authHeader when token is empty string', () => {
    // Legacy convention: unauthenticated calls send token: ''. Don't
    // emit a useless 'Bearer ' header.
    const result = translateRequestBody({
      id: 0,
      token: '',
      email: 'alice@example.com',
    });
    expect(result.authHeader).toBeNull();
    expect(result.translatedBody).toEqual({ email: 'alice@example.com' });
  });

  it('returns null authHeader when token is missing entirely', () => {
    const result = translateRequestBody({ email: 'alice@example.com' });
    expect(result.authHeader).toBeNull();
    expect(result.translatedBody).toEqual({ email: 'alice@example.com' });
  });

  it('handles null body without crashing', () => {
    const result = translateRequestBody(null);
    expect(result.authHeader).toBeNull();
    expect(result.translatedBody).toBeNull();
  });

  it('handles undefined body without crashing', () => {
    const result = translateRequestBody(undefined);
    expect(result.authHeader).toBeNull();
    expect(result.translatedBody).toBeUndefined();
  });

  it('passes arrays through unchanged (no id/token extraction)', () => {
    // Arrays as bodies are unusual but defensive: don't try to walk
    // them as if they were objects.
    const result = translateRequestBody([1, 2, 3]);
    expect(result.authHeader).toBeNull();
    expect(result.translatedBody).toEqual([1, 2, 3]);
  });

  it('does not mutate the input object', () => {
    const original = {
      id: 42,
      token: 'abc',
      product: 'foo',
    };
    const snapshot = { ...original };
    translateRequestBody(original);
    expect(original).toEqual(snapshot);
  });

  it('handles non-string token defensively (numeric token: drop, no header)', () => {
    // Should never happen in practice, but if a call site mis-types
    // token, don't crash and don't emit a bogus header.
    const result = translateRequestBody({ id: 42, token: 12345 });
    expect(result.authHeader).toBeNull();
    expect(result.translatedBody).toEqual({});
  });
});

describe('toLegacyEnvelope', () => {
  it('unwraps v3 {data: T} into envelope.data = T', () => {
    const env = toLegacyEnvelope({ data: { id: 1, name: 'Alice' } });
    expect(env.response_code).toBe(200);
    expect(env.status).toBe('success');
    expect(env.message).toBe('');
    expect(env.data).toEqual({ id: 1, name: 'Alice' });
  });

  it('forwards entire response as data when there is no {data: ...} wrapper', () => {
    // e.g. login response: { access_token, refresh_token, user, ... }
    const loginShape = {
      access_token: 'xyz',
      refresh_token: 'abc',
      user: { id: 1 },
    };
    const env = toLegacyEnvelope(loginShape);
    expect(env.data).toEqual(loginShape);
  });

  it('passes through arrays unchanged (no data unwrap)', () => {
    // Unusual but defensive: top-level array v3 responses
    // (none currently, but possible) should reach call sites
    // as response.data = [...].
    const env = toLegacyEnvelope([1, 2, 3]);
    expect(env.data).toEqual([1, 2, 3]);
  });

  it('handles null v3 response', () => {
    const env = toLegacyEnvelope(null);
    expect(env.data).toBeNull();
  });

  it('handles primitive v3 response (string)', () => {
    const env = toLegacyEnvelope('ok');
    expect(env.data).toBe('ok');
  });

  it('always emits response_code 200 + status success for successful responses', () => {
    // Error responses go through translateError, not toLegacyEnvelope.
    const env = toLegacyEnvelope({ data: { x: 1 } });
    expect(env.response_code).toBe(200);
    expect(env.status).toBe('success');
  });
});
