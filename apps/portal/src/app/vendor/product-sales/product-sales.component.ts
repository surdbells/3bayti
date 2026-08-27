import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';

interface SalesPoint { day: string; units: number; revenue: number; }
interface RecentOrder {
  order_reference: string;
  status: string;
  created_at: string;
  quantity: number;
  line_total: number;
}

/**
 * Per-product sales for the vendor's own product. Reads
 * GET /vendor/products/:id/sales, summary KPIs, a daily revenue/units
 * series (rendered as a lightweight SVG bar chart), and recent orders.
 */
@Component({
  selector: 'app-product-sales',
  standalone: true,
  imports: [VendorShellComponent, CommonModule, IconComponent],
  templateUrl: './product-sales.component.html',
  styleUrl: './product-sales.component.css',
})
export class ProductSalesComponent implements OnInit {
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private adapter = inject(PortalCrudAdapter);
  private toast = inject(HotToastService);
  private readonly navHistory = inject(NavigationHistoryService);

  productId = 0;
  productName = '';
  loading = true;

  summary = {
    units_sold: 0,
    revenue: 0,
    revenue_formatted: 'AED 0.00',
    order_count: 0,
    avg_order_value: 0,
  };
  series: SalesPoint[] = [];
  recentOrders: RecentOrder[] = [];

  ngOnInit(): void {
    this.productId = Number(this.route.snapshot.queryParamMap.get('id')) || 0;
    this.productName = this.route.snapshot.queryParamMap.get('name') || '';
    if (this.productId <= 0) {
      this.toast.error('No product selected.');
      this.loading = false;
      return;
    }
    this.fetchSales();
  }

  fetchSales(): void {
    this.loading = true;
    this.adapter.get_v3('GET /vendor/products/:id/sales', {
      params: { id: String(this.productId) },
      query: { days_back: 90, limit: 10 },
    }).subscribe({
      next: (r: any) => {
        const d = r?.data ?? {};
        if (d.product?.name) this.productName = d.product.name;
        if (d.summary) this.summary = d.summary;
        this.series = d.over_time ?? [];
        this.recentOrders = d.recent_orders ?? [];
        this.loading = false;
      },
      error: (err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load product sales.'));
        this.loading = false;
      },
    });
  }

  // ── Lightweight SVG bar chart (revenue per day) ──────────────────
  get chartMaxRevenue(): number {
    return Math.max(1, ...this.series.map((p) => p.revenue));
  }
  barHeight(p: SalesPoint): number {
    // 0–100 (% of the 120px plot area)
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

  goBack(): void {
    this.navHistory.back('/products');
  }
}
