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

import { inject, Injector, runInInjectionContext } from '@angular/core';
import { toObservable } from '@angular/core/rxjs-interop';
import {
  ActivatedRouteSnapshot,
  CanActivateFn,
  Router,
  RouterStateSnapshot,
  UrlTree,
} from '@angular/router';
import { Observable, filter, map, take } from 'rxjs';

import { PermissionService } from '../../services/permission.service';
import {
  isAdminTier,
  isAuthenticated,
  isVendor,
  readSession,
  homeRouteFor,
  mustChangePassword,
  CHANGE_PASSWORD_ROUTE,
} from './session.util';

/**
 * A user provisioned with a temporary password must replace it before doing
 * anything else. Returns a redirect to the forced change-password screen while
 * the flag is set, except when they're already there (so no loop).
 */
function passwordChangeGate(router: Router, state: RouterStateSnapshot): UrlTree | null {
  if (mustChangePassword(readSession()) && !state.url.startsWith(CHANGE_PASSWORD_ROUTE)) {
    return router.createUrlTree([CHANGE_PASSWORD_ROUTE]);
  }
  return null;
}

/** Must be logged in with an active account. */
export const authGuard: CanActivateFn = (
  _route: ActivatedRouteSnapshot,
  state: RouterStateSnapshot,
) => {
  const router = inject(Router);
  if (!isAuthenticated()) {
    return router.createUrlTree(['/login'], {
      queryParams: { returnUrl: state.url },
    });
  }
  return passwordChangeGate(router, state) ?? true;
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
  if (!isAdminTier(session)) {
    // Authenticated but wrong role → send to the user's real home.
    return router.createUrlTree([homeRouteFor(session)]);
  }
  return passwordChangeGate(router, state) ?? true;
};

/**
 * Permission-aware guard FACTORY (composes ON TOP of the admin gate).
 *
 * Usage in routes:  canActivate: [adminGuard, requirePermission('vendors.view')]
 *
 * adminGuard already covers auth + admin-tier (and bounces unauthenticated /
 * wrong-role users), so this guard's only job is the granular per-permission
 * check that mirrors the API's PermissionGuard. It uses the SAME catalog keys
 * the backend enforces via `$perm->for('<key>')`, so a role-limited admin can
 * never navigate to a module they lack, matching what the API would 403.
 *
 * Super admins (session is_admin) short-circuit synchronously. For role-limited
 * sub-admins the effective permission set is fetched async from /me/profile, so
 * the guard waits for PermissionService to finish loading before deciding,
 * rather than racing the fetch and false-denying on first navigation.
 *
 * On a missing permission the user is redirected to the dashboard (/backend) -
 * a guaranteed-safe landing for any admin-tier user, instead of a dead end.
 */
export function requirePermission(permission: string): CanActivateFn {
  return (): boolean | UrlTree | Observable<boolean | UrlTree> => {
    const router = inject(Router);
    const perms = inject(PermissionService);
    const injector = inject(Injector);

    // Kick off the one-time load (idempotent). load() sets the super-admin flag
    // synchronously from the session, so the check below is reliable on first nav.
    perms.load();

    // Super admin → unrestricted, no need to wait for the async profile fetch.
    if (perms.isSuperAdmin()) return true;

    const decide = (): boolean | UrlTree =>
      perms.can(permission) ? true : router.createUrlTree(['/backend']);

    if (perms.loaded()) return decide();

    // Wait for the effective permissions to arrive, then evaluate exactly once.
    return runInInjectionContext(injector, () =>
      toObservable(perms.loaded).pipe(
        filter((ready) => ready),
        take(1),
        map(decide),
      ),
    );
  };
}

/**
 * Like requirePermission, but grants access when the user holds ANY of the
 * listed permissions. Used by surfaces that merged two previously separate
 * permission-scoped pages (e.g. Orders & Sales = orders.view OR sales.view),
 * so a role holding only one of them keeps access.
 */
export function requireAnyPermission(...permissions: string[]): CanActivateFn {
  return (): boolean | UrlTree | Observable<boolean | UrlTree> => {
    const router = inject(Router);
    const perms = inject(PermissionService);
    const injector = inject(Injector);

    perms.load();

    if (perms.isSuperAdmin()) return true;

    const decide = (): boolean | UrlTree =>
      perms.canAny(permissions) ? true : router.createUrlTree(['/backend']);

    if (perms.loaded()) return decide();

    return runInInjectionContext(injector, () =>
      toObservable(perms.loaded).pipe(
        filter((ready) => ready),
        take(1),
        map(decide),
      ),
    );
  };
}

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
  if (!isVendor(session)) {
    return router.createUrlTree([homeRouteFor(session)]);
  }
  return passwordChangeGate(router, state) ?? true;
};
