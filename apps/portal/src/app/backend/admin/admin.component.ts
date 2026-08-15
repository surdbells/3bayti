import { Component, OnInit, ViewChild, inject } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink, ActivatedRoute } from '@angular/router';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import {
  ApexAxisChartSeries,
  ApexChart,
  ChartComponent,
  ApexDataLabels,
  ApexPlotOptions,
  ApexYAxis,
  ApexLegend,
  ApexStroke,
  ApexXAxis,
  ApexFill,
  ApexTooltip,
  NgApexchartsModule
} from 'ng-apexcharts';
import { CommonModule } from '@angular/common';
import { GlobalComponent } from '../../global-component';
import { Products } from '../../class/products';
import { ROrders } from '../../class/recent';
import { TranslatePipe } from '../../translate.pipe';
import { AxPaginationComponent } from '../../shared/data';

import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { I18nService } from '../../i18n.service';
import { AxComboboxComponent, AxComboboxOption } from '../../shared/forms/ax-combobox.component';
import { TopPerformersComponent, TopPerformer } from '../../shared/top-performers/top-performers.component';
export type ChartOptions = {
  series: ApexAxisChartSeries;
  chart: ApexChart;
  dataLabels: ApexDataLabels;
  plotOptions: ApexPlotOptions;
  yaxis: ApexYAxis;
  xaxis: ApexXAxis;
  fill: ApexFill;
  tooltip: ApexTooltip;
  stroke: ApexStroke;
  legend: ApexLegend;
};

interface Action {
  id: string;
  title: string;
  desc: string;
  icon: string;       // legacy bootstrap-icon name (without `bi-` prefix)
  route: string;
  badge?: number;
}

/** Map legacy bootstrap-icon names to Material Symbols outlined equivalents. */
const ICON_MAP: Record<string, string> = {
  'shop': 'storefront',
  'people': 'group',
  'box-seam': 'inventory_2',
  'cart-check': 'shopping_cart',
  'cash-coin': 'payments',
  'truck': 'local_shipping',
  'folder': 'folder',
  'person': 'admin_panel_settings',
  'credit-card': 'credit_card'
};

@Component({
  selector: 'app-admin',
  imports: [
    AdminShellComponent,
    CommonModule,
    FormsModule,
    NgApexchartsModule,
    ChartComponent,
    AxPaginationComponent,
    TranslatePipe,
    RouterLink, IconComponent, AxComboboxComponent, TopPerformersComponent],
  standalone: true,
  templateUrl: './admin.component.html',
  styleUrl: './admin.component.css'
})
export class AdminComponent implements OnInit {
  private readonly i18n = inject(I18nService);
  /** Recent-sales status filter options as searchable combobox items. */
  get statusFilterComboOptions(): AxComboboxOption[] {
    return this.statusOptions.map((o) => ({ id: o.value, label: this.i18n.t(o.label) }));
  }
  @ViewChild('chart') chart!: ChartComponent;
  public chartOptions: Partial<ChartOptions>;

  /** Rolling window (days) for the KPI metrics — keeps caption + metric in sync. */
  windowDays = 30;

  // Recent orders pagination (server-driven)
  pageIndex = 0;
  pageSize = 10;
  recentTotal = 0;
  statusFilter = '';
  /** Status options for the recent-sales filter dropdown.
   *  `value` is the lowercase DB status sent as ?status=; `label` is an i18n key. */
  readonly statusOptions: { value: string; label: string }[] = [
    { value: '',               label: 'all_statuses' },
    { value: 'paid',           label: 'status_paid' },
    { value: 'fulfilling',     label: 'status_fulfilling' },
    { value: 'shipped',        label: 'status_shipped' },
    { value: 'delivered',      label: 'status_delivered' },
    { value: 'cancelled',      label: 'status_cancelled' },
    { value: 'refunded',       label: 'status_refunded' },
    { value: 'pending_payment',label: 'status_pending_payment' },
    { value: 'failed',         label: 'status_failed' },
  ];
  recent?: ROrders[];
  topProducts: {
    id: number; name: string; image: string; slug: string;
    store_name: string; category_name: string; price: number;
    units_sold: number; orders_count: number; revenue: number;
  }[] = [];

  total_products = 0;
  total_orders = 0;
  products_sold = 0;
  return_orders = 0;

  total_products_stats: Array<number> = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
  total_orders_stats: Array<number>   = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
  products_sold_stats: Array<number>  = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
  return_orders_stats: Array<number>  = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {
    this.chartOptions = {};
  }

  ui_controls = {
    is_loading: false,
    no_recent: false,
    viewing_dashboard: true,
    viewing_recent_orders: false,
    viewing_menu: false,
    nav_open: false
  };

