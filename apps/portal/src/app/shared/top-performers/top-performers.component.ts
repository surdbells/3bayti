import { Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface TopPerformer {
  /** Entity id (vendor / customer), used by the parent to navigate on select. */
  id: number | string;
  rank: number;
  name: string;
  /** The ranked/displayed metric value (sales or purchases count). */
  value: number;
  imageUrl?: string | null;
}

/**
 * "Minimal Metric Rail", a compact, horizontally-scrolling leaderboard of
 * top performers (design C). Each card shows a rank chip, a rounded avatar,
 * the name, the metric, and a share-of-leader bar so relative performance
 * reads at a glance. Reused for Top Stores (by sales) and Top Customers
 * (by purchases).
 */
@Component({
  selector: 'app-top-performers',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './top-performers.component.html',
  styleUrl: './top-performers.component.css',
})
export class TopPerformersComponent {
  @Input() heading = 'Top performers';
  /** Unit shown under the metric, e.g. 'sales' or 'purchases'. */
  @Input() metricUnit = 'sales';
  /** Small label under each name, e.g. 'Storefront' or 'Customer'. */
  @Input() subtitleLabel = '';
  @Input() items: TopPerformer[] = [];
  @Input() loading = false;

  @Output() select = new EventEmitter<TopPerformer>();

  readonly skeletons = [0, 1, 2, 3, 4];

  private get max(): number {
    return this.items.reduce((m, i) => Math.max(m, i.value), 0) || 1;
  }

  pct(value: number): number {
    return Math.max(4, Math.round((value / this.max) * 100));
  }

  initials(name: string): string {
    const parts = (name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2);
    return parts.map((w) => w[0]).join('').toUpperCase() || '?';
  }

  /** Deterministic warm-gradient index so each avatar is distinct but on-brand. */
  gradient(rank: number): string {
    const pairs = [
      ['#7a5844', '#b68e75'], ['#906952', '#c9ae85'], ['#614536', '#a27a60'],
      ['#a27a60', '#d9c7a8'], ['#4a3429', '#906952'],
    ];
    const [a, b] = pairs[(rank - 1) % pairs.length];
    return `linear-gradient(150deg, ${a}, ${b})`;
  }

  trackByRank = (_: number, it: TopPerformer): number => it.rank;
}
