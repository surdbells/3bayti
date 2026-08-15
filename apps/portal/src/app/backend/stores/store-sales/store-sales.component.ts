import { Component, OnInit, signal, computed } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { SALES_STATUSES } from '../../shared/order-filters';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
  type AxDateRange,
} from '../../../shared/data/enterprise';

interface SaleRow extends Record<string, unknown> {
  id: number;
  order_ref: string;
  product_name: string;
  customer_name: string;
  quantity: number;
  total_price: string;
  status: string;
  created: string;
}

interface RevenuePoint { date: string; revenue_aed: string; orders: number; }
interface TopProduct { product_id: number; name: string; units: number; revenue_aed: string; }
interface Analytics {
  totals: { revenue_aed: string; orders: number; items: number; aov_aed: string; unique_customers: number };
  revenue_series: RevenuePoint[];
  top_products_by_units: TopProduct[];
  top_products_by_revenue: TopProduct[];
  customer_mix: { new: number; returning: number; total: number };
  status_mix: { delivered: number; cancelled: number; returned: number; total: number };
}

/** A point on the SVG revenue path (computed geometry). */
interface SvgPoint { x: number; y: number; raw: RevenuePoint; }

@Component({
  selector: 'app-store-sales',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './store-sales.component.html',
  styleUrl: './store-sales.component.css',
})
export class StoreSalesComponent implements OnInit {
  store_name = '';
  private storeId = 0;
  private vendorV3Id = 0;

  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  // Analytics state
  readonly windowDays = signal(30);
  readonly loadingAnalytics = signal(true);
  readonly analytics = signal<Analytics | null>(null);

  config!: AxDataTableConfig<SaleRow>;
  dataSource!: AxServerDataSource<SaleRow>;

  // ── Chart geometry (computed from analytics) ───────────────────────
  readonly CHART_W = 640;
  readonly CHART_H = 200;
  private readonly PAD = { top: 16, right: 16, bottom: 28, left: 48 };

  readonly revenuePoints = computed<SvgPoint[]>(() => {
    const a = this.analytics();
    if (!a || a.revenue_series.length === 0) return [];
    const series = a.revenue_series;
    const maxRev = Math.max(...series.map((p) => Number(p.revenue_aed)), 1);
    const innerW = this.CHART_W - this.PAD.left - this.PAD.right;
    const innerH = this.CHART_H - this.PAD.top - this.PAD.bottom;
    const n = series.length;
    return series.map((p, i) => ({
      x: this.PAD.left + (n === 1 ? innerW / 2 : (i / (n - 1)) * innerW),
      y: this.PAD.top + innerH - (Number(p.revenue_aed) / maxRev) * innerH,
      raw: p,
    }));
  });

  readonly revenueLinePath = computed(() => {
    const pts = this.revenuePoints();
    if (pts.length === 0) return '';
    return pts.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ');
  });

  readonly revenueAreaPath = computed(() => {
    const pts = this.revenuePoints();
    if (pts.length === 0) return '';
    const baseY = this.CHART_H - this.PAD.bottom;
    const line = pts.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ');
    return `${line} L ${pts[pts.length - 1].x.toFixed(1)} ${baseY} L ${pts[0].x.toFixed(1)} ${baseY} Z`;
  });

  readonly revenueYTicks = computed(() => {
    const a = this.analytics();
    if (!a || a.revenue_series.length === 0) return [];
    const maxRev = Math.max(...a.revenue_series.map((p) => Number(p.revenue_aed)), 1);
    const innerH = this.CHART_H - this.PAD.top - this.PAD.bottom;
    const steps = 4;
    return Array.from({ length: steps + 1 }, (_, i) => {
      const value = (maxRev / steps) * i;
      const y = this.PAD.top + innerH - (value / maxRev) * innerH;
      return { y, label: this.compact(value) };
    });
  });

