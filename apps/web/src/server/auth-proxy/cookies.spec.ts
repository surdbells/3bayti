import { describe, it, expect } from 'vitest';
import {
  buildRefreshCookie,
  buildClearRefreshCookie,
  parseRefreshCookie,
} from './cookies';
import { createAuthProxyConfig, type AuthProxyConfig } from './config';

const baseConfig: AuthProxyConfig = createAuthProxyConfig({
  cookieSecure: true,
  cookieSameSite: 'lax',
});

describe('buildRefreshCookie', () => {
  it('produces a well-formed Set-Cookie with all expected attributes', () => {
    const cookie = buildRefreshCookie('eyJabc.eyJdef.sig', baseConfig);

    expect(cookie).toContain('bayti_refresh=eyJabc.eyJdef.sig');
    expect(cookie).toContain('HttpOnly');
    expect(cookie).toContain('Path=/auth-proxy');
    expect(cookie).toContain(`Max-Age=${7 * 24 * 60 * 60}`);
    expect(cookie).toContain('SameSite=Lax');
    expect(cookie).toContain('Secure');
  });

  it('omits Secure when configured for non-https dev', () => {
    const cookie = buildRefreshCookie('tok', createAuthProxyConfig({ cookieSecure: false }));
    expect(cookie).not.toContain('Secure');
  });

  it('upper-cases the SameSite attribute regardless of input casing', () => {
    expect(buildRefreshCookie('tok', createAuthProxyConfig({ cookieSameSite: 'strict' })))
      .toContain('SameSite=Strict');
    expect(buildRefreshCookie('tok', createAuthProxyConfig({ cookieSameSite: 'none', cookieSecure: true })))
      .toContain('SameSite=None');
    expect(buildRefreshCookie('tok', createAuthProxyConfig({ cookieSameSite: 'lax' })))
      .toContain('SameSite=Lax');
  });

  it('forces Secure when SameSite=None even if cookieSecure was false (browsers reject otherwise)', () => {
    const cookie = buildRefreshCookie(
      'tok',
      createAuthProxyConfig({ cookieSameSite: 'none', cookieSecure: false }),
    );
    expect(cookie).toContain('Secure');
  });

  it('rejects tokens containing characters unsafe for Set-Cookie values', () => {
    const unsafe = ['contains space', 'has;semicolon', 'has,comma', 'has"quote', 'has\\backslash', "has\x00null"];
    for (const value of unsafe) {
      expect(() => buildRefreshCookie(value, baseConfig)).toThrow(/unsafe/i);
    }
  });

  it('rejects tokens larger than 4 KB to avoid silent browser stripping', () => {
    const huge = 'a'.repeat(4097);
    expect(() => buildRefreshCookie(huge, baseConfig)).toThrow(/4 KB/);
  });

  it('accepts JWT-shaped tokens (URL-safe base64 + dots)', () => {
    /* The 3-segment shape with base64-url alphabet must be accepted; this
       is the entire production token shape. */
    const jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SIGN-aTuRe_v1';
    expect(() => buildRefreshCookie(jwt, baseConfig)).not.toThrow();
  });
});

describe('buildClearRefreshCookie', () => {
  it('emits Max-Age=0 with matching attributes for browser to delete it', () => {
    const cookie = buildClearRefreshCookie(baseConfig);

    expect(cookie).toContain('bayti_refresh=');
    expect(cookie).toContain('Max-Age=0');
    expect(cookie).toContain('HttpOnly');
    expect(cookie).toContain('Path=/auth-proxy');
    expect(cookie).toContain('SameSite=Lax');
  });

  it('preserves Secure when configured', () => {
    expect(buildClearRefreshCookie(createAuthProxyConfig({ cookieSecure: true }))).toContain('Secure');
    expect(buildClearRefreshCookie(createAuthProxyConfig({ cookieSecure: false }))).not.toContain('Secure');
  });
});

describe('parseRefreshCookie', () => {
  it('returns the value when the cookie is present alone', () => {
    expect(parseRefreshCookie('bayti_refresh=abc.def.ghi', baseConfig)).toBe('abc.def.ghi');
  });

  it('returns the value when present alongside other cookies', () => {
    expect(
      parseRefreshCookie('bayti_locale=en; bayti_refresh=abc.def.ghi; theme=dark', baseConfig),
    ).toBe('abc.def.ghi');
  });

  it('tolerates extra whitespace around separators', () => {
    expect(parseRefreshCookie('bayti_locale=en;bayti_refresh=tok', baseConfig)).toBe('tok');
    expect(parseRefreshCookie('bayti_locale=en;   bayti_refresh=tok', baseConfig)).toBe('tok');
  });

  it('returns null when the cookie is absent', () => {
    expect(parseRefreshCookie('bayti_locale=en; theme=dark', baseConfig)).toBeNull();
  });

  it('returns null for null, undefined, or empty header', () => {
    expect(parseRefreshCookie(null, baseConfig)).toBeNull();
    expect(parseRefreshCookie(undefined, baseConfig)).toBeNull();
    expect(parseRefreshCookie('', baseConfig)).toBeNull();
  });

  it('returns null when the cookie has an empty value', () => {
    expect(parseRefreshCookie('bayti_refresh=', baseConfig)).toBeNull();
    expect(parseRefreshCookie('bayti_refresh=; bayti_locale=en', baseConfig)).toBeNull();
  });

  it('handles values containing equals signs (JWTs do not, but be defensive)', () => {
    expect(parseRefreshCookie('bayti_refresh=abc=def=ghi', baseConfig)).toBe('abc=def=ghi');
  });

  it('respects a customised cookie name', () => {
    const custom = createAuthProxyConfig({ refreshCookieName: 'custom_refresh' });
    expect(parseRefreshCookie('custom_refresh=tok', custom)).toBe('tok');
    expect(parseRefreshCookie('bayti_refresh=tok', custom)).toBeNull();
  });

  it('does NOT URL-decode the value (JWTs are URL-safe; would silently mangle %-tokens)', () => {
    /* If we ever ship a token that contains a literal percent (shouldn't,
       but hypothetically), decodeURIComponent would lose data. */
    expect(parseRefreshCookie('bayti_refresh=a%20b', baseConfig)).toBe('a%20b');
  });
});
