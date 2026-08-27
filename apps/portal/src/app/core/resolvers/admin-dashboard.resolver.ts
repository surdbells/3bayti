/**
 * Admin dashboard resolver.
 *
 * Pre-fetches the admin analytics payload (GET /admin/analytics?days=30)
 * before the dashboard route activates, so the dashboard renders with its
 * KPIs and chart already populated, no post-navigation spinner flash.
 *
 * Resilience: the resolver NEVER blocks navigation on an API error. On
 * failure it resolves `null`, and the component falls back to its own
 * in-component fetch + loading/empty handling. This keeps the dashboard
 * reachable even if analytics is temporarily down, rather than trapping
 * the user on the previous page.
 *
 * The dashboard component reads the resolved value from the route data
 * (key: 'dashboard') when present and skips its own fetch; if the value
 * is null it fetches normally.
 */

import { inject } from '@angular/core';
import { ResolveFn } from '@angular/router';
import { Observable, of } from 'rxjs';
import { catchError, timeout } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';

export interface AdminDashboardData {
  total_products: number;
  total_orders: number;
  products_sold: number;
  return_orders: number;
  total_products_stats: number[];
  total_orders_stats: number[];
  products_sold_stats: number[];
  return_orders_stats: number[];
  [key: string]: unknown;
}

export const adminDashboardResolver: ResolveFn<AdminDashboardData | null> = ():
  | Observable<AdminDashboardData | null> => {
  const adapter = inject(PortalCrudAdapter);
  return adapter.get_v3('GET /admin/analytics', { query: { days: 30 } }).pipe(
    // Don't let a slow analytics call hold navigation hostage.
    timeout({ first: 8000, with: () => of(null) }),
    catchError(() => of(null)),
  ) as Observable<AdminDashboardData | null>;
};