  stats = { id: 0, token: '' };
  session_data: any = '';
  user_session = {
    id: 0,
    token: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_finance: false,
    is_support: false,
    _sub_admin: false,
    is_customer: false
  };

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);

    // Prefer data pre-fetched by adminDashboardResolver (no spinner flash).
    // Falls back to an in-component fetch if the resolver returned null
    // (e.g. analytics was temporarily unavailable at navigation time).
    const resolved = this.route.snapshot.data['dashboard'];
    if (resolved) {
      this.applyDashboard(resolved);
    } else {
      this.get_dashboard();
    }

    if (this.user_session?.is_admin) {
      this.loadTopStores();
      this.loadTopCustomers();
      this.loadInsights();
    }
  }

  // ── Top performers rails ──────────────────────────────────────────────
  topStores: TopPerformer[] = [];
  topStoresLoading = true;
  topCustomers: TopPerformer[] = [];
  topCustomersLoading = true;

  private loadTopStores(): void {
    this.topStoresLoading = true;
    this.adapter.get_v3('GET /admin/top-stores').subscribe({
      next: (res: any) => {
        this.topStores = (res?.data ?? []).map((r: any) => ({
          id: r.id, rank: r.rank, name: r.name, value: r.sales_count, imageUrl: r.logo_url ?? null,
        }));
        this.topStoresLoading = false;
      },
      error: () => { this.topStoresLoading = false; },
    });
  }

  private loadTopCustomers(): void {
    this.topCustomersLoading = true;
    this.adapter.get_v3('GET /admin/top-customers').subscribe({
      next: (res: any) => {
        this.topCustomers = (res?.data ?? []).map((r: any) => ({
          id: r.id, rank: r.rank,
          name: r.name || `${r.first_name ?? ''} ${r.last_name ?? ''}`.trim() || 'Customer',
          value: r.purchases_count, imageUrl: r.avatar_url ?? null,
        }));
        this.topCustomersLoading = false;
      },
      error: () => { this.topCustomersLoading = false; },
    });
  }

  onTopStoreSelect(it: TopPerformer): void {
    this.router.navigate(['/admin/stores', it.id], { queryParams: { name: it.name } });
  }

  onTopCustomerSelect(it: TopPerformer): void {
    this.router.navigate(['/admin/customers', it.id], { queryParams: { name: it.name } });
  }

  // ── Period-over-period insight (GET /admin/insights?days=N) ───────────
  readonly rangeOptions = [7, 30, 90];
  rangeDays = 30;
  insightsLoading = true;
  insights: {
    kpis: Record<string, { value: number; prev: number }>;
    revenue_series: number[];
    sales_by_status: { status: string; count: number }[];
    at_risk: { pending_payment: number; stuck_fulfilling: number };
  } | null = null;

  readonly kpiCards = [
    { key: 'revenue', label: 'Revenue', icon: 'payments', money: true, spark: true },
    { key: 'orders', label: 'Orders', icon: 'shopping_cart', money: false, spark: false },
    { key: 'units_sold', label: 'Units sold', icon: 'sell', money: false, spark: false },
    { key: 'new_customers', label: 'New customers', icon: 'group_add', money: false, spark: false },
  ];

  sparklineOptions: any = {};
  statusDonutOptions: any = {};

  setRange(days: number): void {
    if (this.rangeDays === days) { return; }
    this.rangeDays = days;
    this.loadInsights();
  }

  private loadInsights(): void {
    this.insightsLoading = true;
    this.adapter.get_v3('GET /admin/insights', { query: { days: this.rangeDays } }).subscribe({
      next: (res: any) => {
        this.insights = res ?? null;
        this.buildInsightCharts();
        this.insightsLoading = false;
      },
      error: () => { this.insightsLoading = false; },
    });
  }

  kpiValue(key: string): number { return this.insights?.kpis?.[key]?.value ?? 0; }

  /** Signed % change vs the prior period. prev=0 → 100% (new) or 0. */
  private deltaPct(key: string): number {
    const k = this.insights?.kpis?.[key];
    if (!k) { return 0; }
    if (!k.prev) { return k.value > 0 ? 100 : 0; }
    return ((k.value - k.prev) / k.prev) * 100;
  }
  deltaAbs(key: string): number { return Math.abs(Math.round(this.deltaPct(key))); }
  deltaClass(key: string): string {
    const d = this.deltaPct(key);
    return d > 0 ? 'is-up' : d < 0 ? 'is-down' : 'is-flat';
  }
  deltaIcon(key: string): string {
    const d = this.deltaPct(key);
    return d > 0 ? 'trending_up' : d < 0 ? 'trending_down' : 'trending_flat';
  }

  get hasStatusMix(): boolean { return (this.insights?.sales_by_status?.length ?? 0) > 0; }

  prettyStatus(s: string): string {
    return String(s ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  private buildInsightCharts(): void {
    this.sparklineOptions = {
      series: [{ name: 'Revenue', data: this.insights?.revenue_series ?? [] }],
      chart: { type: 'area', height: 56, sparkline: { enabled: true } },
      stroke: { curve: 'smooth', width: 2 },
      fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0 } },
      colors: ['#906952'],
      tooltip: { enabled: true, x: { show: false }, y: { formatter: (v: number) => 'AED ' + Math.round(v).toLocaleString() } },
    };
    const sbs = this.insights?.sales_by_status ?? [];
    this.statusDonutOptions = {
      series: sbs.map((s) => s.count),
      labels: sbs.map((s) => this.prettyStatus(s.status)),
      chart: { type: 'donut', height: 280 },
      legend: { position: 'bottom', fontSize: '12px' },
      colors: ['#906952', '#c9ae85', '#b68e75', '#a27a60', '#7a5844', '#d1543f', '#614536', '#9b9185'],
      dataLabels: { enabled: false },
      plotOptions: { pie: { donut: { size: '68%' } } },
      stroke: { width: 0 },
    };
  }

  error_notification(message: string) { this.toast.error(message); }
  success_notification(message: string) { this.toast.success(message); }

  /** Icon mapping helper used by the template. */
  getIcon(legacy: string): string {
    return ICON_MAP[legacy] || 'grid_view';
  }

  show_dashboard() {
    this.ui_controls.viewing_dashboard = true;
    this.ui_controls.viewing_menu = false;
    this.ui_controls.viewing_recent_orders = false;
  }
  show_menu() {
    this.ui_controls.viewing_dashboard = false;
    this.ui_controls.viewing_menu = true;
    this.ui_controls.viewing_recent_orders = false;
  }
  show_recent() {
    this.ui_controls.viewing_dashboard = false;
    this.ui_controls.viewing_menu = false;
    this.ui_controls.viewing_recent_orders = true;
  }

  sign_out(): void {
    this.success_notification('User logged out successfully.');
    this.router.navigate(['/']).then(r => console.log(r));
  }

  /** Build the analytics query params for the current pagination/filter state. */
  private analyticsQuery(): Record<string, any> {
    const query: Record<string, any> = {
      days: this.windowDays,
      page: this.pageIndex,
      limit: this.pageSize,
    };
    if (this.statusFilter) {
      query['status'] = this.statusFilter;
    }
    return query;
  }

  get_dashboard() {
    this.stats.id = this.user_session.id;
    this.stats.token = this.user_session.token;
    this.ui_controls.is_loading = true;
    this.adapter.get_v3('GET /admin/analytics', { query: this.analyticsQuery() })
      .subscribe({
        next: (response) => {
          this.ui_controls.is_loading = false;
          if (response) {
            this.applyDashboard(response);
          }
        },
        error: () => {
          this.ui_controls.is_loading = false;
        },
      });
  }

  /** Refetch ONLY the recent-orders list from the server (KPIs/chart unchanged). */
  private fetch_recent(): void {
    this.adapter.get_v3('GET /admin/analytics', { query: this.analyticsQuery() })
      .subscribe({
        next: (response) => {
          if (response) {
            this.recent = response.data;
            this.recentTotal = response.recent_total ?? (response.data?.length ?? 0);
            this.ui_controls.no_recent = this.recentTotal === 0;
          }
        },
      });
  }

  /** Page index changed in the pagination control → refetch that page. */
  onPageIndexChange(index: number): void {
    this.pageIndex = index;
    this.fetch_recent();
  }

  /** Rows-per-page changed → reset to first page and refetch. */
  onPageSizeChange(size: number): void {
    this.pageSize = size;
    this.pageIndex = 0;
    this.fetch_recent();
  }

  /** Status dropdown changed → reset to first page and refetch. */
  onStatusFilterChange(status: string): void {
    this.statusFilter = status;
    this.pageIndex = 0;
    this.fetch_recent();
  }

  /** Apply an analytics payload to the dashboard view (KPIs + chart + recent). */
  private applyDashboard(response: any): void {
    this.ui_controls.is_loading = false;
    this.total_products = response.total_products;
    this.total_orders = response.total_orders;
    this.products_sold = response.products_sold;
    this.return_orders = response.return_orders;
    this.total_products_stats = response.total_products_stats;
    this.total_orders_stats = response.total_orders_stats;
    this.products_sold_stats = response.products_sold_stats;
    this.return_orders_stats = response.return_orders_stats;
    this.topProducts = (response.top_products ?? []).map((p: any) => ({
      id: p.id,
      name: p.name,
      image: p.image,
      slug: p.slug ?? '',
      store_name: p.store_name ?? '—',
      category_name: p.category_name ?? '',
      price: Number(p.price) || 0,
      units_sold: p.units_sold ?? 0,
      orders_count: p.orders_count ?? 0,
      revenue: Number(p.revenue) || 0,
    }));

    this.chartOptions = {
      series: [
        { name: 'Products sold', data: this.total_products_stats },
        { name: 'Total orders',  data: this.total_orders_stats }
      ],
      chart: {
        type: 'bar',
        height: 350,
        toolbar: { show: false },
        fontFamily: 'Inter, system-ui, sans-serif',
        foreColor: '#5a554a'
      },
      colors: ['#906952', '#c9a227'],
      plotOptions: {
        bar: {
          horizontal: false,
          columnWidth: '52%',
          borderRadius: 6,
          borderRadiusApplication: 'end'
        }
      },
      dataLabels: { enabled: false },
      stroke: { show: true, width: 2, colors: ['transparent'] },
      xaxis: {
        categories: [
          'Jan','Feb','March','April','May','June',
          'July','August','September','October','November','December'
        ],
        labels: { style: { colors: '#7d7669', fontSize: '12px' } },
        axisBorder: { color: '#e4ddd3' },
        axisTicks: { color: '#e4ddd3' }
      },
      yaxis: {
        labels: { style: { colors: '#7d7669', fontSize: '12px' } }
      },
      grid: {
        borderColor: '#efe2cf',
        strokeDashArray: 4
      },
      fill: { opacity: 1 },
      legend: {
        position: 'top',
        horizontalAlign: 'right',
        fontSize: '12px',
        fontWeight: 500,
        markers: { size: 6 },
        itemMargin: { horizontal: 12 }
      },
      tooltip: {
        theme: 'light',
        y: { formatter: (val) => '' + val }
      }
    } as Partial<ChartOptions>;

    this.recent = response.data;
    this.recentTotal = response.recent_total ?? (response.data?.length ?? 0);
    this.ui_controls.no_recent = this.recentTotal === 0;
  }

  super_admin: Action[] = [
    { id: 'vendors', title: 'Vendors', desc: 'Manage and onboard vendors', icon: 'shop', route: '/stores' },
    { id: 'customers', title: 'Customers', desc: 'View and manage customers', icon: 'people', route: '/customers' },
    { id: 'products', title: 'Products', desc: 'Track and manage products', icon: 'box-seam', route: '/admin_products' },
    { id: 'orders', title: 'Orders', desc: 'Monitor orders activities', icon: 'cart-check', route: '/processing' },
    { id: 'sales', title: 'Sales', desc: 'Monitor sales activities', icon: 'cart-check', route: '/adminsales' },
    { id: 'commissions', title: 'Commissions', desc: 'Track vendor commissions', icon: 'cash-coin', route: '/admincommissions' },
    { id: 'logistics', title: 'Logistics', desc: 'Manage deliveries & shipping', icon: 'truck', route: '/adminlogistics' },
    { id: 'collections', title: 'Collections', desc: 'Collections management', icon: 'folder', route: '/collections' },
    { id: 'users', title: 'Users', desc: 'Manage users', icon: 'person', route: '/adminusers' }
  ];

  admin: Action[] = [
    { id: 'vendors', title: 'Vendors', desc: 'Manage and onboard vendors', icon: 'shop', route: '/stores' },
    { id: 'customers', title: 'Customers', desc: 'View and manage customers', icon: 'people', route: '/customers' },
    { id: 'products', title: 'Products', desc: 'Track and manage products', icon: 'box-seam', route: '/admin_products' },
    { id: 'orders', title: 'Orders', desc: 'Monitor orders activities', icon: 'cart-check', route: '/processing' },
    { id: 'commissions', title: 'Commissions', desc: 'Track vendor commissions', icon: 'cash-coin', route: '/admincommissions' },
    { id: 'logistics', title: 'Logistics', desc: 'Manage deliveries & shipping', icon: 'truck', route: '/adminlogistics' },
    { id: 'collections', title: 'Collections', desc: 'Collections management', icon: 'folder', route: '/collections' }
  ];

  support: Action[] = [
    { id: 'vendors', title: 'Vendors', desc: 'Manage and onboard vendors', icon: 'shop', route: '/stores' },
    { id: 'products', title: 'Products', desc: 'Track and manage products', icon: 'box-seam', route: '/admin_products' }
  ];

  finance: Action[] = [
    { id: 'transactions', title: 'Transactions', desc: 'Manage financial records', icon: 'credit-card', route: '/admintransactions' },
  ];

  open_processing() { this.router.navigate(['/admin/orders']).then(r => console.log(r)); }
  open_sales() { this.router.navigate(['/admin/sales']).then(r => console.log(r)); }
  open_products() { this.router.navigate(['/admin/products']).then(r => console.log(r)); }
}
