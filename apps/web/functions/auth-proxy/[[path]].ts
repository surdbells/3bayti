/**
 * Auth-proxy — Cloudflare Pages Function.
 *
 * Handles `/auth-proxy/*` for the CSR storefront. This is the ONLY
 * server-side piece of the otherwise-static SPA: it owns the httpOnly
 * refresh-token cookie so the long-lived credential is never readable by
 * JavaScript (and therefore not exposed to XSS), while short-lived access
 * tokens live in the browser and are attached to API calls by the app's
 * HTTP interceptor.
 *
 * Ported verbatim from the previous SSR Worker's `handleAuthProxy` (the
 * SSR rendering half of that Worker is gone with the CSR migration). The
 * Angular AuthService calls the same `/auth-proxy/*` paths, so no client
 * changes were needed. Pages Functions run on the same edge runtime as
 * Workers and take routing precedence over the SPA `_redirects` fallback,
 * so these endpoints are never swallowed by it.
 *
 * Endpoints:
 *   GET  /auth-proxy/me            → refresh (rotates cookie) then /v3/auth/me
 *   POST /auth-proxy/refresh       → /v3/auth/refresh (rotates cookie)
 *   POST /auth-proxy/logout        → clears cookie + best-effort /v3/auth/logout
 *   POST /auth-proxy/login         → /v3/auth/login        (sets cookie on success)
 *   POST /auth-proxy/confirm       → /v3/auth/confirm      (sets cookie on success)
 *   POST /auth-proxy/reset-confirm → /v3/auth/reset/confirm(sets cookie on success)
 *   POST /auth-proxy/register      → /v3/auth/register     (passthrough)
 *   POST /auth-proxy/send-otp      → /v3/auth/send-otp     (passthrough)
 *   POST /auth-proxy/reset         → /v3/auth/reset        (passthrough)
 */

const UPSTREAM_BASE = 'https://api-v3.3bayti.ae';
const REFRESH_COOKIE = 'bayti_refresh';
const COOKIE_PATH = '/auth-proxy';
const COOKIE_MAX_AGE = 7 * 24 * 60 * 60;

function getRefreshToken(cookieHeader: string | null): string | null {
  if (!cookieHeader) return null;
  const match = cookieHeader.match(new RegExp(`(?:^|;\\s*)${REFRESH_COOKIE}=([^;]+)`));
  return match ? decodeURIComponent(match[1]) : null;
}

function setRefreshCookie(token: string, headers: Headers): void {
  headers.append(
    'Set-Cookie',
    `${REFRESH_COOKIE}=${encodeURIComponent(token)}; Path=${COOKIE_PATH}; HttpOnly; Secure; SameSite=Lax; Max-Age=${COOKIE_MAX_AGE}`,
  );
}

function clearRefreshCookie(headers: Headers): void {
  headers.append(
    'Set-Cookie',
    `${REFRESH_COOKIE}=; Path=${COOKIE_PATH}; HttpOnly; Secure; SameSite=Lax; Max-Age=0`,
  );
}

async function upstreamFetch(
  path: string,
  method: string,
  body: unknown,
  accessToken?: string,
  req?: Request,
): Promise<Response> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  if (accessToken) headers['Authorization'] = `Bearer ${accessToken}`;
  if (req) {
    const ip = req.headers.get('CF-Connecting-IP') ?? req.headers.get('X-Forwarded-For');
    if (ip) headers['X-Forwarded-For'] = ip;
    const ua = req.headers.get('User-Agent');
    if (ua) headers['User-Agent'] = ua;
  }
  return fetch(`${UPSTREAM_BASE}${path}`, {
    method,
    headers,
    body: body != null ? JSON.stringify(body) : undefined,
  });
}

function isAuthSuccess(
  d: unknown,
): d is {
  access_token: string;
  access_token_expires_at: string;
  refresh_token: string;
  refresh_token_expires_at: string;
  user: unknown;
} {
  return (
    typeof d === 'object' &&
    d !== null &&
    typeof (d as Record<string, unknown>)['access_token'] === 'string' &&
    typeof (d as Record<string, unknown>)['refresh_token'] === 'string'
  );
}

function stripped(d: Record<string, unknown>): Record<string, unknown> {
  const { refresh_token: _rt, ...rest } = d;
  void _rt;
  return rest;
}

