import {
  Component,
  ChangeDetectionStrategy,
  Input,
  signal,
  ViewChild,
  ElementRef,
  inject,
  PLATFORM_ID,
  AfterViewInit,
  OnDestroy,
  ChangeDetectorRef,
} from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import type { Product } from '../../features/catalog/product.model';
import { ProductCardComponent } from '../../features/catalog/product-card';
import { SkeletonShimmerComponent } from './skeleton-shimmer';

/**
 * ProductStrip — horizontal-scrolling row of product cards.
 *
 * The signature home-page surface (Phase 1). Used for "Featured",
 * "Best sellers", "New arrivals" sections. Same pattern works on
 * /designer/:slug, /search results, /best-sellers index pages
 * (Phase 6). One component, many surfaces.
 *
 * Design language (premium retail):
 *   - Section heading uses Playfair, brand-700, with optional
 *     "View all" link aligned right
 *   - Cards scroll horizontally; CSS scroll-snap aligns each card
 *     to a starting position so swipes/scrolls land on whole cards
 *   - Desktop: hover-revealed left/right arrow buttons (CSS hover);
 *     touch devices skip them entirely (industry consensus —
 *     arrows on touch are noisy chrome)
 *   - Mobile: peek of next card visible at the right edge invites
 *     the user to swipe (no buttons)
 *
 * Loading state: when [loading]="true", renders 4 skeleton cards.
 * No layout shift when real cards swap in (skeletons share the
 * same min-width as cards).
 *
 * Empty state: when [loading]="false" and [products] is empty,
 * the strip renders nothing (parent should hide the section
 * entirely if there's no content).
 *
 * SSR safety: arrows render only after view init on the browser
 * — server-rendered HTML omits them entirely (no hydration
 * mismatch). The strip itself prerenders cleanly because all
 * card data is in TransferState.
 */
@Component({
  selector: 'ui-product-strip',
  standalone: true,
  imports: [ProductCardComponent, SkeletonShimmerComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="strip" [attr.aria-label]="heading">
      <header class="strip__header">
        <h2 class="strip__heading">
          @if (eyebrow) {
            <span class="strip__eyebrow">{{ eyebrow }}</span>
          }
          <span class="strip__title">{{ heading }}</span>
        </h2>
        @if (viewAllUrl) {
          <a [href]="viewAllUrl" class="strip__view-all">
            View all
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M5 12h14M13 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </a>
        }
      </header>

      <div class="strip__viewport">
        <!-- Left arrow: only renders on the browser, after we know
             whether scroll is needed. Hidden on touch devices via CSS. -->
        @if (showArrows()) {
          <button
            type="button"
            class="strip__arrow strip__arrow--left"
            [class.is-disabled]="atStart()"
            [attr.aria-disabled]="atStart()"
            aria-label="Scroll left"
            (click)="scrollByCard(-1)"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
        }

        <div #scroller class="strip__track" (scroll)="onScroll()">
          @if (loading) {
            <!-- Skeleton state — 4 placeholder cards. Match the real
                 card's outer dimensions so layout doesn't shift when
                 real data arrives. -->
            @for (i of [0, 1, 2, 3]; track i) {
              <div class="strip__item strip__item--skeleton">
                <ui-skeleton-shimmer aspectRatio="3 / 4" />
                <ui-skeleton-shimmer height="12px" width="40%" [rounded]="false" />
                <ui-skeleton-shimmer height="18px" width="80%" [rounded]="false" />
                <ui-skeleton-shimmer height="14px" width="50%" [rounded]="false" />
              </div>
            }
          } @else {
            @for (product of products; track product.id) {
              <div class="strip__item">
                <ui-product-card [product]="product" />
              </div>
            }
          }
        </div>

        @if (showArrows()) {
          <button
            type="button"
            class="strip__arrow strip__arrow--right"
            [class.is-disabled]="atEnd()"
            [attr.aria-disabled]="atEnd()"
            aria-label="Scroll right"
            (click)="scrollByCard(1)"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
        }
      </div>
    </section>
  `,
  styleUrl: './product-strip.scss',
})
export class ProductStripComponent implements AfterViewInit, OnDestroy {
  /** Section heading — e.g. "This week's edit", "Best sellers". */
  @Input({ required: true }) heading!: string;

  /**
   * Optional small uppercase label above the heading. Used to give
   * sections a hierarchical eyebrow (e.g. "Editor's pick" above
   * "This week's edit"). Most strips can skip this.
   */
  @Input() eyebrow: string | null = null;

  /** Optional "View all" link target — e.g. /best-sellers. */
  @Input() viewAllUrl: string | null = null;

  /** Products to render. */
  @Input() products: Product[] = [];

  /** True while parent is loading the data. Renders skeletons. */
  @Input() loading = false;

  @ViewChild('scroller') private scrollerRef?: ElementRef<HTMLElement>;

  private platformId = inject(PLATFORM_ID);
  private cdr = inject(ChangeDetectorRef);

  /** True when arrows should render — only on browser AND only on
   * non-touch (the CSS handles the touch hiding, this signal only
   * controls SSR behavior). */
  readonly showArrows = signal(false);

  readonly atStart = signal(true);
  readonly atEnd = signal(false);

  ngAfterViewInit(): void {
    if (!isPlatformBrowser(this.platformId)) return;
    /* Reveal arrows post-hydration. Server renders without them —
     * avoids the FOUC where arrows briefly flash before scroll-state
     * is computed. */
    this.showArrows.set(true);
    /* Initialise edge state from current scroll position. */
    queueMicrotask(() => this.onScroll());
  }

  ngOnDestroy(): void {
    /* Nothing to clean up — scroll listener is template-bound. */
  }

  /**
   * Update edge-state signals when the scroll container moves. The
   * arrows use these to render disabled at scroll boundaries. We
   * give a small tolerance (4px) to account for sub-pixel rounding.
   */
  onScroll(): void {
    const el = this.scrollerRef?.nativeElement;
    if (!el) return;
    this.atStart.set(el.scrollLeft <= 4);
    this.atEnd.set(el.scrollLeft + el.clientWidth >= el.scrollWidth - 4);
  }

  /**
   * Scroll by approximately one card width in the given direction
   * (-1 = left, +1 = right). The "card width" is computed from the
   * first child element's offsetWidth + the gap (read from CSS).
   * We don't need pixel-perfect — the scroll-snap on the track makes
   * sure the result aligns to a card boundary.
   */
  scrollByCard(direction: -1 | 1): void {
    const el = this.scrollerRef?.nativeElement;
    if (!el) return;
    const firstChild = el.firstElementChild as HTMLElement | null;
    if (!firstChild) return;
    /* Card width + 24px gap (matches SCSS). Falls back to clientWidth
     * if for some reason child width is 0. */
    const step = (firstChild.offsetWidth || el.clientWidth) + 24;
    el.scrollBy({ left: step * direction, behavior: 'smooth' });
  }
}