  // Status donut segments (stroke-dasharray on a circle)
  readonly DONUT_R = 56;
  readonly donutCircumference = computed(() => 2 * Math.PI * this.DONUT_R);
  readonly statusSegments = computed(() => {
    const a = this.analytics();
    if (!a) return [];
    const mix = a.status_mix;
    const total = mix.total || (mix.delivered + mix.cancelled + mix.returned) || 1;
    const segs = [
      { label: 'Delivered', value: mix.delivered, color: 'var(--ax-color-success, #16a34a)' },
      { label: 'Returned', value: mix.returned, color: 'var(--ax-color-warning, #d97706)' },
      { label: 'Cancelled', value: mix.cancelled, color: 'var(--ax-color-danger, #dc2626)' },
    ].filter((s) => s.value > 0);
    const circ = this.donutCircumference();
    let offset = 0;
    return segs.map((s) => {
      const frac = s.value / total;
      const seg = {
        ...s,
        dash: `${(frac * circ).toFixed(2)} ${(circ - frac * circ).toFixed(2)}`,
        offset: (-offset * circ).toFixed(2),
        pct: Math.round(frac * 100),
      };
      offset += frac;
      return seg;
    });
  });

  readonly topByRevenue = computed(() => {
    const a = this.analytics();
    if (!a) return [];
    const list = a.top_products_by_revenue.slice(0, 5);
    const max = Math.max(...list.map((p) => Number(p.revenue_aed)), 1);
    return list.map((p) => ({ ...p, pct: Math.round((Number(p.revenue_aed) / max) * 100) }));
  });

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(
      sessionStorage.getItem('SESSION') ?? '',
    );
    this.storeId = Number(this.route.snapshot.queryParamMap.get('id'));
    this.store_name = this.route.snapshot.queryParamMap.get('name') ?? '';
    // The admin flow passes the v3 vendor id straight through as vendor_id;
    // only fall back to legacy-id resolution when it's absent (older links).
    this.vendorV3Id = Number(this.route.snapshot.queryParamMap.get('vendor_id')) || 0;
    this.resolveVendorThenLoad();
    this.buildTable();
  }

  /** Use the v3 vendor id when supplied; otherwise resolve it from the legacy store id. */
  private resolveVendorThenLoad() {
    if (this.vendorV3Id > 0) {
      this.loadAnalytics();
      return;
    }
    this.adapter.get_v3('GET /vendors/by-legacy-id/:id', { params: { id: String(this.storeId) } }).subscribe({
      next: (res: any) => {
        this.vendorV3Id = res?.data?.id ?? 0;
        if (this.vendorV3Id) {
          this.loadAnalytics();
          // The table was built before resolution; re-run its fetch now
          // that we have the vendor id to scope by.
          this.dataSource?.retry();
        } else {
          this.loadingAnalytics.set(false);
        }
      },
      error: () => { this.loadingAnalytics.set(false); },
    });
  }

  loadAnalytics() {
    if (!this.vendorV3Id) return;
    this.loadingAnalytics.set(true);
    this.adapter.get_v3('GET /admin/vendors/:id/analytics', {
      params: { id: String(this.vendorV3Id) },
      query: { days: this.windowDays() },
    }).subscribe({
      next: (res: any) => {
        this.analytics.set(res?.data ?? null);
        this.loadingAnalytics.set(false);
      },
      error: () => {
        this.toast.error('Unable to load sales analytics.');
        this.loadingAnalytics.set(false);
      },
    });
  }

  setWindow(days: number) {
    if (this.windowDays() === days) return;
    this.windowDays.set(days);
    this.loadAnalytics();
  }

  // ── Transactions table ─────────────────────────────────────────────
  private money = (v: unknown) =>
    v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—';

  private buildTable() {
    this.dataSource = new AxServerDataSource<SaleRow>((q) => this.fetchSales(q));
    this.config = {
      tableId: 'store-sales',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search sales…',
      stickyHeader: true,
      hover: true,
      compact: true,
      emptyTitle: 'No sales',
      emptyDescription: 'This store has no sales in the selected range.',
      export: { enabled: true, formats: ['csv', 'xlsx', 'pdf'], filename: 'store-sales' },
      filters: [{ key: 'date', label: 'Date', type: 'date-range' }],
      columns: [
        { key: 'order_ref', label: 'Order ref', sortable: true, sticky: 'left', width: '14rem' },
        { key: 'product_name', label: 'Product' },
        { key: 'customer_name', label: 'Customer', hideOnMobile: true },
        { key: 'quantity', label: 'Qty', align: 'center', hideOnMobile: true },
        { key: 'total_price', label: 'Total', align: 'right', format: (v) => this.money(v) },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'created', label: 'Date', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
      ],
    };
  }

  private fetchSales(query: AxQueryState) {
    // Scope the list to THIS store. /admin/transactions has no vendor
    // filter (it's global) — using it showed every store the same list.
    // /admin/orders filters by the resolved v3 vendor_id and carries the
    // product/line-item data this table needs.
    if (!this.vendorV3Id) {
      return of({ rows: [], total: 0 } as AxServerFetchResult<SaleRow>);
    }
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
      vendor_id: this.vendorV3Id,
    };
    if (query.search) q.search = query.search;
    // Store SALES — exclude unpaid (pending_payment) orders, same rule as the
    // admin/sales report; a specific status pick (if this table gains one) wins.
    q.status = query.filters['status'] || SALES_STATUSES.join(',');
    const range = query.filters['date'] as AxDateRange | undefined;
    if (range?.from) q.since = range.from;
    if (range?.to) q.until = range.to;
    return this.adapter.get_v3('GET /admin/orders', { query: q }).pipe(
      map((response: any): AxServerFetchResult<SaleRow> => {
        const raw: any[] = response?.orders ?? (Array.isArray(response?.data) ? response.data : []);
        const rows = raw.map((o) => this.mapSale(o));
        return { rows, total: response?.pagination?.total ?? response?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load store sales.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<SaleRow>);
      }),
    );
  }

  /** Flatten an admin order into a sales row. */
  private mapSale(o: any): SaleRow {
    const items: any[] = o.items ?? [];
    const first = items[0] ?? {};
    const qty = items.reduce((s, i) => s + (Number(i.quantity) || 0), 0);
    const productLabel = items.length > 1
      ? `${first.product_name ?? 'Item'} +${items.length - 1} more`
      : (first.product_name ?? `Order ${o.order_reference ?? o.id}`);
    const customer = o.customer ?? {};
    const name = `${customer.first_name ?? ''} ${customer.last_name ?? ''}`.trim();
    return {
      id: o.id,
      order_ref: o.order_reference ?? '',
      product_name: productLabel,
      customer_name: name || customer.email || '—',
      quantity: qty,
      total_price: o.total ?? o.subtotal ?? '0',
      status: o.status ?? '',
      created: o.date ?? o.created_at ?? '',
    };
  }

  // ── Display helpers ────────────────────────────────────────────────
  money2(v: unknown): string { return this.money(v); }

  compact(v: number): string {
    if (v >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`;
    if (v >= 1_000) return `${(v / 1_000).toFixed(1)}k`;
    return `${Math.round(v)}`;
  }

  aed(v: unknown): string {
    return `AED ${Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
  }

  pointLabel(p: SvgPoint): string {
    return `${new Date(p.raw.date).toLocaleDateString()} · ${this.aed(p.raw.revenue_aed)} · ${p.raw.orders} orders`;
  }

  statusBadge(s: string): string {
    switch (s) {
      case 'delivered': case 'paid': return 'ax-badge ax-badge-success';
      case 'shipped': return 'ax-badge ax-badge-info';
      case 'pending': return 'ax-badge ax-badge-warning';
      case 'cancelled': case 'returned': return 'ax-badge ax-badge-danger';
      default: return 'ax-badge ax-badge-neutral';
    }
  }

  statusLabel(s: string): string {
    return (s || '').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  goBack() { this.router.navigate(['/stores']); }
}
