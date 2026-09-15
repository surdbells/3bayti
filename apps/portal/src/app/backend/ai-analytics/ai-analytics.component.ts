import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { NgApexchartsModule } from 'ng-apexcharts';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { TopPerformersComponent, TopPerformer } from '../../shared/top-performers/top-performers.component';

/** One labelled bar in a "top N" breakdown (occasions / colours). */
interface RankBar {
  label: string;
  count: number;
  pct: number; // width relative to the leader
}

/**
 * Admin BI for the Ain style & gifting concierge. Reads GET /admin/ai/analytics
 * (raw shape, no {data} envelope — mirrors the Platform-insight dashboard) and
 * surfaces both usage (volume, funnel, top intents, most-recommended products)
 * and — the point of the panel — AI-assisted orders + revenue via the backend's
 * attribution join. Shares the exact day/period/custom window switcher as the
 * insights dashboard so the two read identically.
 */
@Component({
  selector: 'app-ai-analytics',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent, NgApexchartsModule, TopPerformersComponent],
  templateUrl: './ai-analytics.component.html',
  styleUrl: './ai-analytics.component.css',
})
export class AiAnalyticsComponent implements OnInit {
  private adapter = inject(PortalCrudAdapter);

  ai: any = null;
  loading = false;

  // Window switcher (mirrors AdminComponent's insights controls).
  readonly rangeOptions = [7, 30, 90];
  rangeDays = 30;
  rangeMode: 'days' | 'current_month' | 'custom' = 'days';
  showCustom = false;
  customFrom = '';
  customTo = '';

  sparklineOptions: any = {};
  intentDonutOptions: any = {};

  ngOnInit(): void {
    this.load();
  }

  get todayStr(): string {
    return new Date().toISOString().slice(0, 10);
  }

  get subtitle(): string {
    if (this.rangeMode === 'current_month') {
      return 'This month so far';
    }
    if (this.rangeMode === 'custom' && this.ai?.range_start && this.ai?.range_end) {
      return `${this.ai.range_start} → ${this.ai.range_end}`;
    }
    return `Last ${this.rangeDays} days`;
  }

  setRange(days: number): void {
    if (this.rangeMode === 'days' && this.rangeDays === days) { return; }
    this.rangeMode = 'days';
    this.rangeDays = days;
    this.showCustom = false;
    this.load();
  }

  setCurrentMonth(): void {
    if (this.rangeMode === 'current_month') { return; }
    this.rangeMode = 'current_month';
    this.showCustom = false;
    this.load();
  }

  toggleCustom(): void {
    this.showCustom = !this.showCustom;
    if (this.showCustom && (!this.customFrom || !this.customTo)) {
      const to = new Date();
      const from = new Date();
      from.setDate(from.getDate() - 29);
      this.customTo = to.toISOString().slice(0, 10);
      this.customFrom = from.toISOString().slice(0, 10);
    }
  }

  applyCustomRange(): void {
    if (!this.customFrom || !this.customTo) { return; }
    this.rangeMode = 'custom';
    this.load();
  }

  private query(): Record<string, string | number> {
    if (this.rangeMode === 'current_month') {
      return { period: 'current_month' };
    }
    if (this.rangeMode === 'custom' && this.customFrom && this.customTo) {
      return { from: this.customFrom, to: this.customTo };
    }
    return { days: this.rangeDays };
  }

  private load(): void {
    this.loading = true;
    this.adapter.get_v3('GET /admin/ai/analytics', { query: this.query() }).subscribe({
      next: (res: any) => {
        this.ai = res ?? null;
        this.buildCharts();
        this.loading = false;
      },
      error: () => { this.loading = false; },
    });
  }

  // ── Derived views ───────────────────────────────────────────────────────

  total(key: string): number { return this.ai?.totals?.[key] ?? 0; }
  rate(key: string): number { return this.ai?.rates?.[key] ?? 0; }
  ratePct(key: string): number { return Math.round(this.rate(key) * 100); }

  get hasQueries(): boolean { return this.total('queries') > 0; }

  /** Ordered funnel stages for the horizontal funnel strip. */
  get funnel(): { label: string; key: string; icon: string }[] {
    return [
      { label: 'Opened', key: 'opened', icon: 'auto_awesome' },
      { label: 'Recommendations', key: 'recommendations_shown', icon: 'grid_view' },
      { label: 'Product clicks', key: 'product_clicks', icon: 'ads_click' },
      { label: 'Added to cart', key: 'add_to_cart', icon: 'add_shopping_cart' },
    ];
  }

  /** Funnel bar width relative to the widest stage (so the shape reads at a glance). */
  funnelPct(key: string): number {
    const max = Math.max(
      this.total('opened'),
      this.total('recommendations_shown'),
      this.total('product_clicks'),
      this.total('add_to_cart'),
      1,
    );
    return Math.round((this.total(key) / max) * 100);
  }

  get topOccasions(): RankBar[] { return this.bars(this.ai?.top_occasions); }
  get topColours(): RankBar[] { return this.bars(this.ai?.top_colours); }

  private bars(rows: { label: string; count: number }[] | undefined): RankBar[] {
    const list = rows ?? [];
    const max = Math.max(1, ...list.map((r) => r.count));
    return list.map((r) => ({ label: r.label, count: r.count, pct: Math.round((r.count / max) * 100) }));
  }

  get mostRecommended(): TopPerformer[] {
    return (this.ai?.most_recommended ?? []).map((r: any, i: number): TopPerformer => ({
      id: r.product_id,
      rank: i + 1,
      name: r.name,
      value: r.count,
      imageUrl: null,
    }));
  }

  private buildCharts(): void {
    this.sparklineOptions = {
      series: [{ name: 'Queries', data: this.ai?.query_series ?? [] }],
      chart: { type: 'area', height: 56, sparkline: { enabled: true } },
      stroke: { curve: 'smooth', width: 2 },
      fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0 } },
      colors: ['#906952'],
      tooltip: { enabled: true, x: { show: false }, y: { formatter: (v: number) => Math.round(v).toLocaleString() + ' queries' } },
    };

    const types = this.ai?.top_product_types ?? [];
    this.intentDonutOptions = {
      series: types.map((t: any) => t.count),
      labels: types.map((t: any) => this.pretty(t.label)),
      chart: { type: 'donut', height: 280 },
      legend: { position: 'bottom', fontSize: '12px' },
      colors: ['#906952', '#c9ae85', '#b68e75', '#a27a60', '#7a5844', '#d1543f', '#614536', '#9b9185'],
      dataLabels: { enabled: false },
      plotOptions: { pie: { donut: { size: '68%' } } },
      stroke: { width: 0 },
    };
  }

  get hasIntentMix(): boolean { return (this.ai?.top_product_types?.length ?? 0) > 0; }

  pretty(s: string): string {
    return String(s ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }
}
