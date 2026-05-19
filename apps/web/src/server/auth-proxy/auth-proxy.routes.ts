import express, { Router, Request, Response } from 'express';
import type { AuthProxyConfig } from './config';
import {
  buildRefreshCookie,
  buildClearRefreshCookie,
  parseRefreshCookie,
} from './cookies';
import {
  upstreamPostJson,
  upstreamGet,
  extractClientIp,
  type UpstreamContext,
  type UpstreamResponse,
} from './upstream';

/**
 * Auth-proxy Express router.
 *
 * Exposes 8 endpoints that the Angular AuthService calls:
 *
 *   POST /auth-proxy/login           — credential login
 *   POST /auth-proxy/register        — new account, no tokens issued
 *   POST /auth-proxy/confirm         — OTP confirm, auto-login
 *   POST /auth-proxy/send-otp        — resend registration OTP
 *   POST /auth-proxy/reset           — request password-reset OTP
 *   POST /auth-proxy/reset-confirm   — OTP + new password, auto-login
 *   POST /auth-proxy/refresh         — refresh access token from cookie
 *   POST /auth-proxy/logout          — invalidate refresh + clear cookie
 *   GET  /auth-proxy/me              — hydrate session via cookie
 *
 * Responsibilities
 * ----------------
 *   - Forward to the upstream v3 API at the matching path
 *   - On successful auth response: park refresh_token as HttpOnly cookie
 *     and STRIP it from the JSON body before forwarding to browser
 *   - On logout: clear the cookie regardless of upstream outcome
 *   - On refresh: read the cookie, attach to upstream call, swap on success
 *   - On /me: read the cookie, call /refresh upstream to get a fresh
 *     access token, then call /v3/auth/me with Bearer
 *
 * Why /me indirection through /refresh
 * ------------------------------------
 * The browser hits /auth-proxy/me on every page load (via APP_INITIALIZER
 * in auth.providers.ts). The browser has NO access token at that moment
 * (in-memory store is empty on cold load). So /me must first refresh
 * the access token using the cookie. This is the SSR-equivalent of
 * "you just came back to the site; let me re-establish your session".
 *
 * Single-use refresh tokens
 * -------------------------
 * The API rotates refresh tokens on /refresh. Every call swaps the
 * cookie. This is correct behaviour; concurrent calls from the same
 * browser race against each other but the API serialises per-user
 * (one valid token at a time).
 *
 * Error forwarding
 * ----------------
 * Non-2xx upstream responses are forwarded verbatim (status + body)
 * so the Angular page components see exactly what the API emitted.
 * The only mutation we make is stripping refresh_token from 200/201
 * bodies on auth endpoints — error bodies pass through untouched.
 */

interface AuthSuccessBody {
  access_token: string;
  access_token_expires_at: string;
  refresh_token: string;
  refresh_token_expires_at: string;
  user: unknown;
}

function isAuthSuccessBody(body: unknown): body is AuthSuccessBody {
  return (
    typeof body === 'object' &&
    body !== null &&
    typeof (body as { access_token?: unknown }).access_token === 'string' &&
    typeof (body as { refresh_token?: unknown }).refresh_token === 'string' &&
    typeof (body as { user?: unknown }).user === 'object'
  );
}

/**
 * Build the Express router. Accepts config so tests can supply their
 * own (test upstream URL, no-Secure for plain HTTP, etc.).
 */
