import { inject, Injector } from '@angular/core';
import {
  HttpEvent,
  HttpHandlerFn,
  HttpInterceptorFn,
  HttpRequest,
  HttpErrorResponse,
} from '@angular/common/http';
import { Observable, from, throwError } from 'rxjs';
import { catchError, switchMap } from 'rxjs';
import { AccessTokenStore } from './access-token-store';
import { AuthService } from './auth.service';
import { AUTH_PROXY_BASE } from './auth.tokens';
import { AUTH_REFRESH_UNAVAILABLE, isStaleAccessToken401 } from './auth-errors';

/**
 * refreshInterceptor, auth's HTTP interceptor.
 *
 * Two responsibilities
 * --------------------
 *   1. Attach `Authorization: Bearer <token>` to every outgoing
 *      request, IF a token is available AND the request isn't to
 *      the auth-proxy itself (the proxy routes use HttpOnly cookies,
 *      not Bearer headers).
 *
 *   2. On a STALE-TOKEN 401 from a non-auth-proxy request (code
 *      AUTH_INVALID_TOKEN / AUTH_MISSING_TOKEN, or no code), attempt a
 *      single-flight refresh; if the refresh succeeds, retry the
 *      original request with the new token. Then:
 *        - session definitively ended → propagate the original 401 so
 *          the calling page can route to /login;
 *        - refresh merely unavailable (network, 5xx, ...) → fail the
 *          request with a status-0 connection error. The session is
 *          kept and AuthService retries in the background, so a
 *          transient problem never looks like "please sign in".
 *      A 401 with any other code (AUTH_INVALID_CREDENTIALS for a wrong
 *      current password, OTP_VERIFICATION_FAILED, ...) is a business
 *      error and passes through untouched, with no refresh.
 *      A request sent WITHOUT a token takes the same refresh path only
 *      while the session is still 'unknown' (transient first-load
 *      failure; the cookie may be valid), so it can't be mistaken for a
 *      signed-out visitor.
 *
 * Why single-flight
 * -----------------
 * Without single-flight, an unauthenticated app load that fires 5
 * parallel requests would trigger 5 simultaneous /refresh calls; the
 * first would rotate the refresh token, invalidating the others, and
 * 4 of the 5 would fail. The AuthService.refresh() method already
 * implements single-flight (one inflight promise shared by all callers)
 *, this interceptor just hooks into it.
 *
 * Why AuthService is resolved LAZILY via Injector
 * -----------------------------------------------
 * The provideAuth() bundle registers BOTH this interceptor AND an
 * APP_INITIALIZER that calls AuthService.hydrate(). hydrate() makes
 * an HTTP request, which causes this interceptor function to run,
 * which would call inject(AuthService), while AuthService is STILL
 * being constructed for the same APP_INITIALIZER. That's NG0200
 * (circular dependency).
 *
 * We break the cycle by injecting Injector at request-time but
 * resolving AuthService only when a 401 actually comes back. By then
 * AuthService is fully constructed.
 *
 * What this interceptor explicitly does NOT do
 * --------------------------------------------
 *   - Doesn't infinitely retry. Each request gets exactly one
 *     refresh attempt; a second 401 propagates.
 *   - Doesn't refresh proactively. That's AuthService's scheduler.
 *   - Doesn't redirect to /login. That's the calling page's job
 *     (so that the page can preserve form state, returnUrl, etc.).
 *   - Doesn't fire on auth-proxy 401s. The /login endpoint's 401
 *     means "wrong credentials", not "stale token", refreshing
 *     would be pointless and could mask the original error.
 */
