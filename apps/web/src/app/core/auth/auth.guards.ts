import { inject } from '@angular/core';
import {
  CanActivateFn,
  CanMatchFn,
  Router,
  UrlTree,
} from '@angular/router';
import { AuthService } from './auth.service';

/**
 * CanMatch guard, defers lazy-chunk loading for unauthenticated users.
 *
 * What this is for
 * ----------------
 * Applied to `/account/*` and `/checkout/*` route groups. When an
 * anonymous visitor hits one of those paths, the Angular Router
 * checks CanMatch BEFORE downloading the lazy chunk. If the guard
 * returns false (no auth), the router moves on to the next matching
 * route (typically a wildcard redirect or the auth pages), and the
 * lazy chunk is never fetched.
 *
 * Combined with `authActivateGuard` (below), this gives us both
 * - smaller bundle for anonymous users (CanMatch)
 * - re-checked auth at runtime (CanActivate)
 *
 * Behaviour
 * ---------
 *   - authenticated user → match (true)
 *   - anonymous user     → don't match (false). Caller route config
 *                          should provide a redirect to /login as the
 *                          fallback wildcard, or the user lands on a
 *                          404. Y.1's app.routes will wire the
 *                          redirect explicitly.
 *
 * Important
 * ---------
 * CanMatch CANNOT return a UrlTree, only a boolean. Use
 * authActivateGuard if you need to redirect with returnUrl semantics.
 */
export const authMatchGuard: CanMatchFn = () => {
  const auth = inject(AuthService);
  return auth.isAuthenticated();
};

/**
 * CanActivate guard, runtime auth check with returnUrl preservation.
 *
 * What this is for
 * ----------------
 * The runtime-re-check companion to authMatchGuard. Even though the
 * CanMatch guard prevents anon users from loading the chunk, this
 * one runs every navigation INTO the protected route, including
 * back/forward navigation, programmatic router.navigate, etc.
 *
 * Behaviour
 * ---------
 *   - authenticated user → activate (true)
 *   - anonymous user     → redirect to /login?returnUrl=<original>
 *                          via UrlTree (Angular's canonical pattern
 *                          for guard-based redirects). The login
 *                          page reads `returnUrl` from query params
 *                          and routes back after successful sign-in.
 *
 * Why two guards rather than one
 * ------------------------------
 * CanMatch and CanActivate have different return-type contracts.
 * CanMatch can't return a UrlTree, so it can't perform the redirect.
 * CanActivate can. Splitting the responsibility lets each guard do
 * what it does best.
 */
export const authActivateGuard: CanActivateFn = (_route, state): boolean | UrlTree => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (auth.isAuthenticated()) {
    return true;
  }

  /* Preserve the in-flight URL so the login page can return after
     successful auth. `state.url` includes the query string + fragment. */
  return router.createUrlTree(['/login'], {
    queryParams: { returnUrl: state.url },
  });
};

/**
 * Inverse guard, redirect AWAY from auth pages when already signed in.
 *
 * Used by /login, /register, /forgot-password, /reset-password so
 * authenticated users don't see those pages. Honours `returnUrl` if
 * present (e.g. user clicked "back" to a login they no longer need).
 */
export const guestActivateGuard: CanActivateFn = (route): boolean | UrlTree => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (!auth.isAuthenticated()) {
    return true;
  }

  const returnUrl = route.queryParamMap.get('returnUrl');
  if (isSafeInAppPath(returnUrl)) {
    return router.parseUrl(returnUrl);
  }
  return router.createUrlTree(['/']);
};

/**
 * Test whether a candidate returnUrl is safe to honour.
 *
 * Safe in-app paths START with exactly one '/'. The second-character
 * check rules out protocol-relative URLs like '//evil.example/' which
 * the URL parser would interpret as the attacker's host (they start
 * with '/' but the //host syntax means "same protocol, different
 * authority"). Other unsafe shapes:
 *
 *   - absolute URLs:        https://evil/, http://evil/
 *   - protocol-relative:    //evil/, //evil
 *   - non-path schemes:     javascript:, data:, mailto:
 *   - bare hostnames:       evil.example
 *
 * All are excluded by requiring [0] === '/' AND [1] !== '/'. A single
 * '/' is fine, that's the site root and a legitimate returnUrl.
 *
 * Type guard: narrows `value: string | null` to `value is string` so
 * callers can pass the result directly to router.parseUrl().
 */
function isSafeInAppPath(value: string | null): value is string {
  if (value === null || value.length === 0) return false;
  if (value[0] !== '/') return false;
  if (value.length > 1 && value[1] === '/') return false;
  return true;
}
