/**
 * Cloudflare Worker entry — runs Angular SSR for non-prerendered routes.
 *
 * How this fits into the request flow:
 *
 *   1. A request hits Cloudflare's edge.
 *   2. Workers + Static Assets routing: Cloudflare checks the assets
 *      manifest (built from `dist/3bayti-web/browser/`) FIRST. If a
 *      static file matches, it's served directly — never invokes this
 *      Worker. That covers:
 *        - / (prerendered home)
 *        - /category, /category/:slug for the 8 prerendered categories
 *        - /product/:slug for the top-200 prerendered products
 *        - hashed JS/CSS/woff2 assets, sitemap.xml, robots.txt
 *      This is the fast path: zero Worker invocations, full edge cache.
 *   3. If assets miss (e.g. /product/long-tail-slug-not-prerendered),
 *      Cloudflare invokes this Worker. We hand the Request to
 *      `AngularAppEngine.handle()`, which:
 *        - Looks up the matching Angular route (defined in
 *          app.routes.server.ts)
 *        - For RenderMode.Server (the default fallback for /product/:slug
 *          via PrerenderFallback.Server), it runs SSR and returns a
 *          fully-rendered Response.
 *        - Returns `null` if no Angular route matches — we then return
 *          a 404. (In practice this should be rare since `'**'` is
 *          configured as Prerender; non-matching paths typically resolve
 *          via the assets binding to index.html or fail there.)
 *
 * Why @angular/ssr (NOT @angular/ssr/node):
 *   The /node entry uses Express, Node Streams, and Node-only path APIs
 *   that don't exist in the Workers runtime. The platform-agnostic
 *   @angular/ssr export uses standard Web APIs (Request, Response) and
 *   runs natively on Workers, Deno, Bun, etc.
 *
 * Why nodejs_compat is still set in wrangler.jsonc:
 *   Some Angular runtime internals (e.g. zone.js shims, Buffer references
 *   in dependencies) expect Node globals to exist. The flag enables
 *   Cloudflare's polyfills without forcing us to use Node-specific APIs
 *   in our own code.
 */

import { AngularAppEngine, createRequestHandler } from '@angular/ssr';

const UPSTREAM_BASE = 'https://api-v3.3bayti.ae';
const REFRESH_COOKIE = 'bayti_refresh';
const COOKIE_PATH    = '/auth-proxy';
const COOKIE_MAX_AGE = 7 * 24 * 60 * 60;

function getRefreshToken(cookieHeader: string | null): string | null {
  if (!cookieHeader) return null;
  const match = cookieHeader.match(new RegExp(`(?:^|;\\s*)${REFRESH_COOKIE}=([^;]+)`));
  return match ? decodeURIComponent(match[1]) : null;
}

function setRefreshCookie(token: string, headers: Headers): void {
  headers.append('Set-Cookie',
    `${REFRESH_COOKIE}=${encodeURIComponent(token)}; Path=${COOKIE_PATH}; HttpOnly; Secure; SameSite=Lax; Max-Age=${COOKIE_MAX_AGE}`);
}

function clearRefreshCookie(headers: Headers): void {
  headers.append('Set-Cookie',
    `${REFRESH_COOKIE}=; Path=${COOKIE_PATH}; HttpOnly; Secure; SameSite=Lax; Max-Age=0`);
}

async function upstreamFetch(path: string, method: string, body: unknown, accessToken?: string, req?: Request): Promise<Response> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  if (accessToken) headers['Authorization'] = `Bearer ${accessToken}`;
  if (req) {
    const ip = req.headers.get('CF-Connecting-IP') ?? req.headers.get('X-Forwarded-For');
    if (ip) headers['X-Forwarded-For'] = ip;
    const ua = req.headers.get('User-Agent');
    if (ua) headers['User-Agent'] = ua;
  }
  return fetch(`${UPSTREAM_BASE}${path}`, {
    method, headers, body: body != null ? JSON.stringify(body) : undefined,
  });
}

function isAuthSuccess(d: unknown): d is { access_token: string; access_token_expires_at: string; refresh_token: string; refresh_token_expires_at: string; user: unknown } {
  return typeof d === 'object' && d !== null &&
    typeof (d as Record<string, unknown>)['access_token'] === 'string' &&
    typeof (d as Record<string, unknown>)['refresh_token'] === 'string';
}

function stripped(d: Record<string, unknown>): Record<string, unknown> {
  const { refresh_token: _rt, ...rest } = d; void _rt; return rest;
}

