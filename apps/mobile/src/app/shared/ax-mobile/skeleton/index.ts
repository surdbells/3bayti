/**
 * Skeleton / shimmer primitives, the ONE shared loading-placeholder kit for
 * abayti mobile. Reach for these before writing page-level .skeleton-* rules.
 *
 *   import {
 *     AxSkeletonLineComponent,
 *     AxSkeletonBlockComponent,
 *     AxSkeletonCircleComponent,
 *     AxSkeletonCardComponent,
 *   } from '../../shared/ax-mobile/skeleton';
 *
 * Rules
 *   1. Skeleton geometry = real content geometry. Render the skeleton INSIDE
 *      the same wrappers / classes the real content uses (the <h2>, the chip
 *      row, the .product-grid, the cover box) so swapping skeleton -> content
 *      never shifts the layout. Lines keep the parent's line height as long
 *      as the bar is shorter than it.
 *   2. Gate each region on its OWN loading flag (header, chips, rail, grid),
 *      and start those flags `true` so the first paint is already a skeleton
 *      (never a flash of the empty state).
 *   3. Colours are tokens (--ax-color-skeleton-*, light + dark in
 *      _tokens.scss); the sweep is off under prefers-reduced-motion and runs
 *      in the reading direction under [dir='rtl']. Never hard-code colours.
 *   4. Primitives are aria-hidden. Put role="status" + aria-busy="true" + an
 *      aria-label on ONE wrapper per page (usually the main list).
 *
 * Primitives
 *   <ax-skeleton-line width="60%" [height]="12" />           text line
 *   <ax-skeleton-block width="88px" height="34px" radius="999px" />   chip / pill / button
 *   <ax-skeleton-block aspectRatio="3/4" />                  image frame
 *   <ax-skeleton-block [fill]="true" />                      cover the nearest positioned ancestor
 *   <ax-skeleton-circle [size]="40" />                       avatar / logo / icon button
 *   <ax-skeleton-card />                                     canonical .m6d-product-card
 *   tone="on-media"                                          line / block / circle over a dark photo scrim
 *
 * Patterns
 *   Section title:  <h2 class="section-title"><ax-skeleton-line width="80px" [height]="14" /></h2>
 *   Chip row:       <div class="filter-chip-row">
 *                     @for (w of ['52px', '88px', '74px']; track $index) {
 *                       <ax-skeleton-block [width]="w" height="34px" radius="999px" />
 *                     }
 *                   </div>
 *   Product grid:   <div class="product-grid" role="status" aria-busy="true" [attr.aria-label]="...">
 *                     @for (i of [1, 2, 3, 4, 5, 6]; track i) { <ax-skeleton-card /> }
 *                   </div>
 *   Rail:           <section class="section" aria-hidden="true" inert>
 *                     <app-h-scroll-progress>
 *                       @for (i of [1, 2, 3]; track i) { <ax-skeleton-card /> }
 *                     </app-h-scroll-progress>
 *                   </section>
 *                   (inert, not only aria-hidden: the rail's scroller has
 *                   tabindex="0", and a hidden element must not take focus)
 *   Avatar row:     <ax-skeleton-circle [size]="44" /> + two <ax-skeleton-line>s in a flex column
 *
 * Reference implementation: customer/vendors/vendors.page.html (cover
 * header, chips, Latest rail, grid).
 */

export type { AxSkeletonTone } from './ax-skeleton.types';
export { AxSkeletonLineComponent } from './ax-skeleton-line.component';
export { AxSkeletonBlockComponent } from './ax-skeleton-block.component';
export { AxSkeletonCircleComponent } from './ax-skeleton-circle.component';
export { AxSkeletonCardComponent } from './ax-skeleton-card.component';
