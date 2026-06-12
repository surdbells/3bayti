/**
 * Functional route guards (Angular 19 CanActivateFn).
 *
 * - authGuard:   blocks unauthenticated/inactive users, bouncing them to
 *                /login with a returnUrl so they round-trip back after login.
 * - adminGuard:  authGuard + admin-tier role (admin/finance/support).
 * - vendorGuard: authGuard + vendor role.
 *
 * Wrong-role users are sent to THEIR correct home rather than a dead end, so
 * a vendor hitting an admin URL lands on /account instead of a blank bounce.
 *
 * These run before the lazy component loads, so the protected shell never
 * renders for an unauthorized user (fixing the first-paint flash the audit
 * flagged).
 */

import { inject } from '@angular/core';
import {
  ActivatedRouteSnapshot,
  CanActivateFn,
  Router,
  RouterStateSnapshot,
} from '@angular/router';

import {
  isAdminTier,
  isAuthenticated,
  isVendor,
  readSession,
  homeRouteFor,
} from './session.util';

/** Must be logged in with an active account. */
export const authGuard: CanActivateFn = (
  _route: ActivatedRouteSnapshot,
  state: RouterStateSnapshot,
) => {
  const router = inject(Router);
  if (isAuthenticated()) return true;
  return router.createUrlTree(['/login'], {
    queryParams: { returnUrl: state.url },
  });
};

/** Must be logged in AND admin-tier (admin / finance / support). */
export const adminGuard: CanActivateFn = (
  _route: ActivatedRouteSnapshot,
  state: RouterStateSnapshot,
) => {
  const router = inject(Router);
  if (!isAuthenticated()) {
    return router.createUrlTree(['/login'], {
      queryParams: { returnUrl: state.url },
    });
  }
  const session = readSession();
  if (isAdminTier(session)) return true;
  // Authenticated but wrong role → send to the user's real home.
  return router.createUrlTree([homeRouteFor(session)]);
};

/** Must be logged in AND a vendor. */
export const vendorGuard: CanActivateFn = (
  _route: ActivatedRouteSnapshot,
  state: RouterStateSnapshot,
) => {
  const router = inject(Router);
  if (!isAuthenticated()) {
    return router.createUrlTree(['/login'], {
      queryParams: { returnUrl: state.url },
    });
  }
  const session = readSession();
  if (isVendor(session)) return true;
  return router.createUrlTree([homeRouteFor(session)]);
};
