/**
 * AxSkeletonBlockComponent — rectangular block placeholder.
 *
 * Used for image placeholders, card backgrounds, video frames, etc.
 *
 * Usage:
 *
 *   <ax-skeleton-block></ax-skeleton-block>               // 100% wide, must set height or aspect-ratio
 *   <ax-skeleton-block height="200px"></ax-skeleton-block>
 *   <ax-skeleton-block aspectRatio="3/4"></ax-skeleton-block>   // product card aspect
 *   <ax-skeleton-block width="120px" height="80px"></ax-skeleton-block>
 *
 * See docs/m4-icons-loader-skeleton-spec.md for design rationale.
 */

import { ChangeDetectionStrategy, Component, Input } from '@angular/core';

@Component({
  selector: 'ax-skeleton-block',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span
    class="ax-skeleton ax-skeleton--block"
    [style.width]="width"
    [style.height]="height || null"
    [style.aspect-ratio]="aspectRatio || null"
    aria-hidden="true"
  ></span>`,
  styleUrl: './ax-skeleton.scss',
})
export class AxSkeletonBlockComponent {
  /** Width as a CSS value. Default '100%'. */
  @Input() width = '100%';

  /**
   * Height as a CSS value (e.g. '200px', '50vh').
   * If aspectRatio is also set, height takes precedence.
   * If neither is set, the element will collapse to 0 height — use one or the other.
   */
  @Input() height?: string;

  /**
   * Optional CSS aspect-ratio (e.g. '3/4' for product cards, '16/9' for video).
   * Ignored if `height` is set.
   */
  @Input() aspectRatio?: string;
}
