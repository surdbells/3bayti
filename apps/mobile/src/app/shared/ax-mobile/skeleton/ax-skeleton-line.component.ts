/**
 * AxSkeletonLineComponent, single horizontal bar for text placeholders.
 *
 * Usage:
 *
 *   <ax-skeleton-line></ax-skeleton-line>                       // default 100% wide, 12px tall
 *   <ax-skeleton-line width="80%"></ax-skeleton-line>
 *   <ax-skeleton-line width="50%" [height]="14"></ax-skeleton-line>
 *   <h2 class="title"><ax-skeleton-line width="60%" [height]="14" /></h2>   // keeps the h2's line height
 *   <ax-skeleton-line width="70%" tone="on-media" />           // over a dark photo scrim
 *
 * Width/height are applied to the host, so a percentage width resolves
 * against the real parent (the <p>/<h2> the text would sit in). Keep the
 * bar height below the parent's line height and the line box is unchanged.
 *
 * See ./index.ts for the shared rules and docs/m4-icons-loader-skeleton-spec.md
 * for design rationale.
 */

import { ChangeDetectionStrategy, Component, Input } from '@angular/core';

import type { AxSkeletonTone } from './ax-skeleton.types';

@Component({
  selector: 'ax-skeleton-line',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    'aria-hidden': 'true',
    '[style.width]': 'width',
    '[style.height.px]': 'height',
  },
  template: `<span
    class="ax-skeleton ax-skeleton--line"
    [class.ax-skeleton--on-media]="tone === 'on-media'"
  ></span>`,
  styleUrl: './ax-skeleton.scss',
})
export class AxSkeletonLineComponent {
  /** Width as a CSS value. Default '100%'. */
  @Input() width = '100%';

  /** Height in px. Default 12. */
  @Input() height = 12;

  /** Colour treatment. Default 'default'; 'on-media' over dark photos. */
  @Input() tone: AxSkeletonTone = 'default';
}
