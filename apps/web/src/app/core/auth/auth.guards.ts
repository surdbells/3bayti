import { inject } from '@angular/core';
import {
  CanActivateFn,
  CanMatchFn,
  Router,
  UrlTree,
} from '@angular/router';
import { AuthService } from './auth.service';

/**
 * How long guestActivateGuard waits for a still-'unknown' session (a
 * hydrate retry after a transient failure) before rendering the guest
 * page anyway. The protected-route guards wait without a cap: bouncing
 * a signed-in customer to /login over a network blip would break the
 * "signed in until you log out" rule, and a new navigation supersedes a
 * pending one, so the user is never stuck. While they wait, the shell's
 * SessionBannerComponent shows a "can't reach 3bayti" notice with a
 * retry, so the held navigation never looks like a silent blank page.
 */
export const GUEST_GUARD_SESSION_WAIT_MS = 5_000;

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
 *
 * While the session is still 'unknown' (transient hydrate failure being
 * retried) the guard waits for it to resolve instead of answering
 * "anonymous" too early.
 */
export const authMatchGuard: CanMatchFn = (): boolean | Promise<boolean> => {
  const auth = inject(AuthService);
  if (auth.sessionStatus() === 'unknown') {
    return auth.whenSessionKnown().then(() => auth.isAuthenticated());
  }
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
 *
 * Unknown session
 * ---------------
 * If the first-load hydrate hit a transient failure the session is
 * 'unknown' (the cookie may well be valid). The guard then waits for a
 * retry to resolve it rather than redirecting a signed-in customer to
 * /login over a network blip; the session banner tells the user what is
 * happening meanwhile. /login is only ever the answer for 'anonymous'.
 */
export const authActivateGuard: CanActivateFn = (
  _route,
  state,
): boolean | UrlTree | Promise<boolean | UrlTree> => {
  const auth = inject(AuthService);
  const router = inject(Router);

  const decide = (): boolean | UrlTree => {
    if (auth.isAuthenticated()) {
      return true;
    }
    /* Preserve the in-flight URL so the login page can return after
       successful auth. `state.url` includes the query string + fragment. */
    return router.createUrlTree(['/login'], {
      queryParams: { returnUrl: state.url },
    });
  };

  if (auth.sessionStatus() === 'unknown') {
    return auth.whenSessionKnown().then(decide);
  }
  return decide();
};

/**
 * Inverse guard, redirect AWAY from auth pages when already signed in.
 *
 * Used by /login, /register, /forgot-password, /reset-password so
 * authenticated users don't see those pages. Honours `returnUrl` if
 * present (e.g. user clicked "back" to a login they no longer need).
 *
 * While the session is 'unknown' it waits up to
 * GUEST_GUARD_SESSION_WAIT_MS for a retry, so a signed-in customer isn't
 * shown a sign-in form just because the first hydrate hit a blip; after
 * that the guest page renders anyway.
 */
export const guestActivateGuard: CanActivateFn = (
  route,
): boolean | UrlTree | Promise<boolean | UrlTree> => {
  const auth = inject(AuthService);
  const router = inject(Router);

  const decide = (): boolean | UrlTree => {
    if (!auth.isAuthenticated()) {
      return true;
    }
    const returnUrl = route.queryParamMap.get('returnUrl');
    if (isSafeInAppPath(returnUrl)) {
      return router.parseUrl(returnUrl);
    }
    return router.createUrlTree(['/']);
  };

  if (auth.sessionStatus() === 'unknown') {
    return auth.whenSessionKnown(GUEST_GUARD_SESSION_WAIT_MS).then(decide);
  }
  return decide();
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