async function handleAuthProxy(req: Request): Promise<Response> {
  const url = new URL(req.url);
  const seg = url.pathname.slice('/auth-proxy/'.length);
  const cookie = req.headers.get('Cookie');
  const rh = new Headers({ 'Content-Type': 'application/json' });

  if (seg === 'me' && req.method === 'GET') {
    const rt = getRefreshToken(cookie);
    if (!rt)
      return new Response(
        JSON.stringify({ error_code: 'AUTH_REFRESH_TOKEN_INVALID', message: 'No active session.' }),
        { status: 401, headers: rh },
      );
    const rr = await upstreamFetch('/v3/auth/refresh', 'POST', { refresh_token: rt }, undefined, req);
    const rd = await rr.json().catch(() => null);
    if (rr.status !== 200 || !isAuthSuccess(rd)) {
      clearRefreshCookie(rh);
      return new Response(JSON.stringify(rd), { status: rr.status, headers: rh });
    }
    setRefreshCookie(rd.refresh_token, rh);
    const mr = await upstreamFetch('/v3/auth/me', 'GET', null, rd.access_token, req);
    const md = await mr.json().catch(() => null);
    if (mr.status !== 200) {
      clearRefreshCookie(rh);
      return new Response(JSON.stringify(md), { status: mr.status, headers: rh });
    }
    return new Response(
      JSON.stringify({
        access_token: rd.access_token,
        access_token_expires_at: rd.access_token_expires_at,
        refresh_token_expires_at: rd.refresh_token_expires_at,
        /* /v3/auth/me returns the user nested as { user: {...} }; unwrap
           one level so /me matches the FLAT user shape that /login emits.
           Without this, AuthService.applyAuthState sets _currentUser to the
           wrapper and syncLocale crashes on the missing locale, wiping the
           session on every full page load. The `?? md` keeps us resilient
           if the upstream shape ever flattens. */
        user: (md as Record<string, unknown>)?.['user'] ?? md,
      }),
      { status: 200, headers: rh },
    );
  }

  if (seg === 'refresh' && req.method === 'POST') {
    const rt = getRefreshToken(cookie);
    if (!rt)
      return new Response(
        JSON.stringify({ error_code: 'AUTH_REFRESH_TOKEN_INVALID', message: 'No refresh token.' }),
        { status: 401, headers: rh },
      );
    const ur = await upstreamFetch('/v3/auth/refresh', 'POST', { refresh_token: rt }, undefined, req);
    const ud = await ur.json().catch(() => null);
    if (ur.status === 200 && isAuthSuccess(ud)) {
      setRefreshCookie(ud.refresh_token, rh);
      return new Response(JSON.stringify(stripped(ud as Record<string, unknown>)), { status: 200, headers: rh });
    }
    clearRefreshCookie(rh);
    return new Response(JSON.stringify(ud), { status: ur.status, headers: rh });
  }

  if (seg === 'logout' && req.method === 'POST') {
    const rt = getRefreshToken(cookie);
    clearRefreshCookie(rh);
    if (rt)
      await upstreamFetch('/v3/auth/logout', 'POST', { refresh_token: rt }, undefined, req).catch(
        () => null,
      );
    return new Response(null, { status: 204, headers: rh });
  }

  const authSuccessMap: Record<string, string> = {
    login: '/v3/auth/login',
    confirm: '/v3/auth/confirm',
    'reset-confirm': '/v3/auth/reset/confirm',
  };
  if (req.method === 'POST' && authSuccessMap[seg]) {
    const body = await req.json().catch(() => ({}));
    const ur = await upstreamFetch(authSuccessMap[seg], 'POST', body, undefined, req);
    const ud = await ur.json().catch(() => null);
    if ((ur.status === 200 || ur.status === 201) && isAuthSuccess(ud)) {
      setRefreshCookie(ud.refresh_token, rh);
      return new Response(JSON.stringify(stripped(ud as Record<string, unknown>)), {
        status: ur.status,
        headers: rh,
      });
    }
    return new Response(JSON.stringify(ud), { status: ur.status, headers: rh });
  }

  const passthroughMap: Record<string, string> = {
    register: '/v3/auth/register',
    'send-otp': '/v3/auth/send-otp',
    reset: '/v3/auth/reset',
  };
  if (req.method === 'POST' && passthroughMap[seg]) {
    const body = await req.json().catch(() => ({}));
    const ur = await upstreamFetch(passthroughMap[seg], 'POST', body, undefined, req);
    const ud = await ur.json().catch(() => null);
    return new Response(JSON.stringify(ud), { status: ur.status, headers: rh });
  }

  return new Response(
    JSON.stringify({ error_code: 'NOT_FOUND', message: 'Unknown auth-proxy endpoint.' }),
    { status: 404, headers: rh },
  );
}

/**
 * Cloudflare Pages Functions entry. `onRequest` receives an event context;
 * we only need the Request. Catch-all route `/auth-proxy/*` via the
 * `[[path]]` filename.
 */
export const onRequest = async (context: { request: Request }): Promise<Response> => {
  return handleAuthProxy(context.request);
};
