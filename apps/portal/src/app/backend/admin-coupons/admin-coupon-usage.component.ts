import { Component, OnInit, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import {
  AxDataTableComponent,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';

interface LogRow extends Record<string, unknown> {
  id: number;
  redeemed_at: string;
  customer: string;
  discount_amount: number;
  order_reference: string | null;
  order_total_after: number | null;
}

interface DailyPoint {
  day: string;
  uses: number;
  discount: number;
}

/**
 * Admin coupon usage report — the admin analog of the vendor coupon-analytics
 * screen, for ANY code (platform-wide or vendor-owned). Reached from the
 * /admin/coupons list "Usage" action as /admin/coupons/usage?id=<id>.
 *
 * Backed by GET /admin/promo-codes/{id}/analytics: the default bundle
 * ({ coupon, stats, usage_over_time }) drives the header + KPI cards + daily
 * series; ?period=usage_log feeds the paginated redemption table.
 */
@Component({
  selector: 'app-admin-coupon-usage',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, IconComponent],
  templateUrl: './admin-coupon-usage.component.html',
  styleUrl: './admin-coupon-usage.component.css',
})
export class AdminCouponUsageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);
  private readonly navHistory = inject(NavigationHistoryService);

  private id = 0;
  loading = true;
  coupon: any = null;
  stats: { total_uses: number; total_discount_given: number; unique_customers: number; total_revenue_generated: number } | null = null;
  overTime: DailyPoint[] = [];
  /** Peak daily uses, for scaling the mini bar chart. */
  peakUses = 0;

  config?: AxDataTableConfig<LogRow>;
  dataSource!: AxServerDataSource<LogRow>;

  ngOnInit(): void {
    const raw = this.route.snapshot.queryParamMap.get('id');
    const parsed = raw != null ? Number(raw) : NaN;
    if (!Number.isFinite(parsed) || parsed <= 0) {
      this.toast.error('Missing coupon reference.');
      this.goBack();
      return;
    }
    this.id = parsed;
    this.loadBundle();
    this.buildTable();
  }

  private loadBundle(): void {
    this.adapter.get_v3('GET /admin/promo-codes/:id/analytics', { params: { id: String(this.id) } }).subscribe({
      next: (res: any) => {
        const d = res?.data ?? {};
        this.coupon = d.coupon ?? null;
        this.stats = d.stats ?? null;
        this.overTime = Array.isArray(d.usage_over_time) ? d.usage_over_time : [];
        this.peakUses = this.overTime.reduce((m, p) => Math.max(m, p.uses || 0), 0);
        this.loading = false;
      },
      error: (err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load usage for this coupon.'));
        this.loading = false;
      },
    });
  }

  private buildTable(): void {
    this.dataSource = new AxServerDataSource<LogRow>((q: AxQueryState) => this.fetchLog(q));
    this.config = {
      tableId: 'admin-coupon-usage-log',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No redemptions yet',
      emptyDescription: 'This code has not been used.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'coupon-usage-log' },
      columns: [
        { key: 'redeemed_at', label: 'Date', width: '13rem', format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
        { key: 'customer', label: 'Customer' },
        {
          key: 'discount_amount', label: 'Discount', align: 'right',
          value: (r) => `AED ${Number(r.discount_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}`,
        },
        { key: 'order_reference', label: 'Order', hideOnMobile: true, value: (r) => r.order_reference ?? '—' },
        {
          key: 'order_total_after', label: 'Order total', align: 'right', hideOnMobile: true,
          value: (r) => (r.order_total_after != null ? `AED ${Number(r.order_total_after).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—'),
        },
      ],
    };
  }

  private fetchLog(query: AxQueryState) {
    const page = query.pageIndex + 1;
    const per_page = query.pageSize;
    return this.adapter
      .get_v3('GET /admin/promo-codes/:id/analytics', {
        params: { id: String(this.id) },
        query: { period: 'usage_log', page, per_page },
      })
      .pipe(
        map((res: any): AxServerFetchResult<LogRow> => {
          const raw: any[] = res?.data ?? [];
          const rows = raw.map((r) => ({
            ...r,
            id: r.id,
            redeemed_at: r.redeemed_at ?? '',
            customer: r.customer_name || r.customer_email || 'Guest',
            discount_amount: r.discount_amount ?? 0,
            order_reference: r.order_reference ?? null,
            order_total_after: r.order_total_after ?? null,
          } as LogRow));
          return { rows, total: res?.pagination?.total ?? rows.length };
        }),
        catchError((err: any) => {
          this.toast.error(apiErrorMessage(err, 'Unable to load the redemption log.'));
          return of({ rows: [], total: 0 } as AxServerFetchResult<LogRow>);
        }),
      );
  }

  /** Platform-wide (null vendor) vs a specific store. */
  scopeLabel(): string {
    const v = this.coupon?.vendor_id ?? null;
    return v == null ? 'Platform' : `Store #${v}`;
  }

  barHeight(uses: number): number {
    if (this.peakUses <= 0) return 2;
    return Math.max(2, Math.round((uses / this.peakUses) * 100));
  }

  goBack(): void {
    this.navHistory.back('/admin/coupons');
  }
}
