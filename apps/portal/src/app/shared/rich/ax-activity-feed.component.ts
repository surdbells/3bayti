import { ChangeDetectionStrategy, Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

import { IconComponent } from '../icon/icon.component';
export type AxActivityVariant = 'default' | 'brand' | 'success' | 'warning' | 'danger' | 'info';

export interface AxActivityItem {
  /** Material Symbols icon for the dot. Default 'circle' equivalent. */
  icon?: string;
  /** Dot colour treatment. */
  variant?: AxActivityVariant;
  /** Event summary line. Plain text. */
  title: string;
  /** Small metadata (date, author, etc.). Plain text. */
  meta?: string;
  /** Optional HTML body shown beneath the title. */
  body?: string;
}

/**
 * Vertical timeline of events. For order status history, audit logs,
 * vendor activity, ticket replies.
 *
 *   <app-ax-activity-feed [items]="orderEvents"></app-ax-activity-feed>
 *
 * Each item: icon + title + optional meta + optional body. Items connect
 * via a continuous line on the left, with coloured dots per event variant.
 */
@Component({
  selector: 'app-ax-activity-feed',
  standalone: true,
  imports: [CommonModule, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ul class="ax-activity">
      <li *ngFor="let item of items; trackBy: trackByIndex" class="ax-activity-item">
        <span class="ax-activity-dot"
              [class.ax-activity-dot-brand]="item.variant === 'brand'"
              [class.ax-activity-dot-success]="item.variant === 'success'"
              [class.ax-activity-dot-warning]="item.variant === 'warning'"
              [class.ax-activity-dot-danger]="item.variant === 'danger'"
              [class.ax-activity-dot-info]="item.variant === 'info'"
              aria-hidden="true">
          <app-icon [name]="item.icon" *ngIf="item.icon"></app-icon>
        </span>
        <div class="ax-activity-content">
          <p class="ax-activity-title">{{ item.title }}</p>
          <p *ngIf="item.meta" class="ax-activity-meta">{{ item.meta }}</p>
          <div *ngIf="item.body" class="ax-activity-body" [innerHTML]="item.body"></div>
        </div>
      </li>
    </ul>
  `,
})
export class AxActivityFeedComponent {
  @Input() items: AxActivityItem[] = [];

  trackByIndex = (i: number) => i;
}
