import { Component, OnInit, ViewChild } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
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
  NgApexchartsModule,
} from 'ng-apexcharts';
import { CommonModule } from '@angular/common';
import { GlobalComponent } from '../../global-component';
import { Products } from '../../class/products';
import { ROrders } from '../../class/recent';
import { TranslatePipe } from '../../translate.pipe';
import {
  AxTableComponent,
  AxColumnComponent,
  AxEmptyStateComponent,
} from '../../shared/data';
import { themedChart } from '../../shared/rich/ax-chart-theme';
import { CouponWidgetComponent } from '../../coupon/coupon-widget/coupon-widget.component';

import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
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

@Component({
  selector: 'app-user',
  standalone: true,
  templateUrl: './user.component.html',
  imports: [
    VendorShellComponent,
    CommonModule,
    NgApexchartsModule,
    ChartComponent,
    RouterLink,
    TranslatePipe,
    AxTableComponent,
    AxColumnComponent,
    AxEmptyStateComponent,
    CouponWidgetComponent, IconComponent],
  styleUrl: './user.component.css',
})
export class UserComponent implements OnInit {
  @ViewChild('chart') chart!: ChartComponent;
  public chartOptions: Partial<ChartOptions>;
  recent?: ROrders[];
  topProducts?: Products[];

  total_products = 0;
  total_orders = 0;
  products_sold = 0;
  return_orders = 0;

  total_products_stats: Array<number> = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
  total_orders_stats: Array<number> = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
  products_sold_stats: Array<number> = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
  return_orders_stats: Array<number> = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {
    this.chartOptions = {};
  }

  ui_controls = {
    is_loading: false,
    no_recent: false,
  };

  stats = {
    id: 0,
    token: '',
  };

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
    is_customer: false,
  };

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    if (this.session_data !== null) {
      this.get_dashboard();
    } else {
      this.router.navigate(['/']).then(r => console.log(r));
    }
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_dashboard() {
    this.stats.id = this.user_session.id;
    this.stats.token = this.user_session.token;
    this.ui_controls.is_loading = true;
    this.adapter.get_v3('GET /vendor/analytics', { query: { days: 30 } }).subscribe({
      next: (response) => {
        if (response) {
          this.ui_controls.is_loading = false;
          // v3 returns { data: { vendor, totals, revenue_series,
          // status_mix, customer_mix, top_products_* }, meta }
          const a = response.data ?? {};
          const totals = a.totals ?? {};
          const statusMix = a.status_mix ?? {};

          this.total_orders = totals.orders ?? 0;
          this.products_sold = totals.items ?? 0;
          this.return_orders = statusMix.returned ?? 0;
          this.total_products = a.top_products_by_units?.length ?? 0;

          // Revenue time-series → chart (one point per day in window)
          const series: Array<{ date: string; revenue: number }> =
            a.revenue_series ?? [];
          const revenueData = series.map((p) => Number(p.revenue ?? 0));
          const orderData = series.map((p: any) => Number(p.orders ?? 0));
          const categories = series.map((p) => p.date);

          this.chartOptions = themedChart({
            series: [
              { name: 'REVENUE (AED)', data: revenueData },
              { name: 'ORDERS', data: orderData },
            ],
            chart: {
              type: 'bar',
              height: 320,
              toolbar: { show: false },
            },
            plotOptions: {
              bar: {
                horizontal: false,
                columnWidth: '55%',
                borderRadius: 4,
              },
            },
            dataLabels: { enabled: false },
            stroke: { show: true, width: 2, colors: ['transparent'] },
            xaxis: { categories },
            yaxis: { title: { text: '' } },
            fill: { opacity: 1, type: 'solid' },
            tooltip: {
              y: {
                formatter: function (val) {
                  return '' + val;
                },
              },
            },
          });

          // Recent orders surfaced from top products (v3 has no recent-orders
          // block in analytics; populated separately if needed)
          const recentRows = a.top_products_by_revenue ?? [];
          this.recent = recentRows;
          this.ui_controls.no_recent = recentRows.length === 0;
        }
      },
    });
  }
}
