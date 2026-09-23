import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';

interface TopLink {
  code: string;
  target_type: string;
  target_slug: string;
  clicks: number;
  conversions: number;
}

/**
 * Admin BI for store hotlinks & Style-Me codes (P9). Reads
 * GET /admin/hotlinks/analytics (raw shape, mirrors the AI/insight panels):
 * clicks, attributed conversions + revenue (event-window join), the top links,
 * and a daily click series. Shares the day/period/custom window switcher.
 */
@Component({
  selector: 'app-hotlink-analytics',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent],
  templateUrl: './hotlink-analytics.component.html',
  styleUrl: './hotlink-analytics.component.css',
})
export class HotlinkAnalyticsComponent implements OnInit {
  private adapter = inject(PortalCrudAdapter);

  data: any = null;
  loading = false;

  readonly rangeOptions = [7, 30, 90];
  rangeDays = 30;
  rangeMode: 'days' | 'current_month' | 'custom' = 'days';
  showCustom = false;
  customFrom = '';
  customTo = '';

  ngOnInit(): void {
    this.load();
  }

  get todayStr(): string {
    return new Date().toISOString().slice(0, 10);
  }

  get subtitle(): string {
    if (this.rangeMode === 'current_month') { return 'This month so far'; }
    if (this.rangeMode === 'custom' && this.data?.range_start && this.data?.range_end) {
      return `${this.data.range_start} → ${this.data.range_end}`;
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
    if (this.rangeMode === 'current_month') { return { period: 'current_month' }; }
    if (this.rangeMode === 'custom' && this.customFrom && this.customTo) {
      return { from: this.customFrom, to: this.customTo };
    }
    return { days: this.rangeDays };
  }

  private load(): void {
    this.loading = true;
    this.adapter.get_v3('GET /admin/hotlinks/analytics', { query: this.query() }).subscribe({
      next: (res: any) => { this.data = res ?? null; this.loading = false; },
      error: () => { this.loading = false; },
    });
  }

  // ── Derived views ───────────────────────────────────────────────────────

  total(key: string): number { return this.data?.totals?.[key] ?? 0; }

  get conversionRatePct(): number {
    return Math.round((this.data?.rates?.conversions_per_click ?? 0) * 100);
  }

  get revenue(): number { return this.total('attributed_revenue'); }

  get topLinks(): TopLink[] {
    const rows = this.data?.top_links;
    return Array.isArray(rows) ? rows : [];
  }

  get series(): number[] {
    const s = this.data?.click_series;
    return Array.isArray(s) ? s : [];
  }

  get seriesMax(): number {
    return this.series.reduce((m, v) => (v > m ? v : m), 0);
  }

  /** Bar height (%) for a click-series value. */
  barHeight(v: number): number {
    const max = this.seriesMax;
    return max > 0 ? Math.max(3, Math.round((v / max) * 100)) : 3;
  }

  get hasClicks(): boolean {
    return this.total('clicks') > 0;
  }

  targetUrl(row: TopLink): string {
    return row.target_type === 'style' ? `/styles/${row.target_slug}` : `/stores/${row.target_slug}`;
  }
}
