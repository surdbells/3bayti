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

/**
 * refreshInterceptor — auth's HTTP interceptor.
 *
 * Two responsibilities
 * --------------------
 *   1. Attach `Authorization: Bearer <token>` to every outgoing
 *      request, IF a token is available AND the request isn't to
 *      the auth-proxy itself (the proxy routes use HttpOnly cookies,
 *      not Bearer headers).
 *
 *   2. On 401 from a non-auth-proxy request, attempt a single-flight
 *      refresh; if the refresh succeeds, retry the original request
 *      with the new token. If the refresh fails, propagate the 401
 *      so the calling page can route to /login.
 *
 * Why single-flight
 * -----------------
 * Without single-flight, an unauthenticated app load that fires 5
 * parallel requests would trigger 5 simultaneous /refresh calls; the
 * first would rotate the refresh token, invalidating the others, and
 * 4 of the 5 would fail. The AuthService.refresh() method already
 * implements single-flight (one inflight promise shared by all callers)
 * — this interceptor just hooks into it.
 *
 * Why AuthService is resolved LAZILY via Injector
 * -----------------------------------------------
 * The provideAuth() bundle registers BOTH this interceptor AND an
 * APP_INITIALIZER that calls AuthService.hydrate(). hydrate() makes
 * an HTTP request, which causes this interceptor function to run,
 * which would call inject(AuthService) — while AuthService is STILL
 * being constructed for the same APP_INITIALIZER. That's NG0200
 * (circular dependency).
 *
 * We break the cycle by injecting Injector at request-time but
 * resolving AuthService only when the 401-retry path actually
 * needs it. By then AuthService is fully constructed.
 *
 * What this interceptor explicitly does NOT do
 * --------------------------------------------
 *   - Doesn't infinitely retry. Each request gets exactly one
 *     refresh attempt; a second 401 propagates.
 *   - Doesn't refresh proactively. That's AuthService's scheduler.
 *   - Doesn't redirect to /login. That's the calling page's job
 *     (so that the page can preserve form state, returnUrl, etc.).
 *   - Doesn't fire on auth-proxy 401s. The /login endpoint's 401
 *     means "wrong credentials", not "stale token" — refreshing
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
      /* Only attempt refresh on 401 from non-auth-proxy requests.
         Other errors (network, 4xx other than 401, 5xx) propagate. */
      if (
        !(error instanceof HttpErrorResponse) ||
        error.status !== 401 ||
        requestIsAuthProxy
      ) {
        return throwError(() => error);
      }

      /* Defensive: don't try to refresh if the original request
         already lacked an Authorization header — there's no point
         refreshing a token we never tried in the first place; the
         endpoint just doesn't accept anonymous access. */
      const hadAuthHeader = initialRequest.headers.has('Authorization');
      if (!hadAuthHeader) {
        return throwError(() => error);
      }

      /* Trigger a single-flight refresh. AuthService deduplicates
         concurrent calls behind one inflight promise; the second,
         third, ... caller awaits the same result.

         injector.get(AuthService) here (not inject() at the top)
         breaks the APP_INITIALIZER cycle — see the file header. */
      const auth = injector.get(AuthService);
      return from(auth.refresh()).pipe(
        switchMap(success => {
          if (!success) {
            /* Refresh failed — session is dead. Propagate the
               original 401 so the page can route to /login. */
            return throwError(() => error);
          }
          /* Refresh succeeded — retry the original request with
             the new token. */
          const retried = attachAuthHeader(req, tokenStore.getToken());
          return next(retried);
        }),
      );
    }),
  );
};

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
  /* Relative proxyBase ('/auth-proxy') — match by url prefix. */
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

  /* Absolute proxyBase — match by full URL prefix. */
  return req.url.startsWith(proxyBase);
}
