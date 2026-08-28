/**
 * Auth-proxy /phone-claim-verify, Cloudflare Pages Function.
 *
 * Completes the "link my social sign-in to an existing account by phone-OTP"
 * merge for the web SPA. Unlike /social this is an authenticated /me call: it
 * forwards the CALLER's current access token (Authorization: Bearer …, the
 * throwaway social account) to /v3/me/phone/claim/verify. On success the API
 * returns a fresh login envelope for the EXISTING account it merged into, so we
 * park THAT account's refresh_token in the same HttpOnly cookie and return only
 * { access_token, …, user } — the SPA adopts it via applyAuthState, exactly
 * like /social. The refresh_token never reaches the browser's JS context.
 *
 * Cookie attributes, upstream base and forwarded-header handling are kept
 * byte-for-byte identical to social.ts / [[path]].ts so a session minted here
 * refreshes through the shared cookie model.
 *
 *   POST /auth-proxy/phone-claim-verify
 *        Authorization: Bearer <current access token>
 *        body { verification_id, code }
 *        → /v3/me/phone/claim/verify (sets refresh cookie on success)
 */

const UPSTREAM_BASE = 'https://api-v3.3bayti.ae';
const REFRESH_COOKIE = 'bayti_refresh';
const COOKIE_PATH = '/auth-proxy';
const COOKIE_MAX_AGE = 7 * 24 * 60 * 60;

function setRefreshCookie(token: string, headers: Headers): void {
  headers.append(
    'Set-Cookie',
    `${REFRESH_COOKIE}=${encodeURIComponent(token)}; Path=${COOKIE_PATH}; HttpOnly; Secure; SameSite=Lax; Max-Age=${COOKIE_MAX_AGE}`,
  );
}

async function upstreamFetch(path: string, method: string, body: unknown, req: Request): Promise<Response> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  // Forward the caller's bearer token so the /me call authenticates as the
  // social account initiating the merge.
  const auth = req.headers.get('Authorization');
  if (auth) headers['Authorization'] = auth;
  const ip = req.headers.get('CF-Connecting-IP') ?? req.headers.get('X-Forwarded-For');
  if (ip) headers['X-Forwarded-For'] = ip;
  const ua = req.headers.get('User-Agent');
  if (ua) headers['User-Agent'] = ua;
  return fetch(`${UPSTREAM_BASE}${path}`, {
    method,
    headers,
    body: body != null ? JSON.stringify(body) : undefined,
  });
}

function isAuthSuccess(
  d: unknown,
): d is { access_token: string; refresh_token: string; user: unknown } {
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

async function handle(req: Request): Promise<Response> {
  const rh = new Headers({ 'Content-Type': 'application/json' });

  if (req.method !== 'POST') {
    return new Response(
      JSON.stringify({ error_code: 'NOT_FOUND', message: 'Unknown auth-proxy endpoint.' }),
      { status: 404, headers: rh },
    );
  }

  const body = await req.json().catch(() => ({}));
  const ur = await upstreamFetch('/v3/me/phone/claim/verify', 'POST', body, req);
  const ud = await ur.json().catch(() => null);

  if ((ur.status === 200 || ur.status === 201) && isAuthSuccess(ud)) {
    setRefreshCookie(ud.refresh_token, rh);
    return new Response(JSON.stringify(stripped(ud as Record<string, unknown>)), {
      status: ur.status,
      headers: rh,
    });
  }

  // Upstream error (bad OTP, ambiguous/no account, expired token, …): relay
  // status + body verbatim; do NOT touch the cookie.
  return new Response(JSON.stringify(ud), { status: ur.status, headers: rh });
}

export const onRequest = async (context: { request: Request }): Promise<Response> => {
  return handle(context.request);
};
