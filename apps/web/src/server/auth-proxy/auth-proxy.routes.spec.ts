import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import express, { type Express } from 'express';
import request from 'supertest';
import { createAuthProxyRouter } from './auth-proxy.routes';
import { createAuthProxyConfig, type AuthProxyConfig } from './config';

/**
 * Tests for the auth-proxy router via supertest.
 *
 * Strategy
 * --------
 * Mount the real router in a real Express app and drive it via
 * supertest. The only mock is global `fetch`, which we replace with a
 * scripted response stack so each upstream URL gets the response the
 * test wants.
 *
 * What the suite verifies
 * -----------------------
 *   - Each route forwards to the correct upstream path
 *   - Auth-success routes (login, confirm, reset-confirm) strip
 *     refresh_token from the response body
 *   - Auth-success routes set the HttpOnly refresh cookie
 *   - Refresh route reads the cookie, calls upstream with refresh_token
 *     in body, swaps cookie on 200, returns 401 + clears on failure
 *   - Refresh route returns 401 without touching upstream when no
 *     cookie is present (saves a round-trip)
 *   - Logout always clears the cookie, even when upstream fails
 *   - /me does the two-step refresh + /v3/auth/me dance
 *   - Non-2xx upstream responses propagate verbatim (status + body)
 *   - Inbound client-context headers reach upstream
 *
 * What is NOT tested here
 * -----------------------
 *   - Real network behaviour, TLS, proxy-side cookie security flags
 *     in production — those are operational concerns covered by
 *     staging smoke + the Y.1-K operator playbook.
 *   - The Angular AuthService client-side behaviour — covered by
 *     auth.service.spec.ts via HttpClientTesting.
 */

interface ScriptedResponse {
  /** URL the upstream call must match (substring match). */
  urlContains: string;
  status: number;
  body?: unknown;
  /** When body is a JSON string the caller passed raw, set this true. */
  rawBody?: string;
}

/**
 * Install a global fetch mock that returns scripted responses in order
 * of URL match. Tests can also peek at every call after the fact.
 */
function installFetchMock(script: ScriptedResponse[]): {
  restore: () => void;
  calls: Array<{ url: string; init: RequestInit | undefined }>;
} {
  const calls: Array<{ url: string; init: RequestInit | undefined }> = [];
  const originalFetch = globalThis.fetch;
  const remaining = [...script];

  globalThis.fetch = vi.fn(async (...args: Parameters<typeof fetch>) => {
    const [input, init] = args;
    const url = typeof input === 'string' ? input : (input as URL | Request).toString();
    calls.push({ url, init });

    /* Find the first scripted response whose URL substring matches. */
    const matchIdx = remaining.findIndex(r => url.includes(r.urlContains));
    if (matchIdx < 0) {
      throw new Error(`[test mock fetch] No scripted response for URL: ${url}`);
    }
    const [matched] = remaining.splice(matchIdx, 1);

    const status = matched.status;
    const bodyForbidden = status === 204 || status === 205 || status === 304 || (status >= 100 && status < 200);
    const bodyString = bodyForbidden
      ? null
      : matched.rawBody !== undefined
        ? matched.rawBody
        : matched.body !== undefined
          ? JSON.stringify(matched.body)
          : '';
    return new Response(bodyString, {
      status,
      headers: {
        'Content-Type': matched.body !== undefined && matched.rawBody === undefined ? 'application/json' : 'text/plain',
      },
    });
  }) as unknown as typeof fetch;

  return {
    restore: () => {
      globalThis.fetch = originalFetch;
    },
    calls,
  };
}

/**
 * Build a fresh app + router for each test.
 * Config defaults to no-Secure so the test's plain-HTTP supertest
 * client sees the Set-Cookie attributes the same way a real browser
 * would on http://localhost.
 */
function makeApp(configOverrides: Partial<AuthProxyConfig> = {}): Express {
  const app = express();
  const config = createAuthProxyConfig({
    upstreamBaseUrl: 'https://api-v3.test.local',
    cookieSecure: false,
    ...configOverrides,
  });
  app.use('/auth-proxy', createAuthProxyRouter(config));
  return app;
}

