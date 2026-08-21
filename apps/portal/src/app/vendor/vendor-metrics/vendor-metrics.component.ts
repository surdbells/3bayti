import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { AxSkeletonComponent } from '../../shared/data';
import { IconComponent } from '../../shared/icon/icon.component';
import { StoreSetupProgressComponent } from '../store-setup-progress/store-setup-progress.component';

interface SeriesPoint { day: string; revenue: number; orders: number; }
interface TopProduct { product_id: number; name: string; units: number; revenue: number; }
interface RecentOrder {
  order_reference: string;
  status: string;
  created_at: string;
  item_count: number;
  vendor_total: number;
}
interface DashboardData {
  window: { days: number };
  catalog: { total_products: number; active: number; draft: number; out_of_stock: number; low_stock: number };
  sales: {
    period_days: number;
    revenue: number; revenue_formatted: string;
    orders: number; units: number; aov: number;
    all_time_revenue: number; all_time_revenue_formatted: string;
    all_time_orders: number; all_time_units: number;
  };
  operations: { awaiting_acceptance: number; to_ship: number };
  revenue_series: SeriesPoint[];
  top_products: TopProduct[];
  recent_orders: RecentOrder[];
}

/**
 * Insightful vendor dashboard — a balanced view of sales (revenue, orders,
 * AOV, trend) and operations (items awaiting action, low stock), plus
 * catalog health, top products, and recent orders. Reads
 * GET /vendor/dashboard.
 */
@Component({
  selector: 'app-vendor-metrics',
  standalone: true,
  imports: [CommonModule, FormsModule, VendorShellComponent, AxSkeletonComponent, IconComponent, StoreSetupProgressComponent],
  templateUrl: './vendor-metrics.component.html',
  styleUrl: './vendor-metrics.component.css',
})
export class VendorMetricsComponent implements OnInit {
  private adapter = inject(PortalCrudAdapter);
  private toast = inject(HotToastService);
  private router = inject(Router);

  data: DashboardData | null = null;
  days = 30;
  loading = false;
  error = '';
  readonly dayOptions = [7, 14, 30, 90];

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading = true;
    this.error = '';
    this.adapter.get_v3('GET /vendor/dashboard', { query: { days: this.days } }).subscribe({
      next: (res: any) => {
        this.data = res?.data ?? res;
        this.loading = false;
      },
      error: (err: any) => {
        this.error = 'Could not load your dashboard.';
        this.loading = false;
        this.toast.error(apiErrorMessage(err, 'Failed to load dashboard'));
      },
    });
  }

  onDaysChange(): void {
    this.load();
  }

  // ── Revenue trend (SVG bars) ─────────────────────────────────────
  get series(): SeriesPoint[] {
    return this.data?.revenue_series ?? [];
  }
  get chartMaxRevenue(): number {
    return Math.max(1, ...this.series.map((p) => p.revenue));
  }
  barHeight(p: SeriesPoint): number {
    return Math.max(2, Math.round((p.revenue / this.chartMaxRevenue) * 100));
  }

  statusBadgeClass(status: string): string {
    const map: Record<string, string> = {
      delivered: 'ax-badge ax-badge-success',
      shipped: 'ax-badge ax-badge-info',
      fulfilling: 'ax-badge ax-badge-warning',
      paid: 'ax-badge ax-badge-neutral',
    };
    return map[status] ?? 'ax-badge ax-badge-neutral';
  }

  // ── Actionable navigation ────────────────────────────────────────
  goToOrders(status?: string): void {
    this.router.navigate(['/orders'], status ? { queryParams: { status } } : {});
  }
  goToProducts(): void {
    this.router.navigate(['/products']);
  }
}
