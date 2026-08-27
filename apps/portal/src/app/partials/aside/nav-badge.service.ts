import { Injectable, inject } from '@angular/core';
import { NavigationEnd, Router } from '@angular/router';
import { BehaviorSubject } from 'rxjs';
import { filter } from 'rxjs/operators';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { PermissionService } from '../../services/permission.service';
import { SALES_STATUSES } from '../../backend/shared/order-filters';

/**
 * Feeds the sidenav "new items" count badges.
 *
 * Two independent signals, each with its own semantics (chosen per item):
 *
 *  - applications$, the pending vendor-application BACKLOG. Counts every
 *    application still awaiting review; it only shrinks when one is approved
 *    or rejected, so merely opening the page does NOT clear it.
 *
 *  - sales$, NEW sales since the admin last opened /admin/sales. A last-seen
 *    timestamp is kept in localStorage; visiting the Sales page stamps "now"
 *    and the badge drops to zero. The first run stamps "now" too, so the whole
 *    sales history isn't flagged as new.
 *
 * Both are cheap `limit=1` queries, we only read the pagination total, never
 * the rows. Counts refresh on every navigation plus a slow poll, so same-page
 * mutations (e.g. approving an application) still surface without a reload.
 */
@Injectable({ providedIn: 'root' })
export class NavBadgeService {
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly router = inject(Router);
  private readonly perms = inject(PermissionService);

  private static readonly SALES_SEEN_KEY = 'nav_badge_seen:/admin/sales';
  private static readonly POLL_MS = 60_000;

  readonly applications$ = new BehaviorSubject<number>(0);
  readonly sales$ = new BehaviorSubject<number>(0);

  private started = false;

  /** Begin populating the badges. Idempotent, the sidenav calls it on init. */
  start(): void {
    if (this.started) {
      return;
    }
    this.started = true;

    // First run: stamp "now" so the whole sales history isn't shown as new.
    if (!localStorage.getItem(NavBadgeService.SALES_SEEN_KEY)) {
      this.stampSalesSeen();
    }

    this.router.events
      .pipe(filter((e): e is NavigationEnd => e instanceof NavigationEnd))
      .subscribe((e) => {
        // Opening the merged Orders & Sales page acknowledges everything up to
        // now. (Orders and Sales merged into /admin/orders.)
        if (e.urlAfterRedirects.split('?')[0] === '/admin/orders') {
          this.stampSalesSeen();
        }
        this.refresh();
      });

    this.refresh();
    setInterval(() => this.refresh(), NavBadgeService.POLL_MS);
  }

  /** Re-read both counts from the API. */
  refresh(): void {
    this.fetchApplications();
    this.fetchSales();
  }

  private stampSalesSeen(): void {
    localStorage.setItem(NavBadgeService.SALES_SEEN_KEY, new Date().toISOString());
    this.sales$.next(0);
  }

  private fetchApplications(): void {
    if (!this.perms.can('vendors.view')) {
      return;
    }
    this.adapter
      .get_v3('GET /admin/vendor-applications', { query: { status: 'pending', limit: 1 } })
      .subscribe({
        next: (res: any) => this.applications$.next(this.readTotal(res)),
        error: () => {},
      });
  }

  private fetchSales(): void {
    if (!this.perms.can('sales.view')) {
      return;
    }
    const since = localStorage.getItem(NavBadgeService.SALES_SEEN_KEY) ?? new Date().toISOString();
    this.adapter
      .get_v3('GET /admin/orders', {
        query: { since, status: SALES_STATUSES.join(','), limit: 1 },
      })
      .subscribe({
        next: (res: any) => this.sales$.next(this.readTotal(res)),
        error: () => {},
      });
  }

  /** Pagination total across both envelope shapes (orders vs. PaginatedEnvelope). */
  private readTotal(res: any): number {
    const n = Number(res?.pagination?.total ?? res?.meta?.total ?? 0);
    return Number.isFinite(n) && n > 0 ? n : 0;
  }
}
