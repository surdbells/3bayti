/**
 * Auth-proxy /social, Cloudflare Pages Function.
 *
 * Sibling of the catch-all `[[path]].ts` auth-proxy router. A more-specific
 * filename, so Pages routes POST /auth-proxy/social here in preference to the
 * `[[path]]` catch-all (which never registers a `social` segment).
 *
 * MIRRORS the `login` branch of [[path]].ts exactly: it exchanges a Firebase
 * ID token for the standard login token-pair envelope, parks the long-lived
 * refresh_token in the SAME HttpOnly cookie the rest of auth-proxy uses, and
 * returns only { access_token, access_token_expires_at, ..., user } to the
 * SPA. The refresh_token never reaches the browser's JS context.
 *
 * Endpoint:
 *   POST /auth-proxy/social  body { id_token, first_name?, last_name? }
 *                            → /v3/auth/social (sets refresh cookie on success)
 *
 * The cookie attributes (name, path, max-age, HttpOnly/Secure/SameSite),
 * upstream base, and forwarded-header handling are kept byte-for-byte
 * identical to [[path]].ts so the two stay interchangeable for the cookie
 * model (a session minted here refreshes through [[path]].ts and vice-versa).
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

async function upstreamFetch(
  path: string,
  method: string,
  body: unknown,
  req?: Request,
): Promise<Response> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
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

async function handleSocial(req: Request): Promise<Response> {
  const rh = new Headers({ 'Content-Type': 'application/json' });

  if (req.method !== 'POST') {
    return new Response(
      JSON.stringify({ error_code: 'NOT_FOUND', message: 'Unknown auth-proxy endpoint.' }),
      { status: 404, headers: rh },
    );
  }

  /* Pass the body straight through to /v3/auth/social. The API accepts
     { id_token, first_name?, last_name? }; we forward whatever the SPA
     sends rather than re-validate here. */
  const body = await req.json().catch(() => ({}));
  const ur = await upstreamFetch('/v3/auth/social', 'POST', body, req);
  const ud = await ur.json().catch(() => null);

  if ((ur.status === 200 || ur.status === 201) && isAuthSuccess(ud)) {
    setRefreshCookie(ud.refresh_token, rh);
    return new Response(JSON.stringify(stripped(ud as Record<string, unknown>)), {
      status: ur.status,
      headers: rh,
    });
  }

  /* Upstream error (bad/expired id_token, etc.), relay status + body
     verbatim; do NOT touch the cookie. */
  return new Response(JSON.stringify(ud), { status: ur.status, headers: rh });
}

/**
 * Cloudflare Pages Functions entry. `onRequest` receives an event context;
 * we only need the Request. Filename `social.ts` binds this to the exact
 * path `/auth-proxy/social`.
 */
export const onRequest = async (context: { request: Request }): Promise<Response> => {
  return handleSocial(context.request);
};
