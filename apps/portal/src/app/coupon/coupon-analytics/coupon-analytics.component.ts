import { Component, OnInit, OnDestroy } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { interval, Subject } from 'rxjs';
import { takeUntil, switchMap } from 'rxjs/operators';

import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
@Component({
  selector: 'app-coupon-analytics',
  standalone: true,
  imports: [
    VendorShellComponent,
    AdminShellComponent,
    CommonModule, FormsModule, IconComponent],
  templateUrl: './coupon-analytics.component.html',
  styleUrl: './coupon-analytics.component.css',
})
export class CouponAnalyticsComponent implements OnInit, OnDestroy {
  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  ui = { loading: true, log_loading: false, nav_open: false };

  coupon_id = 0;
  coupon_code = '';
  coupon_name = '';

  overview = {
    total_redemptions: 0,
    unique_customers: 0,
    coupons_used: 0,
    active_coupons: 0,
    total_discount_given: 0,
    total_revenue_with_coupons: 0,
    avg_discount_per_order: 0,
    avg_order_value: 0,
  };

  coupon_stats = {
    total_uses: 0,
    unique_customers: 0,
    total_discount_given: 0,
    total_revenue_generated: 0,
    avg_order_before: 0,
    avg_discount: 0,
    first_used: null as string | null,
    last_used: null as string | null,
  };

  top_coupons: any[] = [];
  top_sort_by = 'uses';

  usage_series: { period: string; uses: number; total_discount: number; total_revenue: number }[] = [];
  chart_group_by = 'day';
  chart_days_back = 30;
  chart_max_uses = 0;

  usage_log: any[] = [];
  log_pagination = { page: 1, per_page: 10, total: 0, total_pages: 0 };

  live_count = 0;
  private destroy$ = new Subject<void>();

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.coupon_id = Number(this.route.snapshot.queryParamMap.get('id') || 0);

    if (this.coupon_id > 0) {
      this.fetchCouponDetails();
      this.fetchCouponStats();
      this.fetchUsageLog();
      this.startLiveCounter();
    } else {
      this.fetchOverview();
      this.fetchTopCoupons();
    }
    this.fetchUsageOverTime();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  fetchOverview(): void {
    this.adapter.get_v3('GET /vendor/coupons/:id/analytics', { params: { id: String(this.coupon_id) }, query: { period: 'overview' } }).subscribe({
      next: (r: any) => {
        if (r) this.overview = r.data;
        this.ui.loading = false;
      },
    });
  }

  fetchCouponDetails(): void {
    const payload = { token: this.user_session.token, id: this.user_session.id, coupon_id: this.coupon_id };
    this.adapter.get_v3('GET /vendor/coupons/:id', { params: { id: String(this.coupon_id) } }).subscribe({
      next: (r: any) => {
        if (r) {
          this.coupon_code = r.data.code;
          this.coupon_name = r.data.name;
        }
      },
    });
  }

  fetchCouponStats(): void {
    if (this.coupon_id <= 0) { this.ui.loading = false; return; }
    this.adapter.get_v3('GET /vendor/coupons/:id/analytics', {
      params: { id: String(this.coupon_id) }, query: { period: 'coupon_stats' },
    }).subscribe({
      next: (r: any) => {
        if (r?.data) this.coupon_stats = r.data;
        this.ui.loading = false;
      },
      error: () => { this.ui.loading = false; },
    });
  }

  fetchTopCoupons(): void {
    this.adapter.get_v3('GET /vendor/coupons/:id/analytics', {
      params: { id: String(this.coupon_id) },
      query: { period: 'top_coupons', sort_by: this.top_sort_by, limit: 5 },
    }).subscribe({
      next: (r: any) => {
        if (r?.data) this.top_coupons = r.data;
      },
    });
  }

  fetchUsageOverTime(): void {
    // The v3 vendor analytics endpoint serves the per-coupon daily series.
    if (this.coupon_id <= 0) return;
    this.adapter.get_v3('GET /vendor/coupons/:id/analytics', {
      params: { id: String(this.coupon_id) },
      query: { period: 'usage_over_time', days_back: this.chart_days_back },
    }).subscribe({
      next: (r: any) => {
        if (r?.data) {
          this.usage_series = r.data;
          this.chart_max_uses = Math.max(1, ...this.usage_series.map(s => s.uses));
        }
      },
    });
  }

  fetchUsageLog(): void {
    if (this.coupon_id <= 0) return;
    this.ui.log_loading = true;

    this.adapter.get_v3('GET /vendor/coupons/:id/analytics', {
      params: { id: String(this.coupon_id) },
      query: {
        period: 'usage_log',
        page: this.log_pagination.page,
        per_page: this.log_pagination.per_page,
      },
    }).subscribe({
      next: (r: any) => {
        if (r) {
          this.usage_log = r.data;
          this.log_pagination = r.pagination ?? this.log_pagination;
        }
        this.ui.log_loading = false;
      },
      error: () => { this.ui.log_loading = false; },
    });
  }

  startLiveCounter(): void {
    interval(15000).pipe(
      takeUntil(this.destroy$),
      switchMap(() =>
        this.adapter.get_v3('GET /vendor/coupons/:id/analytics', {
          params: { id: String(this.coupon_id) },
          query: { period: 'live_count' },
        }),
      ),
    ).subscribe({
      next: (r: any) => {
        if (r?.data) this.live_count = r.data.times_used;
      },
    });
  }

  onChartSettingsChange(): void {
    this.fetchUsageOverTime();
  }

  onTopSortChange(): void {
    this.fetchTopCoupons();
  }

  goToLogPage(page: number): void {
    if (page < 1 || page > this.log_pagination.total_pages) return;
    this.log_pagination.page = page;
    this.fetchUsageLog();
  }

  getBarHeight(uses: number): number {
    if (this.chart_max_uses <= 0) return 0;
    return Math.max(4, (uses / this.chart_max_uses) * 100);
  }

  getBarLabel(period: string): string {
    if (this.chart_group_by === 'day') {
      const d = new Date(period);
      return `${d.getDate()}/${d.getMonth() + 1}`;
    }
    return period;
  }

  goBack(): void {
    this.router.navigate(['/coupons']);
  }

  viewCouponAnalytics(id: number): void {
    this.router.navigate(['/coupon-analytics'], { queryParams: { id } });
    this.coupon_id = id;
    this.ui.loading = true;
    this.fetchCouponDetails();
    this.fetchCouponStats();
    this.fetchUsageOverTime();
    this.fetchUsageLog();
    this.startLiveCounter();
  }
}
