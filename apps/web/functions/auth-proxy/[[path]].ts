/**
 * Auth-proxy, Cloudflare Pages Function.
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
 *
 * Passwordless parity (#8), mirrors the mobile v3 flows:
 *   POST /auth-proxy/otp-login/send      → /v3/auth/otp-login/send      (passthrough → {verification_id})
 *   POST /auth-proxy/otp-login/verify    → /v3/auth/otp-login/verify    (authSuccessMap → cookie)
 *   POST /auth-proxy/register/initiate   → /v3/auth/register/initiate   (passthrough → {verification_id})
 *   POST /auth-proxy/register/verify-phone → /v3/auth/register/verify-phone (passthrough → {registration_token})
 *   POST /auth-proxy/register/submit     → /v3/auth/register/submit     (passthrough → {verification_id})
 *   POST /auth-proxy/register/confirm-email → /v3/auth/register/confirm-email (authSuccessMap → cookie)
 *   POST /auth-proxy/validate-phone      → /v3/auth/validate-phone      (passthrough → {available})
 *
 * Session persistence (product rule: signed in until the user taps "Log
 * out"). The refresh cookie is cleared ONLY when /v3/auth/refresh answers
 * 401 with code AUTH_REFRESH_TOKEN_INVALID (or on /logout). Any other
 * refresh failure (network, timeout, 5xx, 429, 403, non-JSON) leaves the
 * cookie untouched and returns 503 AUTH_REFRESH_UNAVAILABLE, so the SPA
 * keeps the session and retries. The definitive case reaches the SPA as
 * 401 with that same code, which is how it tells the two apart.
 */

const UPSTREAM_BASE = 'https://api-v3.3bayti.ae';
const REFRESH_COOKIE = 'bayti_refresh';
const COOKIE_PATH = '/auth-proxy';
/* 400 days, the browser cap on cookie lifetime. The API refresh token
   lives 5 years and slides on every rotation; the cookie is re-set on
   every rotation too, so an active customer never ages out. Kept in sync
   by hand with social.ts / phone-claim-verify.ts (Pages Functions can't
   safely share a module here: files under functions/ become routes). */
const COOKIE_MAX_AGE = 34_560_000;
/* Upper bound for the refresh / me upstream calls, so a hung API surfaces
   as a transient 503 instead of a stalled page load. */
const UPSTREAM_TIMEOUT_MS = 15_000;
const SESSION_ENDED_CODE = 'AUTH_REFRESH_TOKEN_INVALID';
const REFRESH_UNAVAILABLE_CODE = 'AUTH_REFRESH_UNAVAILABLE';

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
  timeoutMs?: number,
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
    signal: timeoutMs !== undefined ? AbortSignal.timeout(timeoutMs) : undefined,
  });
}

/** API-native error envelope (contract: { error: { code, message } }). */
function errorJson(code: string, message: string): string {
  return JSON.stringify({ error: { code, message } });
}

/**
 * True only for the API's definitive refresh rejection: 401 with code
 * AUTH_REFRESH_TOKEN_INVALID (nested, or the legacy flat error_code).
 */
function isSessionEnded(status: number, body: unknown): boolean {
  if (status !== 401 || typeof body !== 'object' || body === null) return false;
  const record = body as Record<string, unknown>;
  const nested = record['error'];
  const code =
    typeof nested === 'object' && nested !== null
      ? (nested as Record<string, unknown>)['code']
      : record['error_code'];
  return code === SESSION_ENDED_CODE;
}

type AuthSuccess = {
  access_token: string;
  access_token_expires_at: string;
  refresh_token: string;
  refresh_token_expires_at: string;
  user: unknown;
};

type RefreshResult =
  | { kind: 'ok'; data: AuthSuccess }
  | { kind: 'ended' }
  | { kind: 'unavailable' };

/**
 * Rotate the refresh token upstream and classify the result. Never
 * throws: a network error or timeout is 'unavailable', like a 5xx.
 */
async function refreshUpstream(rt: string, req: Request): Promise<RefreshResult> {
  let ur: Response;
  try {
    ur = await upstreamFetch(
      '/v3/auth/refresh',
      'POST',
      { refresh_token: rt },
      undefined,
      req,
      UPSTREAM_TIMEOUT_MS,
    );
  } catch {
    return { kind: 'unavailable' };
  }
  const ud = await ur.json().catch(() => null);
  if (ur.status === 200 && isAuthSuccess(ud)) return { kind: 'ok', data: ud };
  if (isSessionEnded(ur.status, ud)) return { kind: 'ended' };
  /* Status only (no token / body) so `wrangler pages deployment tail`
     can tell an API outage from a proxy block. */
  console.warn('[auth-proxy] refresh upstream unavailable', ur.status);
  return { kind: 'unavailable' };
}

function sessionEndedResponse(rh: Headers): Response {
  return new Response(errorJson(SESSION_ENDED_CODE, 'The session has ended. Please sign in again.'), {
    status: 401,
    headers: rh,
  });
}