async function handleAuthProxy(req: Request): Promise<Response | null> {
  const url = new URL(req.url);
  if (!url.pathname.startsWith('/auth-proxy/')) return null;
  const seg = url.pathname.slice('/auth-proxy/'.length);
  const cookie = req.headers.get('Cookie');
  const rh = new Headers({ 'Content-Type': 'application/json' });

  if (seg === 'me' && req.method === 'GET') {
    const rt = getRefreshToken(cookie);
    if (!rt) return new Response(JSON.stringify({ error_code: 'AUTH_REFRESH_TOKEN_INVALID', message: 'No active session.' }), { status: 401, headers: rh });
    const rr = await upstreamFetch('/v3/auth/refresh', 'POST', { refresh_token: rt }, undefined, req);
    const rd = await rr.json().catch(() => null);
    if (rr.status !== 200 || !isAuthSuccess(rd)) { clearRefreshCookie(rh); return new Response(JSON.stringify(rd), { status: rr.status, headers: rh }); }
    setRefreshCookie(rd.refresh_token, rh);
    const mr = await upstreamFetch('/v3/auth/me', 'GET', null, rd.access_token, req);
    const md = await mr.json().catch(() => null);
    if (mr.status !== 200) { clearRefreshCookie(rh); return new Response(JSON.stringify(md), { status: mr.status, headers: rh }); }
    return new Response(JSON.stringify({ access_token: rd.access_token, access_token_expires_at: rd.access_token_expires_at, refresh_token_expires_at: rd.refresh_token_expires_at, user: md }), { status: 200, headers: rh });
  }

  if (seg === 'refresh' && req.method === 'POST') {
    const rt = getRefreshToken(cookie);
    if (!rt) return new Response(JSON.stringify({ error_code: 'AUTH_REFRESH_TOKEN_INVALID', message: 'No refresh token.' }), { status: 401, headers: rh });
    const ur = await upstreamFetch('/v3/auth/refresh', 'POST', { refresh_token: rt }, undefined, req);
    const ud = await ur.json().catch(() => null);
    if (ur.status === 200 && isAuthSuccess(ud)) { setRefreshCookie(ud.refresh_token, rh); return new Response(JSON.stringify(stripped(ud as Record<string,unknown>)), { status: 200, headers: rh }); }
    clearRefreshCookie(rh); return new Response(JSON.stringify(ud), { status: ur.status, headers: rh });
  }

  if (seg === 'logout' && req.method === 'POST') {
    const rt = getRefreshToken(cookie); clearRefreshCookie(rh);
    if (rt) await upstreamFetch('/v3/auth/logout', 'POST', { refresh_token: rt }, undefined, req).catch(() => null);
    return new Response(null, { status: 204, headers: rh });
  }

  const authSuccessMap: Record<string, string> = { 'login': '/v3/auth/login', 'confirm': '/v3/auth/confirm', 'reset-confirm': '/v3/auth/reset/confirm' };
  if (req.method === 'POST' && authSuccessMap[seg]) {
    const body = await req.json().catch(() => ({}));
    const ur = await upstreamFetch(authSuccessMap[seg], 'POST', body, undefined, req);
    const ud = await ur.json().catch(() => null);
    if ((ur.status === 200 || ur.status === 201) && isAuthSuccess(ud)) { setRefreshCookie(ud.refresh_token, rh); return new Response(JSON.stringify(stripped(ud as Record<string,unknown>)), { status: ur.status, headers: rh }); }
    return new Response(JSON.stringify(ud), { status: ur.status, headers: rh });
  }

  const passthroughMap: Record<string, string> = { 'register': '/v3/auth/register', 'send-otp': '/v3/auth/send-otp', 'reset': '/v3/auth/reset' };
  if (req.method === 'POST' && passthroughMap[seg]) {
    const body = await req.json().catch(() => ({}));
    const ur = await upstreamFetch(passthroughMap[seg], 'POST', body, undefined, req);
    const ud = await ur.json().catch(() => null);
    return new Response(JSON.stringify(ud), { status: ur.status, headers: rh });
  }

  return new Response(JSON.stringify({ error_code: 'NOT_FOUND', message: 'Unknown auth-proxy endpoint.' }), { status: 404, headers: rh });
}


/* Singleton — instantiated once per Worker isolate. The class internally
 * caches the parsed app manifest and entry-point loaders, so reusing the
 * same instance across requests avoids redundant work. */
const angularApp = new AngularAppEngine();

/**
 * Workers Assets binding — set in wrangler.jsonc's `assets.binding`.
 * Currently unused because we rely on Cloudflare's default static-first
 * routing (which serves assets BEFORE this Worker is invoked). We
 * declare the type here for future use (e.g. if we add edge-cached SSR
 * responses or programmatic asset lookups).
 */
interface Env {
  ASSETS: { fetch: (request: Request) => Promise<Response> };
}

export default {
  async fetch(request: Request, _env: Env, _ctx: ExecutionContext): Promise<Response> {
    try {
      /* Hand the request to Angular's SSR engine. Returns:
       *   - Response: SSR succeeded (or a prerendered page was served
       *     internally by AngularAppEngine — though in our setup
       *     prerendered pages are served by the assets binding before
       *     reaching here).
       *   - null: no Angular route matched the URL. */
      const authProxyResponse = await handleAuthProxy(request);
      if (authProxyResponse) return authProxyResponse;

      const response = await angularApp.handle(request);
      if (response) {
        return response;
      }

      /* No Angular route matched. This shouldn't normally happen given
       * our `'**' → Prerender` catch-all, but if it does, return a
       * minimal 404. (We don't proxy back to env.ASSETS here because
       * if assets had a match, the request never would have reached
       * this Worker in the first place.) */
      return new Response('Not Found', {
        status: 404,
        headers: { 'content-type': 'text/plain; charset=utf-8' },
      });
    } catch (err) {
      /* Last-resort error handler. We log to the Worker's stderr (visible
       * in Cloudflare's tail logs / Workers Logs) so we can debug, but
       * we don't leak the error message to the client — generic 500. */
      console.error('[worker.fetch] SSR error:', err);
      return new Response('Internal Server Error', {
        status: 500,
        headers: { 'content-type': 'text/plain; charset=utf-8' },
      });
    }
  },
};

/**
 * Request handler used by the Angular CLI's dev-server during build.
 *
 * The Angular build tooling looks for a default export AND an exported
 * `reqHandler`. The default export above is the runtime Worker handler;
 * `reqHandler` is what the build pipeline uses to perform prerendering
 * at compile time.
 */
export const reqHandler = createRequestHandler(async (req: Request) => {
  const response = await angularApp.handle(req);
  return response ?? new Response('Not Found', { status: 404 });
});
