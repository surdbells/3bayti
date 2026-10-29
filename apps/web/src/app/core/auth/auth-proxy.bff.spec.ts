import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { onRequest as authProxy } from '../../../../functions/auth-proxy/[[path]]';
import { onRequest as socialProxy } from '../../../../functions/auth-proxy/social';
import { onRequest as phoneClaimProxy } from '../../../../functions/auth-proxy/phone-claim-verify';

/**
 * Tests for the auth-proxy Pages Functions (the BFF that owns the
 * HttpOnly `bayti_refresh` cookie), driven through their real
 * `onRequest` entry points with the upstream API mocked via global fetch.
 *
 * Contract under test (product rule: signed in until "Log out"):
 *   - The cookie is cleared ONLY when /v3/auth/refresh answers 401 with
 *     AUTH_REFRESH_TOKEN_INVALID (→ SPA gets 401 with that code), or on
 *     /logout.
 *   - Any other refresh failure (network, timeout, 5xx, 429, 403, 422,
 *     non-JSON, a 401 without the code) leaves the cookie untouched and
 *     returns 503 AUTH_REFRESH_UNAVAILABLE.
 *   - /me: once the rotation succeeded the new cookie is ALWAYS set; a
 *     failing /v3/auth/me then yields 503 + that Set-Cookie, never a clear.
 *   - Cookie Max-Age is 34560000 (400 days) in all three functions.
 *   - /logout always clears the cookie and answers without waiting on the
 *     upstream revoke when the runtime offers waitUntil.
 */

const ORIGIN = 'https://3bayti.ae';
const MAX_AGE_400_DAYS = 'Max-Age=34560000';

type UpstreamReply =
  | { status: number; body?: unknown; raw?: string }
  | { throws: true }
  /** Never answers (a hung upstream). */
  | { hangs: true };

interface UpstreamCall {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: unknown;
}

let calls: UpstreamCall[] = [];
let replies: Record<string, UpstreamReply> = {};

function json(status: number, body: unknown): UpstreamReply {
  return { status, body };
}

const SESSION_ENDED = json(401, {
  error: { code: 'AUTH_REFRESH_TOKEN_INVALID', message: 'Refresh token is invalid or expired.' },
});

function rotated(rt = 'rt.new'): UpstreamReply {
  return json(200, {
    access_token: 'at.new',
    access_token_expires_at: '2026-10-29T12:15:00+00:00',
    refresh_token: rt,
    refresh_token_expires_at: '2031-10-29T12:00:00+00:00',
    user: { id: 7, email: 'jane@example.com' },
  });
}

function installFetch(): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
      const url = String(input);
      const path = new URL(url).pathname;
      calls.push({
        url,
        method: init?.method ?? 'GET',
        headers: { ...((init?.headers as Record<string, string>) ?? {}) },
        body: typeof init?.body === 'string' ? JSON.parse(init.body) : null,
      });
      const reply = replies[path];
      if (reply === undefined) throw new Error(`unexpected upstream call ${path}`);
      if ('throws' in reply) throw new TypeError('network down');
      if ('hangs' in reply) return new Promise<Response>(() => undefined);
      const text = reply.raw ?? JSON.stringify(reply.body ?? null);
      /* 204 / 304 are null-body statuses: a Response must not carry a body. */
      return new Response(reply.status === 204 || reply.status === 304 ? null : text, {
        status: reply.status,
        headers: { 'Content-Type': reply.raw !== undefined ? 'text/html' : 'application/json' },
      });
    }),
  );
}

function call(
  handler: (ctx: { request: Request; waitUntil?: (p: Promise<unknown>) => void }) => Promise<Response>,
  path: string,
  init: {
    method?: string;
    cookie?: string;
    body?: unknown;
    auth?: string;
    waitUntil?: (p: Promise<unknown>) => void;
  } = {},
): Promise<Response> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  if (init.cookie !== undefined) headers['Cookie'] = init.cookie;
  if (init.auth !== undefined) headers['Authorization'] = init.auth;
  const request = new Request(`${ORIGIN}/auth-proxy/${path}`, {
    method: init.method ?? 'GET',
    headers,
    body: init.body !== undefined ? JSON.stringify(init.body) : undefined,
  });
  return handler(init.waitUntil ? { request, waitUntil: init.waitUntil } : { request });
}

