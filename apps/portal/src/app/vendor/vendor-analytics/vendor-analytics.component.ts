import { Component, OnInit, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';

interface AnalyticsTotals {
  revenue_aed: string;
  orders: number;
  items: number;
  aov_aed: string;
  unique_customers: number;
}
interface RevenuePoint { date: string; revenue_aed: string; orders: number; }
interface TopProduct { product_id: number; slug: string; name: string; units: number; revenue_aed: string; }
interface CustomerMix { new: number; returning: number; total: number; }
interface StatusMix { delivered: number; cancelled: number; returned: number; total: number; }

interface AnalyticsDashboard {
  totals: AnalyticsTotals;
  revenue_series: RevenuePoint[];
  top_products_by_units: TopProduct[];
  top_products_by_revenue: TopProduct[];
  customer_mix: CustomerMix;
  status_mix: StatusMix;
}

interface SvgPoint { x: number; y: number; raw: RevenuePoint; }

@Component({
  selector: 'app-vendor-analytics',
  standalone: true,
  imports: [VendorShellComponent, CommonModule, FormsModule],
  templateUrl: './vendor-analytics.component.html',
  styleUrl: './vendor-analytics.component.css',
})
export class VendorAnalyticsComponent implements OnInit {
  readonly windowDays = signal(30);
  readonly loading = signal(true);
  readonly analytics = signal<AnalyticsDashboard | null>(null);

  // ── Chart geometry ─────────────────────────────────────────────────
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

  readonly customerMix = computed(() => {
    const a = this.analytics();
    if (!a) return null;
    const m = a.customer_mix;
    const total = m.total || (m.new + m.returning) || 1;
    return {
      new: m.new, returning: m.returning, total: m.total,
      newPct: Math.round((m.new / total) * 100),
      returningPct: Math.round((m.returning / total) * 100),
    };
  });

  constructor(
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void { this.load(); }

  load(): void {
    this.loading.set(true);
    this.adapter.get_v3('GET /vendor/analytics', { query: { days: this.windowDays() } }).subscribe({
      next: (res: any) => {
        this.analytics.set(res?.data ?? res ?? null);
        this.loading.set(false);
      },
      error: (err: any) => {
        this.toast.error(apiErrorMessage(err, 'Failed to load analytics'));
        this.loading.set(false);
      },
    });
  }

  setWindow(days: number): void {
    if (this.windowDays() === days) return;
    this.windowDays.set(days);
    this.load();
  }

  // ── Display helpers ────────────────────────────────────────────────
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
}
