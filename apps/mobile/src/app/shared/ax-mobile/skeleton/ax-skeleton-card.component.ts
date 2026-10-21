/**
 * AxSkeletonCardComponent, placeholder for the canonical product card
 * (.m6d-product-card in global.scss: 3:4 image + name line + price row with
 * the round "+" button).
 *
 * It renders the SAME global .m6d-* frame classes as the real card, so it
 * has identical geometry in every context: fluid (100%) in a grid cell,
 * pinned to 158px inside an <app-h-scroll-progress> rail. The image area is
 * the real card's own image-loading overlay (.m6d-img-skeleton +
 * .skeleton-shimmer), so skeleton -> card (image still decoding) is seamless.
 *
 * Usage:
 *
 *   <div class="product-grid" role="status" aria-busy="true" [attr.aria-label]="'...' | translate">
 *     @for (i of [1, 2, 3, 4, 5, 6]; track i) { <ax-skeleton-card /> }
 *   </div>
 *
 *   <app-h-scroll-progress>
 *     @for (i of [1, 2, 3]; track i) { <ax-skeleton-card /> }
 *   </app-h-scroll-progress>
 *
 *   <ax-skeleton-card nameWidth="55%" [showAction]="false" />   // card variant without the + button
 *
 * See ./index.ts for the shared rules.
 */

import { ChangeDetectionStrategy, Component, Input } from '@angular/core';

import { AxSkeletonCircleComponent } from './ax-skeleton-circle.component';
import { AxSkeletonLineComponent } from './ax-skeleton-line.component';

@Component({
  selector: 'ax-skeleton-card',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [AxSkeletonLineComponent, AxSkeletonCircleComponent],
  host: { 'aria-hidden': 'true' },
  template: `
    <div class="m6d-product-card ax-skeleton-card">
      <div class="m6d-img-wrap">
        <div class="m6d-img-skeleton"><div class="skeleton-shimmer"></div></div>
      </div>
      <div class="m6d-info">
        <p class="m6d-name"><ax-skeleton-line [width]="nameWidth" [height]="11" /></p>
        @if (showPrice) {
          <div class="m6d-price-row">
            <ax-skeleton-line [width]="priceWidth" [height]="11" />
            @if (showAction) {
              <ax-skeleton-circle [size]="26" />
            }
          </div>
        }
      </div>
    </div>
  `,
  styles: [`
    :host {
      display: block;
    }

    /* Not interactive: never steal taps / show the pointer cursor. */
    .ax-skeleton-card,
    .ax-skeleton-card .m6d-info {
      pointer-events: none;
      cursor: default;
    }
  `],
})
export class AxSkeletonCardComponent {
  /** Width of the name bar (CSS value, % of the caption). Default '72%'. */
  @Input() nameWidth = '72%';

  /** Width of the price bar (CSS value). Default '44px', a typical "Ð1,250". */
  @Input() priceWidth = '44px';

  /** Render the price row. Default true (the canonical card has one). */
  @Input() showPrice = true;

  /** Render the round 26px "+" button placeholder in the price row. Default true. */
  @Input() showAction = true;
}
