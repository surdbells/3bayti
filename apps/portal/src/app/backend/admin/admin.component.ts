import { Component, OnInit, ViewChild } from '@angular/core';
import { TopComponent } from '../../partials/top/top.component';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
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
  'ticket-detailed': 'confirmation_number',
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
    TopComponent,
    NgApexchartsModule,
    ChartComponent,
    AxPaginationComponent,
    RouterLink,
    TranslatePipe,
    RouterLinkActive
  ],
  standalone: true,
  templateUrl: './admin.component.html',
  styleUrl: './admin.component.css'
})
export class AdminComponent implements OnInit {
  @ViewChild('chart') chart!: ChartComponent;
  public chartOptions: Partial<ChartOptions>;

  // Recent orders pagination (replaces DataTables)
  pageIndex = 0;
  pageSize = 10;
  recent?: ROrders[];
  topProducts?: Products[];

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
    private crudService: CrudService,
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
    this.get_dashboard();
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

  get_dashboard() {
    this.stats.id = this.user_session.id;
    this.stats.token = this.user_session.token;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.stats, GlobalComponent.getAdminStats)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === 'success') {
            this.ui_controls.is_loading = false;
            this.total_products = response.total_products;
            this.total_orders = response.total_orders;
            this.products_sold = response.products_sold;
            this.return_orders = response.return_orders;
            this.total_products_stats = response.total_products_stats;
            this.total_orders_stats = response.total_orders_stats;
            this.products_sold_stats = response.products_sold_stats;
            this.return_orders_stats = response.return_orders_stats;

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
            this.pageIndex = 0;
            if (response.message === 0) {
              this.ui_controls.no_recent = true;
            }
          }
        }
      });
  }


  /** Slice of `recent` for the current page (replaces DataTables paging). */
  get pagedRecent(): ROrders[] {
    if (!this.recent) return [];
    const start = this.pageIndex * this.pageSize;
    return this.recent.slice(start, start + this.pageSize);
  }

  onPageIndexChange(index: number): void {
    this.pageIndex = index;
  }

  super_admin: Action[] = [
    { id: 'vendors', title: 'Vendors', desc: 'Manage and onboard vendors', icon: 'shop', route: '/stores' },
    { id: 'customers', title: 'Customers', desc: 'View and manage customers', icon: 'people', route: '/customers' },
    { id: 'products', title: 'Products', desc: 'Track and manage products', icon: 'box-seam', route: '/admin_products' },
    { id: 'orders', title: 'Orders', desc: 'Monitor orders activities', icon: 'cart-check', route: '/processing' },
    { id: 'sales', title: 'Sales', desc: 'Monitor sales activities', icon: 'cart-check', route: '/adminsales' },
    { id: 'tickets', title: 'Tickets', desc: 'Support and issue tracking', icon: 'ticket-detailed', route: '/admintickets' },
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
    { id: 'tickets', title: 'Tickets', desc: 'Support and issue tracking', icon: 'ticket-detailed', route: '/admintickets' },
    { id: 'commissions', title: 'Commissions', desc: 'Track vendor commissions', icon: 'cash-coin', route: '/admincommissions' },
    { id: 'logistics', title: 'Logistics', desc: 'Manage deliveries & shipping', icon: 'truck', route: '/adminlogistics' },
    { id: 'collections', title: 'Collections', desc: 'Collections management', icon: 'folder', route: '/collections' }
  ];

  support: Action[] = [
    { id: 'vendors', title: 'Vendors', desc: 'Manage and onboard vendors', icon: 'shop', route: '/stores' },
    { id: 'products', title: 'Products', desc: 'Track and manage products', icon: 'box-seam', route: '/admin_products' },
    { id: 'tickets', title: 'Tickets', desc: 'Support and issue tracking', icon: 'ticket-detailed', route: '/admintickets' }
  ];

  finance: Action[] = [
    { id: 'transactions', title: 'Transactions', desc: 'Manage financial records', icon: 'credit-card', route: '/admintransactions' },
  ];

  open_processing() { this.router.navigate(['/processing']).then(r => console.log(r)); }
  open_sales() { this.router.navigate(['/adminsales']).then(r => console.log(r)); }
  open_products() { this.router.navigate(['/admin_products']).then(r => console.log(r)); }
}
