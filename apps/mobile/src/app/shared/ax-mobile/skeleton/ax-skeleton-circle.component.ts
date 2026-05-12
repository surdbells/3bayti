/**
 * AxSkeletonCircleComponent — round placeholder for avatars and badges.
 *
 * Usage:
 *
 *   <ax-skeleton-circle></ax-skeleton-circle>            // default 40px
 *   <ax-skeleton-circle [size]="56"></ax-skeleton-circle>
 *
 * See docs/m4-icons-loader-skeleton-spec.md for design rationale.
 */

import { ChangeDetectionStrategy, Component, Input } from '@angular/core';

@Component({
  selector: 'ax-skeleton-circle',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span
    class="ax-skeleton ax-skeleton--circle"
    [style.width.px]="size"
    [style.height.px]="size"
    aria-hidden="true"
  ></span>`,
  styleUrl: './ax-skeleton.scss',
})
export class AxSkeletonCircleComponent {
  /** Diameter in px. Default 40. */
  @Input() size = 40;
}