export function createAuthProxyRouter(config: AuthProxyConfig): Router {
  const router = Router();

  /* Body parser scoped to this router so we don't interfere with the
     Angular SSR fallback's handling of arbitrary request bodies. */
  router.use(express.json({ limit: '1mb' }));

  /* Extract context once per request; route handlers reuse it. */
  router.use((req, _res, next) => {
    const context: UpstreamContext = {
      clientIp: extractClientIp(req.headers),
      userAgent: singleHeader(req.headers['user-agent']),
      acceptLanguage: singleHeader(req.headers['accept-language']),
    };
    (req as RequestWithContext).upstreamContext = context;
    next();
  });

  /* --------------------------------------------------------------
     POST /auth-proxy/login
     -------------------------------------------------------------- */
  router.post('/login', async (req, res) => {
    await proxyAuthSuccess(
      '/v3/auth/login',
      req,
      res,
      config,
    );
  });

  /* --------------------------------------------------------------
     POST /auth-proxy/register
     No cookie set — registration doesn't issue tokens yet.
     -------------------------------------------------------------- */
  router.post('/register', async (req, res) => {
    const upstream = await upstreamPostJson(
      '/v3/auth/register',
      req.body,
      (req as RequestWithContext).upstreamContext,
      config,
    );
    forwardUpstream(upstream, res);
  });

  /* --------------------------------------------------------------
     POST /auth-proxy/confirm
     Auth response with tokens — strip refresh, set cookie.
     -------------------------------------------------------------- */
  router.post('/confirm', async (req, res) => {
    await proxyAuthSuccess(
      '/v3/auth/confirm',
      req,
      res,
      config,
    );
  });

  /* --------------------------------------------------------------
     POST /auth-proxy/send-otp
     No tokens. Pass through.
     -------------------------------------------------------------- */
  router.post('/send-otp', async (req, res) => {
    const upstream = await upstreamPostJson(
      '/v3/auth/send-otp',
      req.body,
      (req as RequestWithContext).upstreamContext,
      config,
    );
    forwardUpstream(upstream, res);
  });

  /* --------------------------------------------------------------
     POST /auth-proxy/reset
     No tokens. Pass through.
     -------------------------------------------------------------- */
  router.post('/reset', async (req, res) => {
    const upstream = await upstreamPostJson(
      '/v3/auth/reset',
      req.body,
      (req as RequestWithContext).upstreamContext,
      config,
    );
    forwardUpstream(upstream, res);
  });

  /* --------------------------------------------------------------
     POST /auth-proxy/reset-confirm
     Note the path SHAPE difference: BFF '/reset-confirm' (one segment)
     maps to upstream '/v3/auth/reset/confirm' (sub-path). The BFF
     uses dashes for routability simplicity; the upstream uses
     slashes for REST hierarchy.
     -------------------------------------------------------------- */
  router.post('/reset-confirm', async (req, res) => {
    await proxyAuthSuccess(
      '/v3/auth/reset/confirm',
      req,
      res,
      config,
    );
  });

  /* --------------------------------------------------------------
     POST /auth-proxy/refresh
     Read cookie → call upstream with refresh_token in body → on success,
     swap the cookie + strip refresh_token from response. Single-use
     rotation is enforced server-side; the BFF just shuttles the new
     token into the cookie.
     -------------------------------------------------------------- */
  router.post('/refresh', async (req, res) => {
    const cookieToken = parseRefreshCookie(req.headers.cookie, config);
    if (cookieToken === null) {
      /* No cookie — return 401 directly without touching upstream.
         Saves a network round-trip on the very common "anonymous
         visitor / cold tab" case. */
      res.status(401).json({
        error_code: 'AUTH_REFRESH_TOKEN_INVALID',
        message: 'No refresh token present.',
      });
      return;
    }

    const upstream = await upstreamPostJson<AuthSuccessBody>(
      '/v3/auth/refresh',
      { refresh_token: cookieToken },
      (req as RequestWithContext).upstreamContext,
      config,
    );

    if (upstream.status === 200 && isAuthSuccessBody(upstream.body)) {
      /* Set NEW cookie (single-use rotation). Strip refresh_token
         from the JSON body before forwarding. */
      setRefreshCookie(res, upstream.body.refresh_token, config);
      sendStripped(res, upstream);
      return;
    }

    /* Refresh failed — clear the cookie so subsequent requests don't
       keep retrying with a known-bad token. */
    clearRefreshCookie(res, config);
    forwardUpstream(upstream, res);
  });

  /* --------------------------------------------------------------
     POST /auth-proxy/logout
     Always clear the cookie. Upstream call is best-effort: if it
     fails (network, 5xx), we still clear locally so the client side
     ends up logged-out.
     -------------------------------------------------------------- */
  router.post('/logout', async (req, res) => {
    const cookieToken = parseRefreshCookie(req.headers.cookie, config);

    /* Clear local cookie first, regardless of what upstream does. */
    clearRefreshCookie(res, config);

    if (cookieToken === null) {
      /* No cookie to invalidate — return 204 directly. */
      res.status(204).end();
      return;
    }

    try {
      await upstreamPostJson(
        '/v3/auth/logout',
        { refresh_token: cookieToken },
        (req as RequestWithContext).upstreamContext,
        config,
      );
    } catch {
      /* Swallow — local logout always succeeds. */
    }

    res.status(204).end();
  });

  /* --------------------------------------------------------------
     GET /auth-proxy/me
     Two-step: refresh access token from cookie, then call /v3/auth/me
     with the new access token. On any failure: clear cookie + 401.
     -------------------------------------------------------------- */
  router.get('/me', async (req, res) => {
    const cookieToken = parseRefreshCookie(req.headers.cookie, config);
    if (cookieToken === null) {
      res.status(401).json({
        error_code: 'AUTH_REFRESH_TOKEN_INVALID',
        message: 'No active session.',
      });
      return;
    }

    /* Step 1: refresh. */
    const refreshResp = await upstreamPostJson<AuthSuccessBody>(
      '/v3/auth/refresh',
      { refresh_token: cookieToken },
      (req as RequestWithContext).upstreamContext,
      config,
    );

    if (refreshResp.status !== 200 || !isAuthSuccessBody(refreshResp.body)) {
      clearRefreshCookie(res, config);
      /* Forward upstream's status + body so the client sees what
         actually went wrong (e.g. AUTH_REFRESH_TOKEN_EXPIRED). */
      forwardUpstream(refreshResp, res);
      return;
    }

    /* Refresh succeeded — swap the cookie. */
    setRefreshCookie(res, refreshResp.body.refresh_token, config);

    /* Step 2: call /me with the new access token. The body we'll
       return to the browser is the SAME shape as /login: stripped
       refresh_token + user. /me returns just the user, so we build
       the BffLoginResponse by combining refresh data + /me data. */
    const meResp = await upstreamGet<unknown>(
      '/v3/auth/me',
      `Bearer ${refreshResp.body.access_token}`,
      (req as RequestWithContext).upstreamContext,
      config,
    );

    if (meResp.status !== 200) {
      /* Unusual: refresh succeeded but /me failed. Could happen if
         the user was deactivated between refresh and /me. Clear and
         forward. */
      clearRefreshCookie(res, config);
      forwardUpstream(meResp, res);
      return;
    }

    res.status(200).json({
      access_token: refreshResp.body.access_token,
      access_token_expires_at: refreshResp.body.access_token_expires_at,
      refresh_token_expires_at: refreshResp.body.refresh_token_expires_at,
      user: meResp.body,
    });
  });

  /* --------------------------------------------------------------
     Error handler — catches anything route handlers throw.
     -------------------------------------------------------------- */
  router.use((err: unknown, _req: Request, res: Response, _next: express.NextFunction) => {
    /* Don't leak error details — the upstream API has its own error
       shapes; anything that bubbles up here is a BFF-side bug. */
    if (typeof console !== 'undefined') {
      console.error('[auth-proxy] unhandled error', err);
    }
    res.status(502).json({
      error_code: 'BFF_UPSTREAM_ERROR',
      message: 'Could not reach the authentication service. Please try again.',
    });
  });

  return router;
}

