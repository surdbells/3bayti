import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { HotToastService } from '@ngneat/hot-toast';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { GlobalComponent } from '../../global-component';
import {
  AxTableComponent, AxColumnComponent,
  AxEmptyStateComponent, AxSkeletonComponent, AxPaginationComponent,
} from '../../shared/data';

const V3 = 'https://api-v3.3bayti.ae';

interface AnalyticsTotals {
  revenue_aed: string;
  orders: number;
  items: number;
  aov_aed: string;
  unique_customers: number;
}
interface RevenuePoint { date: string; revenue_aed: string; orders: number; }
interface TopProduct  { product_id: number; slug: string; name: string; units: number; revenue_aed: string; }
interface CustomerMix { new: number; returning: number; total: number; }
interface StatusMix   { delivered: number; cancelled: number; returned: number; total: number; }

interface AnalyticsDashboard {
  vendor: { id: number; slug: string; name: string };
  window: { days: number; since: string; until: string };
  totals: AnalyticsTotals;
  revenue_series: RevenuePoint[];
  top_products_by_units: TopProduct[];
  top_products_by_revenue: TopProduct[];
  customer_mix: CustomerMix;
  status_mix: StatusMix;
}

@Component({
  selector: 'app-vendor-analytics',
  standalone: true,
  imports: [
    CommonModule, FormsModule,
    VendorShellComponent,
    AxTableComponent, AxColumnComponent,
    AxEmptyStateComponent, AxSkeletonComponent, AxPaginationComponent,
  ],
  templateUrl: './vendor-analytics.component.html',
  styleUrl: './vendor-analytics.component.css',
})
export class VendorAnalyticsComponent implements OnInit {
  data: AnalyticsDashboard | null = null;
  days = 30;
  loading = false;
  error = '';

  readonly dayOptions = [7, 14, 30, 90];

  private token = '';

  constructor(
    private http: HttpClient,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void {
    const raw = sessionStorage.getItem('SESSION');
    if (raw) {
      this.token = GlobalComponent.decodeBase64(raw)?.token ?? '';
    }
    this.load();
  }

  load(): void {
    this.loading = true;
    this.error = '';
    const headers = new HttpHeaders({ Authorization: `Bearer ${this.token}` });
    this.http
      .get<{ data: AnalyticsDashboard; meta: Record<string, string> }>(
        `${V3}/v3/vendor/analytics?days=${this.days}`,
        { headers },
      )
      .subscribe({
        next: (res) => {
          this.data = res.data;
          this.loading = false;
        },
        error: () => {
          this.error = 'Could not load analytics. Please try again.';
          this.loading = false;
          this.toast.error('Failed to load analytics');
        },
      });
  }

  onDaysChange(): void { this.load(); }

  pct(part: number, total: number): string {
    if (!total) return '0';
    return ((part / total) * 100).toFixed(1);
  }

  fmt(aed: string | undefined): string {
    if (!aed) return 'AED 0.00';
    return `AED ${parseFloat(aed).toLocaleString('en-AE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }
}
