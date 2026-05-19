import type { AuthProxyConfig } from './config';

/**
 * Upstream API helper for the auth-proxy.
 *
 * Wraps `fetch` with:
 *   - Base URL prefix (so callers pass paths like '/v3/auth/login')
 *   - Request timeout via AbortController
 *   - Forwarded client-context headers (X-Forwarded-For, User-Agent)
 *   - Consistent error shape for the route handler
 *
 * Why an inner type rather than returning Response directly
 * ----------------------------------------------------------
 * Tests need to assert on body parsing AND header propagation. Returning
 * a typed object with `status`, `body`, and `headers` lets us mock the
 * call deterministically without dragging in fetch internals.
 *
 * Why NOT use the @3bayti/api-client package
 * -------------------------------------------
 * The api-client targets the BROWSER's HttpClient + bearer/refresh
 * interceptors. The BFF needs different semantics: no client-side
 * retry, explicit timeout, and the ability to forward raw JSON
 * unchanged. A thin wrapper is the right shape here.
 */

export interface UpstreamResponse<T = unknown> {
  /** HTTP status from the upstream. */
  status: number;
  /** Parsed JSON body (or null if body was empty / non-JSON). */
  body: T | null;
  /** Raw upstream body string — used when the caller wants to forward
   *  it unchanged (e.g. error responses with API-specific shapes). */
  raw: string;
}

export interface UpstreamContext {
  /** Inbound request's client IP. Forwarded to the API as X-Forwarded-For. */
  clientIp: string | null;
  /** Inbound request's user-agent. Forwarded as User-Agent. */
  userAgent: string | null;
  /** Accept-Language from inbound request. Forwarded for i18n. */
  acceptLanguage: string | null;
}

/**
 * Make a POST request to the upstream API.
 *
 * Returns an UpstreamResponse regardless of status — non-2xx is not a
 * thrown error. Network failures and timeouts ARE thrown so callers
 * can distinguish "upstream rejected" from "couldn't reach upstream".
 */
export async function upstreamPostJson<T = unknown>(
  path: string,
  body: unknown,
  context: UpstreamContext,
  config: AuthProxyConfig,
): Promise<UpstreamResponse<T>> {
  return upstreamCall<T>('POST', path, body, context, config);
}

/**
 * Make a GET request to the upstream API.
 *
 * Caller is responsible for any Authorization header — the upstream
 * helper does not infer one. (Auth flows for /me are routed
 * through /auth-proxy/refresh first to get an access token; the
 * route handler attaches Bearer when calling /v3/auth/me itself.)
 */
export async function upstreamGet<T = unknown>(
  path: string,
  authorization: string | null,
  context: UpstreamContext,
  config: AuthProxyConfig,
): Promise<UpstreamResponse<T>> {
  return upstreamCall<T>('GET', path, null, context, config, authorization);
}

/* ----------------------------------------------------------------
   Internals
   ---------------------------------------------------------------- */

async function upstreamCall<T>(
  method: 'GET' | 'POST',
  path: string,
  body: unknown,
  context: UpstreamContext,
  config: AuthProxyConfig,
  authorization: string | null = null,
): Promise<UpstreamResponse<T>> {
  const url = joinPath(config.upstreamBaseUrl, path);
  const headers: Record<string, string> = {
    Accept: 'application/json',
  };

  if (method === 'POST') {
    headers['Content-Type'] = 'application/json';
  }
  if (context.clientIp !== null) {
    headers['X-Forwarded-For'] = context.clientIp;
  }
  if (context.userAgent !== null) {
    headers['User-Agent'] = context.userAgent;
  }
  if (context.acceptLanguage !== null) {
    headers['Accept-Language'] = context.acceptLanguage;
  }
  if (authorization !== null) {
    headers['Authorization'] = authorization;
  }

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), config.upstreamTimeoutMs);

  let response: Response;
  try {
    response = await fetch(url, {
      method,
      headers,
      body: body === null || body === undefined ? undefined : JSON.stringify(body),
      signal: controller.signal,
    });
  } finally {
    clearTimeout(timeoutId);
  }

  /* Read the body as text first so we can return it raw on parse failure.
     Most v3 responses are JSON, but error pages from upstream proxies
     (Cloudflare, ALB) can be HTML. */
  const raw = await response.text();
  let parsed: T | null = null;
  if (raw !== '') {
    try {
      parsed = JSON.parse(raw) as T;
    } catch {
      /* Non-JSON body — pass through as raw. parsed remains null. */
    }
  }

  return {
    status: response.status,
    body: parsed,
    raw,
  };
}

/**
 * Join the base URL and path, handling trailing/leading slash mismatches.
 * Cheap and explicit; avoids reaching for URL.
 */
function joinPath(base: string, path: string): string {
  const trimmedBase = base.replace(/\/+$/, '');
  const trimmedPath = path.startsWith('/') ? path : `/${path}`;
  return `${trimmedBase}${trimmedPath}`;
}

/**
 * Extract the client IP from an Express request's headers.
 *
 * Cloudflare Workers + most proxies set X-Forwarded-For; the first
 * entry in the comma-separated list is the original client. If no
 * X-Forwarded-For, fall back to a CF-Connecting-IP header (Cloudflare-
 * specific) or null.
 *
 * We don't trust this for security purposes (forge-able); the API
 * uses it for rate-limiting + audit logging only.
 */
export function extractClientIp(headers: Record<string, string | string[] | undefined>): string | null {
  const xff = singleHeader(headers['x-forwarded-for']);
  if (xff !== null) {
    const first = xff.split(',')[0]?.trim();
    if (first !== undefined && first !== '') return first;
  }
  const cfIp = singleHeader(headers['cf-connecting-ip']);
  if (cfIp !== null && cfIp !== '') return cfIp;
  return null;
}

/**
 * Express normalises some headers to string[] when multiple values
 * arrived. Normalise back to a single string (first value wins) or null.
 */
function singleHeader(value: string | string[] | undefined): string | null {
  if (value === undefined) return null;
  if (Array.isArray(value)) return value[0] ?? null;
  return value;
}