/* ----------------------------------------------------------------
   Shared route helpers
   ---------------------------------------------------------------- */

/**
 * Run an auth-success-shaped request (login, confirm, reset-confirm):
 * upstream call → on 2xx with tokens, strip refresh + set cookie.
 */
async function proxyAuthSuccess(
  upstreamPath: string,
  req: Request,
  res: Response,
  config: AuthProxyConfig,
): Promise<void> {
  const upstream = await upstreamPostJson<AuthSuccessBody>(
    upstreamPath,
    req.body,
    (req as RequestWithContext).upstreamContext,
    config,
  );

  if (
    (upstream.status === 200 || upstream.status === 201) &&
    isAuthSuccessBody(upstream.body)
  ) {
    setRefreshCookie(res, upstream.body.refresh_token, config);
    sendStripped(res, upstream);
    return;
  }

  /* Non-2xx or unexpected body shape → forward as-is. */
  forwardUpstream(upstream, res);
}

/**
 * Forward an UpstreamResponse to the client unchanged (status + body).
 *
 * The raw text is used when the body wasn't JSON (so the upstream
 * error page passes through readable). Content-Type is inferred:
 * JSON if body parsed, otherwise text/plain.
 */
function forwardUpstream<T>(upstream: UpstreamResponse<T>, res: Response): void {
  if (upstream.body !== null) {
    res.status(upstream.status).json(upstream.body);
    return;
  }
  if (upstream.raw === '') {
    res.status(upstream.status).end();
    return;
  }
  res.status(upstream.status).type('text/plain').send(upstream.raw);
}

/**
 * Send a stripped auth-success response: same as forwardUpstream but
 * removes `refresh_token` from the JSON body so the browser never
 * sees it.
 */
function sendStripped<T extends AuthSuccessBody>(
  res: Response,
  upstream: UpstreamResponse<T>,
): void {
  if (upstream.body === null) {
    /* Shouldn't happen — caller checked isAuthSuccessBody. Defensive. */
    res.status(upstream.status).end();
    return;
  }
  const { refresh_token, ...rest } = upstream.body;
  /* refresh_token is intentionally extracted-and-discarded.
     Lint will mark refresh_token as "unused"; that's the point. */
  void refresh_token;
  res.status(upstream.status).json(rest);
}

function setRefreshCookie(res: Response, token: string, config: AuthProxyConfig): void {
  /* Use Express's setHeader to allow stacking multiple Set-Cookies
     (e.g. someone earlier in the chain set a locale cookie). The
     'set' method overwrites; we need to APPEND. */
  res.append('Set-Cookie', buildRefreshCookie(token, config));
}

function clearRefreshCookie(res: Response, config: AuthProxyConfig): void {
  res.append('Set-Cookie', buildClearRefreshCookie(config));
}

function singleHeader(value: string | string[] | undefined): string | null {
  if (value === undefined) return null;
  if (Array.isArray(value)) return value[0] ?? null;
  return value;
}

/**
 * Local type extension to carry per-request UpstreamContext through
 * the middleware chain without polluting Express's global types.
 */
interface RequestWithContext extends Request {
  upstreamContext: UpstreamContext;
}
