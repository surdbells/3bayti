/**
 * AxSkeletonLineComponent — single horizontal bar for text placeholders.
 *
 * Usage:
 *
 *   <ax-skeleton-line></ax-skeleton-line>                       // default 100% wide, 12px tall
 *   <ax-skeleton-line width="80%"></ax-skeleton-line>
 *   <ax-skeleton-line width="50%" [height]="14"></ax-skeleton-line>
 *
 * See docs/m4-icons-loader-skeleton-spec.md for design rationale.
 */

import { ChangeDetectionStrategy, Component, Input } from '@angular/core';

@Component({
  selector: 'ax-skeleton-line',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span
    class="ax-skeleton ax-skeleton--line"
    [style.width]="width"
    [style.height.px]="height"
    aria-hidden="true"
  ></span>`,
  styleUrl: './ax-skeleton.scss',
})
export class AxSkeletonLineComponent {
  /** Width as a CSS value. Default '100%'. */
  @Input() width = '100%';

  /** Height in px. Default 12. */
  @Input() height = 12;
}