function setCookies(res: Response): string[] {
  const h = res.headers as Headers & { getSetCookie?: () => string[] };
  if (typeof h.getSetCookie === 'function') return h.getSetCookie();
  const raw = res.headers.get('Set-Cookie');
  return raw ? [raw] : [];
}

function errorCode(body: unknown): unknown {
  return (body as { error?: { code?: unknown } } | null)?.error?.code;
}

describe('auth-proxy BFF ([[path]].ts)', () => {
  let warn: { mockRestore(): void };

  beforeEach(() => {
    calls = [];
    replies = {};
    installFetch();
    warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    warn.mockRestore();
  });

  /* -----------------------------------------------------------------
     POST /auth-proxy/refresh
     ----------------------------------------------------------------- */
  describe('POST /refresh', () => {
    it('401 AUTH_REFRESH_TOKEN_INVALID (nested shape) with no cookie, without calling upstream', async () => {
      const res = await call(authProxy, 'refresh', { method: 'POST' });
      expect(res.status).toBe(401);
      expect(errorCode(await res.json())).toBe('AUTH_REFRESH_TOKEN_INVALID');
      expect(calls).toHaveLength(0);
    });

    it('rotates: 200 without refresh_token in the body, Set-Cookie with the new token for 400 days', async () => {
      replies['/v3/auth/refresh'] = rotated('rt.2');
      const res = await call(authProxy, 'refresh', { method: 'POST', cookie: 'bayti_refresh=rt.1' });

      expect(res.status).toBe(200);
      const body = (await res.json()) as Record<string, unknown>;
      expect(body['access_token']).toBe('at.new');
      expect(body['refresh_token']).toBeUndefined();
      const cookies = setCookies(res);
      expect(cookies).toHaveLength(1);
      expect(cookies[0]).toContain('bayti_refresh=rt.2');
      expect(cookies[0]).toContain(MAX_AGE_400_DAYS);
      expect(cookies[0]).toContain('HttpOnly');
      expect(calls[0].body).toEqual({ refresh_token: 'rt.1' });
    });

    it('definitive 401 AUTH_REFRESH_TOKEN_INVALID: clears the cookie and returns 401 with the same code', async () => {
      replies['/v3/auth/refresh'] = SESSION_ENDED;
      const res = await call(authProxy, 'refresh', { method: 'POST', cookie: 'bayti_refresh=rt.1' });

      expect(res.status).toBe(401);
      expect(errorCode(await res.json())).toBe('AUTH_REFRESH_TOKEN_INVALID');
      const cookies = setCookies(res);
      expect(cookies).toHaveLength(1);
      expect(cookies[0]).toContain('bayti_refresh=;');
      expect(cookies[0]).toContain('Max-Age=0');
    });

    it.each<[string, UpstreamReply]>([
      ['500', json(500, { error: { code: 'INTERNAL', message: 'x' } })],
      ['502 HTML', { status: 502, raw: '<html>Bad gateway</html>' }],
      ['503', json(503, null)],
      ['429', json(429, { error: { code: 'RATE_LIMITED', message: 'x' } })],
      ['403 proxy block', { status: 403, raw: '<html>Forbidden</html>' }],
      ['422', json(422, { error: { code: 'VALIDATION_FAILED', message: 'x' } })],
      ['401 without the definitive code', json(401, { error: { code: 'AUTH_INVALID_TOKEN', message: 'x' } })],
      ['200 non-JSON', { status: 200, raw: '<html>ok?</html>' }],
      ['network error / timeout', { throws: true }],
    ])('upstream %s: 503 AUTH_REFRESH_UNAVAILABLE and the cookie is NOT touched', async (_label, reply) => {
      replies['/v3/auth/refresh'] = reply;
      const res = await call(authProxy, 'refresh', { method: 'POST', cookie: 'bayti_refresh=rt.1' });

      expect(res.status).toBe(503);
      expect(errorCode(await res.json())).toBe('AUTH_REFRESH_UNAVAILABLE');
      expect(setCookies(res)).toEqual([]);
    });
  });

  /* -----------------------------------------------------------------
     GET /auth-proxy/me
     ----------------------------------------------------------------- */
  describe('GET /me', () => {
    it('401 AUTH_REFRESH_TOKEN_INVALID with no cookie', async () => {
      const res = await call(authProxy, 'me');
      expect(res.status).toBe(401);
      expect(errorCode(await res.json())).toBe('AUTH_REFRESH_TOKEN_INVALID');
      expect(calls).toHaveLength(0);
    });

    it('rotates then reads /v3/auth/me: 200, flat user, new cookie', async () => {
      replies['/v3/auth/refresh'] = rotated('rt.2');
      replies['/v3/auth/me'] = json(200, { user: { id: 7, email: 'jane@example.com', locale: 'en' } });
      const res = await call(authProxy, 'me', { cookie: 'bayti_refresh=rt.1' });

      expect(res.status).toBe(200);
      const body = (await res.json()) as Record<string, unknown>;
      expect(body['access_token']).toBe('at.new');
      expect(body['user']).toEqual({ id: 7, email: 'jane@example.com', locale: 'en' });
      expect(body['refresh_token']).toBeUndefined();
      expect(setCookies(res)[0]).toContain('bayti_refresh=rt.2');
      expect(setCookies(res)[0]).toContain(MAX_AGE_400_DAYS);
      /* /v3/auth/me is called with the freshly rotated access token. */
      expect(calls[1].headers['Authorization']).toBe('Bearer at.new');
    });

    it('definitive rejection: clears the cookie, 401 with the code, never calls /v3/auth/me', async () => {
      replies['/v3/auth/refresh'] = SESSION_ENDED;
      const res = await call(authProxy, 'me', { cookie: 'bayti_refresh=rt.1' });

      expect(res.status).toBe(401);
      expect(errorCode(await res.json())).toBe('AUTH_REFRESH_TOKEN_INVALID');
      expect(setCookies(res)[0]).toContain('Max-Age=0');
      expect(calls.map(c => new URL(c.url).pathname)).toEqual(['/v3/auth/refresh']);
    });

    it.each<[string, UpstreamReply]>([
      ['502', json(502, null)],
      ['network error', { throws: true }],
      ['429', json(429, null)],
    ])('refresh %s: 503, cookie untouched, /v3/auth/me not called', async (_label, reply) => {
      replies['/v3/auth/refresh'] = reply;
      const res = await call(authProxy, 'me', { cookie: 'bayti_refresh=rt.1' });

      expect(res.status).toBe(503);
      expect(errorCode(await res.json())).toBe('AUTH_REFRESH_UNAVAILABLE');
      expect(setCookies(res)).toEqual([]);
      expect(calls).toHaveLength(1);
    });

    it.each<[string, UpstreamReply]>([
      ['500', json(500, { error: { code: 'INTERNAL', message: 'x' } })],
      ['401', json(401, { error: { code: 'AUTH_INVALID_TOKEN', message: 'x' } })],
      ['network error', { throws: true }],
      ['non-JSON', { status: 200, raw: '<html></html>' }],
    ])('rotation OK but /v3/auth/me %s: 503 that still CARRIES the new cookie (never a clear)', async (_label, reply) => {
      replies['/v3/auth/refresh'] = rotated('rt.2');
      replies['/v3/auth/me'] = reply;
      const res = await call(authProxy, 'me', { cookie: 'bayti_refresh=rt.1' });

      expect(res.status).toBe(503);
      expect(errorCode(await res.json())).toBe('AUTH_REFRESH_UNAVAILABLE');
      const cookies = setCookies(res);
      expect(cookies).toHaveLength(1);
      expect(cookies[0]).toContain('bayti_refresh=rt.2');
      expect(cookies[0]).toContain(MAX_AGE_400_DAYS);
    });
  });

  /* -----------------------------------------------------------------
     POST /auth-proxy/logout
     ----------------------------------------------------------------- */
  describe('POST /logout', () => {
    it('clears the cookie and posts { refresh_token } upstream WITHOUT a bearer', async () => {
      replies['/v3/auth/logout'] = { status: 204, raw: '' };
      const res = await call(authProxy, 'logout', { method: 'POST', cookie: 'bayti_refresh=rt.1' });

      expect(res.status).toBe(204);
      expect(setCookies(res)[0]).toContain('Max-Age=0');
      expect(calls).toHaveLength(1);
      expect(calls[0].body).toEqual({ refresh_token: 'rt.1' });
      expect(calls[0].headers['Authorization']).toBeUndefined();
    });

    it('answers at once and hands a slow upstream revoke to waitUntil (the cookie clear is never held up)', async () => {
      replies['/v3/auth/logout'] = { hangs: true };
      const background: Array<Promise<unknown>> = [];
      const res = await call(authProxy, 'logout', {
        method: 'POST',
        cookie: 'bayti_refresh=rt.1',
        waitUntil: p => background.push(p),
      });

      expect(res.status).toBe(204);
      expect(setCookies(res)[0]).toContain('Max-Age=0');
      expect(background).toHaveLength(1);
      expect(calls[0].body).toEqual({ refresh_token: 'rt.1' });
    });

    it('still clears the cookie and answers 204 when the upstream call fails', async () => {
      replies['/v3/auth/logout'] = { throws: true };
      const res = await call(authProxy, 'logout', { method: 'POST', cookie: 'bayti_refresh=rt.1' });

      expect(res.status).toBe(204);
      expect(setCookies(res)[0]).toContain('Max-Age=0');
    });
  });

  /* -----------------------------------------------------------------
     Cookie lifetime on the login-style endpoints
     ----------------------------------------------------------------- */
  it('login sets the refresh cookie for 400 days', async () => {
    replies['/v3/auth/login'] = rotated('rt.login');
    const res = await call(authProxy, 'login', {
      method: 'POST',
      body: { email: 'jane@example.com', password: 'x' },
    });

    expect(res.status).toBe(200);
    expect(setCookies(res)[0]).toContain('bayti_refresh=rt.login');
    expect(setCookies(res)[0]).toContain(MAX_AGE_400_DAYS);
  });
});

describe('auth-proxy social.ts / phone-claim-verify.ts cookie lifetime', () => {
  beforeEach(() => {
    calls = [];
    replies = {};
    installFetch();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('social sign-in sets the refresh cookie for 400 days', async () => {
    replies['/v3/auth/social'] = rotated('rt.social');
    const res = await call(socialProxy, 'social', { method: 'POST', body: { id_token: 'x' } });

    expect(res.status).toBe(200);
    expect(setCookies(res)[0]).toContain('bayti_refresh=rt.social');
    expect(setCookies(res)[0]).toContain(MAX_AGE_400_DAYS);
  });

  it('phone-claim merge sets the refresh cookie for 400 days', async () => {
    replies['/v3/me/phone/claim/verify'] = rotated('rt.claim');
    const res = await call(phoneClaimProxy, 'phone-claim-verify', {
      method: 'POST',
      auth: 'Bearer at.social',
      body: { verification_id: 'v', code: '123456' },
    });

    expect(res.status).toBe(200);
    expect(setCookies(res)[0]).toContain('bayti_refresh=rt.claim');
    expect(setCookies(res)[0]).toContain(MAX_AGE_400_DAYS);
  });
});
