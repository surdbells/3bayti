/**
 * AxSkeletonCircleComponent, round placeholder for avatars, logos and
 * icon buttons.
 *
 * Usage:
 *
 *   <ax-skeleton-circle></ax-skeleton-circle>            // default 40px
 *   <ax-skeleton-circle [size]="56"></ax-skeleton-circle>
 *   <ax-skeleton-circle [size]="38" tone="on-media" />   // over a dark photo scrim
 *
 * See ./index.ts for the shared rules and docs/m4-icons-loader-skeleton-spec.md
 * for design rationale.
 */

import { ChangeDetectionStrategy, Component, Input } from '@angular/core';

import type { AxSkeletonTone } from './ax-skeleton.types';

@Component({
  selector: 'ax-skeleton-circle',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    'aria-hidden': 'true',
    '[style.width.px]': 'size',
    '[style.height.px]': 'size',
  },
  template: `<span
    class="ax-skeleton ax-skeleton--circle"
    [class.ax-skeleton--on-media]="tone === 'on-media'"
  ></span>`,
  styleUrl: './ax-skeleton.scss',
})
export class AxSkeletonCircleComponent {
  /** Diameter in px. Default 40. */
  @Input() size = 40;

  /** Colour treatment. Default 'default'; 'on-media' over dark photos. */
  @Input() tone: AxSkeletonTone = 'default';
}
