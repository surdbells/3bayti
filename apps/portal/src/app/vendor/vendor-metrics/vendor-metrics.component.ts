import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { HotToastService } from '@ngneat/hot-toast';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { GlobalComponent } from '../../global-component';
import {
  AxEmptyStateComponent, AxSkeletonComponent,
} from '../../shared/data';

const V3 = 'https://api-v3.3bayti.ae';

interface MetricValue { value: number | null; [k: string]: unknown; }
interface VendorMetrics {
  vendor_id: number;
  vendor_slug: string;
  vendor_name: string;
  window: { days: number; since: string; until: string };
  metrics: Record<string, MetricValue>;
}

const METRIC_LABELS: Record<string, string> = {
  fulfillment_rate:     'Fulfillment rate',
  cancellation_rate:    'Cancellation rate',
  on_time_dispatch_rate:'On-time dispatch rate',
  return_rate:          'Return rate',
  review_score_avg:     'Avg. review score',
  review_count:         'Total reviews',
  response_time_hours:  'Avg. response time (hrs)',
  repeat_customer_rate: 'Repeat customer rate',
};

@Component({
  selector: 'app-vendor-metrics',
  standalone: true,
  imports: [
    CommonModule, FormsModule,
    VendorShellComponent,
    AxEmptyStateComponent, AxSkeletonComponent,
  ],
  templateUrl: './vendor-metrics.component.html',
  styleUrl: './vendor-metrics.component.css',
})
export class VendorMetricsComponent implements OnInit {
  data: VendorMetrics | null = null;
  days = 30;
  loading = false;
  error = '';
  readonly dayOptions = [7, 14, 30, 90];
  readonly metricKeys = Object.keys(METRIC_LABELS);

  private token = '';

  constructor(private http: HttpClient, private toast: HotToastService) {}

  ngOnInit(): void {
    const raw = sessionStorage.getItem('SESSION');
    if (raw) this.token = GlobalComponent.decodeBase64(raw)?.token ?? '';
    this.load();
  }

  load(): void {
    this.loading = true; this.error = '';
    const headers = new HttpHeaders({ Authorization: `Bearer ${this.token}` });
    this.http.get<{ data: VendorMetrics }>(
      `${V3}/v3/vendor/metrics?days=${this.days}`, { headers }
    ).subscribe({
      next: (res) => { this.data = res.data; this.loading = false; },
      error: () => {
        this.error = 'Could not load metrics.';
        this.loading = false;
        this.toast.error('Failed to load metrics');
      },
    });
  }

  onDaysChange(): void { this.load(); }

  label(key: string): string { return METRIC_LABELS[key] ?? key; }

  metricValue(key: string): string {
    const m = this.data?.metrics?.[key];
    if (!m || m.value == null) return '—';
    const v = m.value;
    if (key.endsWith('_rate') || key.endsWith('_avg')) {
      return `${(v * 100).toFixed(1)}%`;
    }
    return String(v);
  }

  metricDetail(key: string): string {
    const m = this.data?.metrics?.[key];
    if (!m) return '';
    if (key === 'fulfillment_rate')
      return `${m['fulfilled_items']} of ${m['total_items']} items`;
    if (key === 'cancellation_rate')
      return `${m['rejected_items']} rejected of ${m['total_items']} items`;
    return '';
  }
}
