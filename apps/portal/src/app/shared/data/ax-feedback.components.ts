import { ChangeDetectionStrategy, Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * Linear progress bar. Determinate when [value] is set, indeterminate
 * otherwise.
 *   <app-ax-progress [value]="60"></app-ax-progress>
 *   <app-ax-progress></app-ax-progress>  <!-- indeterminate -->
 */
@Component({
  selector: 'app-ax-progress',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="ax-progress"
      [class.ax-progress-sm]="size === 'sm'"
      [class.ax-progress-lg]="size === 'lg'"
      [class.ax-progress-indeterminate]="value == null"
      role="progressbar"
      [attr.aria-valuenow]="value"
      [attr.aria-valuemin]="0"
      [attr.aria-valuemax]="100"
      [attr.aria-label]="ariaLabel"
    >
      <div
        class="ax-progress-bar"
        [class.ax-progress-bar-success]="variant === 'success'"
        [class.ax-progress-bar-warning]="variant === 'warning'"
        [class.ax-progress-bar-danger]="variant === 'danger'"
        [style.width.%]="value != null ? value : null"
      ></div>
    </div>
  `,
})
export class AxProgressComponent {
  /** 0–100. Omit for indeterminate. */
  @Input() value?: number | null;
  @Input() size: 'sm' | 'md' | 'lg' = 'md';
  @Input() variant: 'brand' | 'success' | 'warning' | 'danger' = 'brand';
  @Input() ariaLabel = 'Progress';
}


/**
 * Shimmer placeholder. Use [kind] to pick a shape:
 *   <app-ax-skeleton kind="heading"></app-ax-skeleton>
 *   <app-ax-skeleton kind="text"></app-ax-skeleton>
 *   <app-ax-skeleton kind="avatar-sm"></app-ax-skeleton>
 *   <app-ax-skeleton kind="rect" style="height: 10rem;"></app-ax-skeleton>
 */
@Component({
  selector: 'app-ax-skeleton',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span class="ax-skeleton" [ngClass]="skeletonClass" [style.width]="width" [style.height]="height"></span>`,
  styles: [`:host { display: block; }`],
})
export class AxSkeletonComponent {
  @Input() kind: 'line' | 'text' | 'heading' | 'circle' | 'avatar-sm' | 'avatar-lg' | 'rect' | 'button' = 'line';
  @Input() width?: string;
  @Input() height?: string;

  get skeletonClass(): string {
    return `ax-skeleton-${this.kind}`;
  }
}


/**
 * Empty state card. Use the default inputs or project your own content.
 *
 *   <app-ax-empty-state
 *     icon="shopping_bag"
 *     title="No orders yet"
 *     description="When customers place orders, you'll see them here.">
 *     <button class="ax-btn ax-btn-primary">Add first product</button>
 *   </app-ax-empty-state>
 */
@Component({
  selector: 'app-ax-empty-state',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="ax-empty">
      <span class="ax-empty-icon" *ngIf="icon">
        <span class="material-symbols-outlined" aria-hidden="true">{{ icon }}</span>
      </span>
      <h3 class="ax-empty-title" *ngIf="title">{{ title }}</h3>
      <p class="ax-empty-description" *ngIf="description">{{ description }}</p>
      <div class="ax-mt-4"><ng-content></ng-content></div>
    </div>
  `,
})
export class AxEmptyStateComponent {
  @Input() icon = 'inbox';
  @Input() title = 'Nothing to show';
  @Input() description = '';
}
