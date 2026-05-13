import { Component, OnInit, ViewChild } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
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
    CouponWidgetComponent,
  ],
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
    private crudService: CrudService,
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
    this.crudService.post_request(this.stats, GlobalComponent.getVendorStats).subscribe({
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

          this.chartOptions = themedChart({
            series: [
              { name: 'PRODUCTS SOLD', data: this.total_products_stats },
              { name: 'TOTAL ORDERS', data: this.total_orders_stats },
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
            xaxis: {
              categories: [
                'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
              ],
            },
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

          this.recent = response.data;
          if (response.message === 0) {
            this.ui_controls.no_recent = true;
          }
        }
      },
    });
  }
}