function refreshUnavailableResponse(rh: Headers): Response {
  return new Response(
    errorJson(REFRESH_UNAVAILABLE_CODE, 'Could not refresh the session right now. Please try again.'),
    { status: 503, headers: rh },
  );
}

function isAuthSuccess(d: unknown): d is AuthSuccess {
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

/** Pages Functions' EventContext.waitUntil: keeps work alive after the response. */
type WaitUntil = (promise: Promise<unknown>) => void;

async function handleAuthProxy(req: Request, waitUntil?: WaitUntil): Promise<Response> {
  const url = new URL(req.url);
  const seg = url.pathname.slice('/auth-proxy/'.length);
  const cookie = req.headers.get('Cookie');
  const rh = new Headers({ 'Content-Type': 'application/json' });

  if (seg === 'me' && req.method === 'GET') {
    const rt = getRefreshToken(cookie);
    if (!rt)
      return new Response(errorJson(SESSION_ENDED_CODE, 'No active session.'), {
        status: 401,
        headers: rh,
      });
    const rr = await refreshUpstream(rt, req);
    if (rr.kind === 'ended') {
      clearRefreshCookie(rh);
      return sessionEndedResponse(rh);
    }
    if (rr.kind === 'unavailable') {
      /* Transient: keep the cookie exactly as it is. */
      return refreshUnavailableResponse(rh);
    }
    const rd = rr.data;
    /* The rotation succeeded, so the old token is spent: ALWAYS persist
       the new one, whatever happens with /v3/auth/me below. */
    setRefreshCookie(rd.refresh_token, rh);
    let mr: Response;
    try {
      mr = await upstreamFetch('/v3/auth/me', 'GET', null, rd.access_token, req, UPSTREAM_TIMEOUT_MS);
    } catch {
      return refreshUnavailableResponse(rh);
    }
    const md = await mr.json().catch(() => null);
    if (mr.status !== 200 || md === null) {
      /* The session is fine (it just rotated); the profile read wasn't.
         503 carries the new cookie, never a clear. */
      return refreshUnavailableResponse(rh);
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
      return new Response(errorJson(SESSION_ENDED_CODE, 'No refresh token.'), {
        status: 401,
        headers: rh,
      });
    const rr = await refreshUpstream(rt, req);
    if (rr.kind === 'ok') {
      setRefreshCookie(rr.data.refresh_token, rh);
      return new Response(JSON.stringify(stripped(rr.data as Record<string, unknown>)), {
        status: 200,
        headers: rh,
      });
    }
    if (rr.kind === 'ended') {
      clearRefreshCookie(rh);
      return sessionEndedResponse(rh);
    }
    /* Transient: cookie untouched, the SPA keeps the session and retries. */
    return refreshUnavailableResponse(rh);
  }

  if (seg === 'logout' && req.method === 'POST') {
    /* The user's explicit logout: always clear the cookie. The API ends
       this browser's session family from the refresh token alone (no
       bearer needed) and never touches other devices. Best-effort, and
       finished in the background (waitUntil) so the cookie clear reaches
       the browser at once instead of after a slow upstream (up to
       UPSTREAM_TIMEOUT_MS). */
    const rt = getRefreshToken(cookie);
    clearRefreshCookie(rh);
    if (rt) {
      const revoke = upstreamFetch(
        '/v3/auth/logout',
        'POST',
        { refresh_token: rt },
        undefined,
        req,
        UPSTREAM_TIMEOUT_MS,
      ).catch(() => null);
      if (waitUntil) waitUntil(revoke);
      else await revoke;
    }
    return new Response(null, { status: 204, headers: rh });
  }

  const authSuccessMap: Record<string, string> = {
    login: '/v3/auth/login',
    confirm: '/v3/auth/confirm',
    'reset-confirm': '/v3/auth/reset/confirm',
    /* Passwordless parity (#8): both of these return the same token-pair
       envelope as /login, so they go through the cookie-setting path. */
    'otp-login/verify': '/v3/auth/otp-login/verify',
    'register/confirm-email': '/v3/auth/register/confirm-email',
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
    /* Passwordless parity (#8): no tokens issued at these steps, they
       hand back an opaque verification_id / registration_token, or an
       availability flag, so they pass straight through unchanged. */
    'otp-login/send': '/v3/auth/otp-login/send',
    'register/initiate': '/v3/auth/register/initiate',
    'register/verify-phone': '/v3/auth/register/verify-phone',
    'register/submit': '/v3/auth/register/submit',
    'validate-phone': '/v3/auth/validate-phone',
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
 * we need the Request and, for /logout, waitUntil. Catch-all route
 * `/auth-proxy/*` via the `[[path]]` filename.
 */
export const onRequest = async (context: {
  request: Request;
  waitUntil?: WaitUntil;
}): Promise<Response> => {
  return handleAuthProxy(context.request, context.waitUntil?.bind(context));
};
