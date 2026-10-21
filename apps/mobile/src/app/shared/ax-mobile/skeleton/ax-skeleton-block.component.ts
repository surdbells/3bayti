/**
 * AxSkeletonBlockComponent, rectangular block placeholder.
 *
 * Used for image placeholders, chips/pills, buttons, card backgrounds, etc.
 *
 * Usage:
 *
 *   <ax-skeleton-block height="200px"></ax-skeleton-block>     // 100% wide; set height OR aspectRatio
 *   <ax-skeleton-block aspectRatio="3/4"></ax-skeleton-block>  // product image frame
 *   <ax-skeleton-block width="120px" height="80px"></ax-skeleton-block>
 *   <ax-skeleton-block width="88px" height="34px" radius="999px" />   // filter chip / pill
 *   <ax-skeleton-block [fill]="true" />                        // cover the nearest positioned ancestor
 *   <ax-skeleton-block [fill]="true" radius="50%" />           // inside a round logo frame
 *   <ax-skeleton-block width="92px" height="32px" radius="999px" tone="on-media" />  // over a photo
 *
 * See ./index.ts for the shared rules and docs/m4-icons-loader-skeleton-spec.md
 * for design rationale.
 */

import { ChangeDetectionStrategy, Component, Input } from '@angular/core';

import type { AxSkeletonTone } from './ax-skeleton.types';

@Component({
  selector: 'ax-skeleton-block',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    'aria-hidden': 'true',
    '[class.ax-skeleton-host--fill]': 'fill',
    '[style.width]': 'fill ? null : width',
    '[style.height]': 'fill ? null : (height || null)',
    '[style.aspect-ratio]': 'fill || height ? null : (aspectRatio || null)',
  },
  template: `<span
    class="ax-skeleton ax-skeleton--block"
    [class.ax-skeleton--on-media]="tone === 'on-media'"
    [style.border-radius]="radius || null"
  ></span>`,
  styleUrl: './ax-skeleton.scss',
})
export class AxSkeletonBlockComponent {
  /** Width as a CSS value. Default '100%'. Ignored when `fill` is set. */
  @Input() width = '100%';

  /**
   * Height as a CSS value (e.g. '200px', '50vh').
   * If aspectRatio is also set, height takes precedence.
   * If neither is set (and not `fill`), the block collapses to 0 height.
   */
  @Input() height?: string;

  /**
   * Optional CSS aspect-ratio (e.g. '3/4' for product cards, '16/9' for video).
   * Ignored if `height` is set.
   */
  @Input() aspectRatio?: string;

  /** Optional CSS border-radius (e.g. '999px' for a pill, '50%', '0'). Default ax-radius-sm. */
  @Input() radius?: string;

  /**
   * Absolutely fill the nearest positioned ancestor (inset: 0) instead of
   * taking its own width/height. Use inside a wrapper that already owns the
   * geometry (cover image box, logo circle, image frame).
   */
  @Input() fill = false;

  /** Colour treatment. Default 'default'; 'on-media' over dark photos. */
  @Input() tone: AxSkeletonTone = 'default';
}