/* ===================================================================
   Shared fixtures
   =================================================================== */

const authSuccessUpstream = {
  access_token: 'access.jwt.v1',
  access_token_expires_at: '2026-05-19T13:00:00+00:00',
  refresh_token: 'refresh.jwt.v1',
  refresh_token_expires_at: '2026-05-26T12:00:00+00:00',
  user: { id: 1, email: 'jane@example.com', is_phone_verified: true },
};

describe('auth-proxy router', () => {
  /* -----------------------------------------------------------------
     POST /auth-proxy/login
     ----------------------------------------------------------------- */
  describe('POST /login', () => {
    it('forwards to /v3/auth/login, strips refresh_token, sets HttpOnly cookie', async () => {
      const mock = installFetchMock([
        { urlContains: '/v3/auth/login', status: 200, body: authSuccessUpstream },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/login')
          .set('User-Agent', 'TestAgent/1.0')
          .send({ email: 'jane@example.com', password: 'secret' });

        expect(response.status).toBe(200);

        /* Refresh token MUST NOT appear in the JSON body. */
        expect(response.body.refresh_token).toBeUndefined();
        /* The rest of the auth-success body comes through. */
        expect(response.body.access_token).toBe('access.jwt.v1');
        expect(response.body.access_token_expires_at).toBe('2026-05-19T13:00:00+00:00');
        expect(response.body.refresh_token_expires_at).toBe('2026-05-26T12:00:00+00:00');
        expect(response.body.user).toEqual(authSuccessUpstream.user);

        /* Set-Cookie carries the refresh token with HttpOnly + Path scope. */
        const setCookie = response.headers['set-cookie'] as unknown as string[];
        expect(setCookie).toBeDefined();
        const cookie = Array.isArray(setCookie) ? setCookie[0] : setCookie;
        expect(cookie).toContain('bayti_refresh=refresh.jwt.v1');
        expect(cookie).toContain('HttpOnly');
        expect(cookie).toContain('Path=/auth-proxy');
        expect(cookie).toContain('SameSite=Lax');

        /* Upstream got the right URL + JSON body + forwarded headers. */
        expect(mock.calls).toHaveLength(1);
        expect(mock.calls[0].url).toBe('https://api-v3.test.local/v3/auth/login');
        expect(mock.calls[0].init?.method).toBe('POST');
        expect(mock.calls[0].init?.body).toBe(JSON.stringify({ email: 'jane@example.com', password: 'secret' }));
        const fwdHeaders = mock.calls[0].init?.headers as Record<string, string>;
        expect(fwdHeaders['User-Agent']).toBe('TestAgent/1.0');
      } finally {
        mock.restore();
      }
    });

    it('forwards a 401 from upstream verbatim and does NOT set a cookie', async () => {
      const mock = installFetchMock([
        {
          urlContains: '/v3/auth/login',
          status: 401,
          body: { error_code: 'AUTH_INVALID_CREDENTIALS', message: 'Wrong.' },
        },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/login')
          .send({ email: 'jane@example.com', password: 'wrong' });

        expect(response.status).toBe(401);
        expect(response.body.error_code).toBe('AUTH_INVALID_CREDENTIALS');
        expect(response.headers['set-cookie']).toBeUndefined();
      } finally {
        mock.restore();
      }
    });
  });

  /* -----------------------------------------------------------------
     POST /auth-proxy/register
     ----------------------------------------------------------------- */
  describe('POST /register', () => {
    it('forwards to /v3/auth/register, returns verification_id, does NOT set a cookie', async () => {
      const mock = installFetchMock([
        {
          urlContains: '/v3/auth/register',
          status: 201,
          body: {
            verification_id: 'mc-abc123',
            user: { email: 'new@example.com', phone: '+971501234567', country_code: 'AE', is_phone_verified: false },
          },
        },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/register')
          .send({
            email: 'new@example.com',
            phone: '+971501234567',
            password: 'pass1234',
            country_code: 'AE',
          });

        expect(response.status).toBe(201);
        expect(response.body.verification_id).toBe('mc-abc123');
        expect(response.body.user.is_phone_verified).toBe(false);
        /* Register doesn't issue tokens — no cookie. */
        expect(response.headers['set-cookie']).toBeUndefined();
      } finally {
        mock.restore();
      }
    });

    it('forwards 409 CONFLICT_EMAIL_TAKEN verbatim', async () => {
      const mock = installFetchMock([
        {
          urlContains: '/v3/auth/register',
          status: 409,
          body: { error_code: 'CONFLICT_EMAIL_TAKEN', message: 'Email already registered.' },
        },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/register')
          .send({ email: 'existing@example.com', phone: '+971501234567', password: 'pass1234' });

        expect(response.status).toBe(409);
        expect(response.body.error_code).toBe('CONFLICT_EMAIL_TAKEN');
      } finally {
        mock.restore();
      }
    });
  });

  /* -----------------------------------------------------------------
     POST /auth-proxy/confirm
     ----------------------------------------------------------------- */
  describe('POST /confirm', () => {
    it('forwards to /v3/auth/confirm and sets cookie on success (auto-login)', async () => {
      const mock = installFetchMock([
        { urlContains: '/v3/auth/confirm', status: 200, body: authSuccessUpstream },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/confirm')
          .send({ verification_id: 'mc-abc123', code: '123456' });

        expect(response.status).toBe(200);
        expect(response.body.refresh_token).toBeUndefined();
        expect(response.body.access_token).toBe('access.jwt.v1');
        const setCookie = response.headers['set-cookie'] as unknown as string[];
        expect(Array.isArray(setCookie) ? setCookie[0] : setCookie).toContain('bayti_refresh=refresh.jwt.v1');
      } finally {
        mock.restore();
      }
    });
  });

  /* -----------------------------------------------------------------
     POST /auth-proxy/send-otp
     ----------------------------------------------------------------- */
  describe('POST /send-otp', () => {
    it('forwards to /v3/auth/send-otp and returns the new verification_id', async () => {
      const mock = installFetchMock([
        { urlContains: '/v3/auth/send-otp', status: 200, body: { verification_id: 'mc-resent' } },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/send-otp')
          .send({ email: 'jane@example.com' });

        expect(response.status).toBe(200);
        expect(response.body.verification_id).toBe('mc-resent');
        expect(response.headers['set-cookie']).toBeUndefined();
      } finally {
        mock.restore();
      }
    });

    it('forwards 429 OTP_RATE_LIMITED verbatim', async () => {
      const mock = installFetchMock([
        {
          urlContains: '/v3/auth/send-otp',
          status: 429,
          body: { error_code: 'OTP_RATE_LIMITED', message: 'Too many.' },
        },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/send-otp')
          .send({ email: 'jane@example.com' });

        expect(response.status).toBe(429);
        expect(response.body.error_code).toBe('OTP_RATE_LIMITED');
      } finally {
        mock.restore();
      }
    });
  });

  /* -----------------------------------------------------------------
     POST /auth-proxy/reset
     ----------------------------------------------------------------- */
  describe('POST /reset', () => {
    it('forwards to /v3/auth/reset', async () => {
      const mock = installFetchMock([
        { urlContains: '/v3/auth/reset', status: 200, body: { verification_id: 'mc-reset' } },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/reset')
          .send({ email: 'jane@example.com' });

        expect(response.status).toBe(200);
        expect(response.body.verification_id).toBe('mc-reset');
      } finally {
        mock.restore();
      }
    });

    it('passes through fake-prefix verification_id without alteration (anti-enumeration)', async () => {
      const mock = installFetchMock([
        { urlContains: '/v3/auth/reset', status: 200, body: { verification_id: 'fake-aaa' } },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/reset')
          .send({ email: 'not-registered@example.com' });

        expect(response.status).toBe(200);
        expect(response.body.verification_id).toBe('fake-aaa');
      } finally {
        mock.restore();
      }
    });
  });

  /* -----------------------------------------------------------------
     POST /auth-proxy/reset-confirm
     ----------------------------------------------------------------- */
  describe('POST /reset-confirm', () => {
    it('forwards to /v3/auth/reset/confirm (note path shape difference)', async () => {
      const mock = installFetchMock([
        { urlContains: '/v3/auth/reset/confirm', status: 200, body: authSuccessUpstream },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/reset-confirm')
          .send({ verification_id: 'mc-reset', code: '123456', new_password: 'newpass1234' });

        expect(response.status).toBe(200);
        expect(response.body.refresh_token).toBeUndefined();
        expect(mock.calls[0].url).toBe('https://api-v3.test.local/v3/auth/reset/confirm');
        const setCookie = response.headers['set-cookie'] as unknown as string[];
        expect(Array.isArray(setCookie) ? setCookie[0] : setCookie).toContain('bayti_refresh=');
      } finally {
        mock.restore();
      }
    });
  });

  /* -----------------------------------------------------------------
     POST /auth-proxy/refresh
     ----------------------------------------------------------------- */
  describe('POST /refresh', () => {
    it('returns 401 without touching upstream when no cookie is present', async () => {
      const mock = installFetchMock([]);
      try {
        const app = makeApp();
        const response = await request(app).post('/auth-proxy/refresh');

        expect(response.status).toBe(401);
        expect(response.body.error_code).toBe('AUTH_REFRESH_TOKEN_INVALID');
        expect(mock.calls).toHaveLength(0);
      } finally {
        mock.restore();
      }
    });

    it('reads cookie, calls upstream with refresh_token in body, swaps cookie on 200', async () => {
      const rotatedSuccess = { ...authSuccessUpstream, refresh_token: 'refresh.jwt.v2' };
      const mock = installFetchMock([
        { urlContains: '/v3/auth/refresh', status: 200, body: rotatedSuccess },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/refresh')
          .set('Cookie', 'bayti_refresh=refresh.jwt.v1');

        expect(response.status).toBe(200);
        expect(response.body.refresh_token).toBeUndefined();
        expect(response.body.access_token).toBe('access.jwt.v1');

        /* Upstream call carried the cookie value in the body. */
        const body = JSON.parse(String(mock.calls[0].init?.body));
        expect(body).toEqual({ refresh_token: 'refresh.jwt.v1' });

        /* The NEW refresh token (v2) is in the swapped cookie. */
        const setCookie = response.headers['set-cookie'] as unknown as string[];
        const cookie = Array.isArray(setCookie) ? setCookie[0] : setCookie;
        expect(cookie).toContain('bayti_refresh=refresh.jwt.v2');
      } finally {
        mock.restore();
      }
    });

    it('clears cookie and forwards upstream error on refresh failure', async () => {
      const mock = installFetchMock([
        {
          urlContains: '/v3/auth/refresh',
          status: 401,
          body: { error_code: 'AUTH_REFRESH_TOKEN_EXPIRED', message: 'Expired.' },
        },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/refresh')
          .set('Cookie', 'bayti_refresh=old.token');

        expect(response.status).toBe(401);
        expect(response.body.error_code).toBe('AUTH_REFRESH_TOKEN_EXPIRED');

        const setCookie = response.headers['set-cookie'] as unknown as string[];
        const cookie = Array.isArray(setCookie) ? setCookie[0] : setCookie;
        expect(cookie).toContain('bayti_refresh=');
        expect(cookie).toContain('Max-Age=0');
      } finally {
        mock.restore();
      }
    });
  });

  /* -----------------------------------------------------------------
     POST /auth-proxy/logout
     ----------------------------------------------------------------- */
  describe('POST /logout', () => {
    it('clears cookie and returns 204 even with no cookie (idempotent client-side logout)', async () => {
      const mock = installFetchMock([]);
      try {
        const app = makeApp();
        const response = await request(app).post('/auth-proxy/logout');

        expect(response.status).toBe(204);
        expect(mock.calls).toHaveLength(0);

        /* Even without a cookie present, we emit a clear-cookie header.
           Belt-and-suspenders: helps in case the browser has a stale
           cookie this request didn't include for some reason. */
        const setCookie = response.headers['set-cookie'] as unknown as string[];
        const cookie = Array.isArray(setCookie) ? setCookie[0] : setCookie;
        expect(cookie).toContain('Max-Age=0');
      } finally {
        mock.restore();
      }
    });

    it('calls upstream /logout with cookie and clears local cookie', async () => {
      const mock = installFetchMock([
        { urlContains: '/v3/auth/logout', status: 204 },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/logout')
          .set('Cookie', 'bayti_refresh=tok.v1');

        expect(response.status).toBe(204);
        const body = JSON.parse(String(mock.calls[0].init?.body));
        expect(body).toEqual({ refresh_token: 'tok.v1' });

        const setCookie = response.headers['set-cookie'] as unknown as string[];
        const cookie = Array.isArray(setCookie) ? setCookie[0] : setCookie;
        expect(cookie).toContain('Max-Age=0');
      } finally {
        mock.restore();
      }
    });

    it('still clears cookie when upstream /logout returns 5xx', async () => {
      const mock = installFetchMock([
        { urlContains: '/v3/auth/logout', status: 502, body: { error_code: 'BAD_GATEWAY' } },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/logout')
          .set('Cookie', 'bayti_refresh=tok.v1');

        /* Local logout always succeeds regardless of upstream.
           User-visible session is gone; upstream cleanup is best-effort. */
        expect(response.status).toBe(204);
        const setCookie = response.headers['set-cookie'] as unknown as string[];
        const cookie = Array.isArray(setCookie) ? setCookie[0] : setCookie;
        expect(cookie).toContain('Max-Age=0');
      } finally {
        mock.restore();
      }
    });
  });

  /* -----------------------------------------------------------------
     GET /auth-proxy/me
     ----------------------------------------------------------------- */
  describe('GET /me', () => {
    it('returns 401 without touching upstream when no cookie is present', async () => {
      const mock = installFetchMock([]);
      try {
        const app = makeApp();
        const response = await request(app).get('/auth-proxy/me');

        expect(response.status).toBe(401);
        expect(response.body.error_code).toBe('AUTH_REFRESH_TOKEN_INVALID');
        expect(mock.calls).toHaveLength(0);
      } finally {
        mock.restore();
      }
    });

    it('does the two-step refresh + /me dance and returns a BffLoginResponse', async () => {
      const userPayload = { id: 1, email: 'jane@example.com', is_phone_verified: true };
      const mock = installFetchMock([
        { urlContains: '/v3/auth/refresh', status: 200, body: authSuccessUpstream },
        { urlContains: '/v3/auth/me', status: 200, body: userPayload },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .get('/auth-proxy/me')
          .set('Cookie', 'bayti_refresh=tok.v1');

        expect(response.status).toBe(200);
        expect(response.body.refresh_token).toBeUndefined();
        expect(response.body.access_token).toBe('access.jwt.v1');
        expect(response.body.user).toEqual(userPayload);

        /* Both upstream calls fired in order. */
        expect(mock.calls).toHaveLength(2);
        expect(mock.calls[0].url).toContain('/v3/auth/refresh');
        expect(mock.calls[1].url).toContain('/v3/auth/me');

        /* /me was called with the new access token from the refresh. */
        const meHeaders = mock.calls[1].init?.headers as Record<string, string>;
        expect(meHeaders['Authorization']).toBe(`Bearer ${authSuccessUpstream.access_token}`);

        /* Cookie was swapped to the new refresh token. */
        const setCookie = response.headers['set-cookie'] as unknown as string[];
        const cookie = Array.isArray(setCookie) ? setCookie[0] : setCookie;
        expect(cookie).toContain(`bayti_refresh=${authSuccessUpstream.refresh_token}`);
      } finally {
        mock.restore();
      }
    });

    it('clears cookie and forwards refresh error when the refresh step fails', async () => {
      const mock = installFetchMock([
        {
          urlContains: '/v3/auth/refresh',
          status: 401,
          body: { error_code: 'AUTH_REFRESH_TOKEN_EXPIRED' },
        },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .get('/auth-proxy/me')
          .set('Cookie', 'bayti_refresh=expired.tok');

        expect(response.status).toBe(401);
        expect(response.body.error_code).toBe('AUTH_REFRESH_TOKEN_EXPIRED');

        const setCookie = response.headers['set-cookie'] as unknown as string[];
        const cookie = Array.isArray(setCookie) ? setCookie[0] : setCookie;
        expect(cookie).toContain('Max-Age=0');

        /* Only one upstream call — we don't proceed to /me. */
        expect(mock.calls).toHaveLength(1);
      } finally {
        mock.restore();
      }
    });

    it('clears cookie and forwards error when /me itself fails after refresh succeeded', async () => {
      const mock = installFetchMock([
        { urlContains: '/v3/auth/refresh', status: 200, body: authSuccessUpstream },
        {
          urlContains: '/v3/auth/me',
          status: 403,
          body: { error_code: 'AUTH_ACCOUNT_INACTIVE' },
        },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .get('/auth-proxy/me')
          .set('Cookie', 'bayti_refresh=tok.v1');

        expect(response.status).toBe(403);
        expect(response.body.error_code).toBe('AUTH_ACCOUNT_INACTIVE');

        const setCookie = response.headers['set-cookie'] as unknown as string[];
        /* setCookie contains BOTH the swap-from-refresh AND the clear.
           Last write wins on the browser side, but we expose both
           Set-Cookie values via response.append. Find the clear. */
        const headers = Array.isArray(setCookie) ? setCookie : [setCookie];
        const hasClear = headers.some(h => typeof h === 'string' && h.includes('Max-Age=0'));
        expect(hasClear).toBe(true);
      } finally {
        mock.restore();
      }
    });
  });

  /* -----------------------------------------------------------------
     Header forwarding
     ----------------------------------------------------------------- */
  describe('header forwarding', () => {
    it('forwards X-Forwarded-For (first entry only), User-Agent, and Accept-Language to upstream', async () => {
      const mock = installFetchMock([
        { urlContains: '/v3/auth/login', status: 200, body: authSuccessUpstream },
      ]);
      try {
        const app = makeApp();
        await request(app)
          .post('/auth-proxy/login')
          .set('X-Forwarded-For', '203.0.113.7, 198.51.100.1')
          .set('User-Agent', 'CustomAgent')
          .set('Accept-Language', 'ar-AE,ar;q=0.9')
          .send({ email: 'jane@example.com', password: 'secret' });

        const headers = mock.calls[0].init?.headers as Record<string, string>;
        expect(headers['X-Forwarded-For']).toBe('203.0.113.7');
        expect(headers['User-Agent']).toBe('CustomAgent');
        expect(headers['Accept-Language']).toBe('ar-AE,ar;q=0.9');
      } finally {
        mock.restore();
      }
    });
  });

  /* -----------------------------------------------------------------
     BFF upstream-error handling
     ----------------------------------------------------------------- */
  describe('BFF upstream-error handling', () => {
    it('returns 502 BFF_UPSTREAM_ERROR when fetch itself throws (network error)', async () => {
      const originalFetch = globalThis.fetch;
      globalThis.fetch = vi.fn(async () => {
        throw new TypeError('fetch failed: ECONNREFUSED');
      }) as unknown as typeof fetch;
      /* The route handler intentionally logs this via console.error;
         silence it for the duration of the test so the suite output
         stays readable. */
      const errSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined);

      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/login')
          .send({ email: 'jane@example.com', password: 'secret' });

        expect(response.status).toBe(502);
        expect(response.body.error_code).toBe('BFF_UPSTREAM_ERROR');
        /* The error WAS logged — verify the diagnostic path fired. */
        expect(errSpy).toHaveBeenCalled();
      } finally {
        globalThis.fetch = originalFetch;
        errSpy.mockRestore();
      }
    });

    it('returns non-JSON upstream body as text/plain with original status', async () => {
      const mock = installFetchMock([
        { urlContains: '/v3/auth/login', status: 503, rawBody: '<html>upstream maintenance</html>' },
      ]);
      try {
        const app = makeApp();
        const response = await request(app)
          .post('/auth-proxy/login')
          .send({ email: 'jane@example.com', password: 'secret' });

        expect(response.status).toBe(503);
        expect(response.text).toContain('upstream maintenance');
      } finally {
        mock.restore();
      }
    });
  });
});