export const refreshInterceptor: HttpInterceptorFn = (req, next) => {
  const tokenStore = inject(AccessTokenStore);
  const injector = inject(Injector);
  const proxyBase = inject(AUTH_PROXY_BASE);

  const requestIsAuthProxy = isAuthProxyRequest(req, proxyBase);

  /* Add Authorization header if (a) we have a token and (b) the
     request isn't to the auth-proxy. The auth-proxy uses cookies. */
  const initialRequest = requestIsAuthProxy ? req : attachAuthHeader(req, tokenStore.getToken());

  return next(initialRequest).pipe(
    catchError((error: unknown) => {
      /* Only attempt refresh on a stale-token 401 from non-auth-proxy
         requests. Other errors (network, 4xx other than 401, 5xx) and
         business 401s (wrong password / OTP) propagate untouched. */
      if (
        !(error instanceof HttpErrorResponse) ||
        !isStaleAccessToken401(error) ||
        requestIsAuthProxy
      ) {
        return throwError(() => error);
      }

      /* injector.get(AuthService) here (not inject() at the top)
         breaks the APP_INITIALIZER cycle, see the file header. */
      const auth = injector.get(AuthService);

      /* No Authorization header means there was no token to refresh:
         for a signed-out visitor the endpoint simply needs a session,
         so the 401 goes to the page (which routes to /login).

         Except while the session is still 'unknown' (the first-load
         hydrate hit a transient failure, so the store is empty but the
         HttpOnly cookie may be perfectly valid): a 401 then must not
         read as "please sign in". Resolve the session through the
         cookie first; the outcomes below handle it exactly like a
         stale token. */
      const hadAuthHeader = initialRequest.headers.has('Authorization');
      if (!hadAuthHeader && auth.sessionStatus() !== 'unknown') {
        return throwError(() => error);
      }

      /* Trigger a single-flight refresh. AuthService deduplicates
         concurrent calls behind one inflight promise; the second,
         third, ... caller awaits the same result. */
      return from(auth.refresh()).pipe(
        switchMap(outcome => {
          if (outcome === 'session-ended') {
            /* Session definitively over. Propagate the original 401
               so the page can route to /login. */
            return throwError(() => error);
          }
          if (outcome === 'unavailable') {
            /* Couldn't refresh right now; the session is intact and
               AuthService retries in the background. Fail THIS request
               as a connection error, never as a 401 (pages route 401s
               to /login). */
            return throwError(() => refreshUnavailableError(req));
          }
          /* Refresh succeeded, retry the original request with
             the new token. A stale-token 401 on the retry (a password
             change on another device landing in between, a proxy 401)
             is not a reason to sign the customer out: the session was
             just renewed, so fail as a connection error instead of a
             401 that pages route to /login. */
          const retried = attachAuthHeader(req, tokenStore.getToken());
          return next(retried).pipe(
            catchError((retryError: unknown) =>
              throwError(() =>
                retryError instanceof HttpErrorResponse && isStaleAccessToken401(retryError)
                  ? refreshUnavailableError(req)
                  : retryError,
              ),
            ),
          );
        }),
      );
    }),
  );
};

/**
 * The error a request fails with when its token refresh was merely
 * unavailable. Status 0 is what HttpClient reports for a network
 * failure, so pages (and mapApiErrors) show their usual connection
 * error rather than anything auth-shaped.
 */
function refreshUnavailableError(req: HttpRequest<unknown>): HttpErrorResponse {
  return new HttpErrorResponse({
    status: 0,
    statusText: 'Unknown Error',
    url: req.urlWithParams,
    error: {
      error: {
        code: AUTH_REFRESH_UNAVAILABLE,
        message: 'Could not reach the server. Please check your connection and try again.',
      },
    },
  });
}

/**
 * Add or replace the Authorization header on a request.
 *
 * Uses HttpRequest.clone to keep the request immutable (Angular
 * convention; the original `req` reference is shared with the rest
 * of the interceptor chain).
 */
function attachAuthHeader(req: HttpRequest<unknown>, token: string | null): HttpRequest<unknown> {
  if (token === null) return req;
  return req.clone({
    setHeaders: { Authorization: `Bearer ${token}` },
  });
}

/**
 * Detect whether the request targets the BFF auth proxy.
 *
 * Handles both absolute URLs (when AUTH_PROXY_BASE includes the
 * origin) and relative paths (the default). For relative paths,
 * we check by path prefix.
 */
function isAuthProxyRequest(req: HttpRequest<unknown>, proxyBase: string): boolean {
  /* Relative proxyBase ('/auth-proxy'), match by url prefix. */
  if (proxyBase.startsWith('/')) {
    /* HttpClient also supports absolute URLs; if a consumer passed
       an absolute URL that happens to share the proxy path, treat
       it the same way for safety. */
    try {
      const parsed = new URL(req.url, 'http://placeholder.local');
      return parsed.pathname.startsWith(proxyBase);
    } catch {
      return req.url.startsWith(proxyBase);
    }
  }

  /* Absolute proxyBase, match by full URL prefix. */
  return req.url.startsWith(proxyBase);
}
