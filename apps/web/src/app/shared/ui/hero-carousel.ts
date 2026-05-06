import {
  Component,
  ChangeDetectionStrategy,
  Input,
  signal,
  computed,
  inject,
  PLATFORM_ID,
  OnDestroy,
  effect,
} from '@angular/core';
import { isPlatformBrowser } from '@angular/common';

import { SkeletonShimmerComponent } from './skeleton-shimmer';
import type { Product } from '../../features/catalog/product.model';

/**
 * HeroCarouselComponent — coverflow product carousel for the home-page
 * hero band.
 *
 * Visual model:
 *   5 portrait product cards rendered simultaneously, positioned in
 *   horizontal slots:
 *     - Slot -2 (far left, small):    ~140×210, dim
 *     - Slot -1 (left, medium):       ~200×300
 *     - Slot  0 (center, large):      ~300×450, highlighted (full
 *                                     opacity, deeper shadow, bigger
 *                                     overlay panel)
 *     - Slot +1 (right, medium):      ~200×300
 *     - Slot +2 (far right, small):   ~140×210, dim
 *
 *   Auto-advances every 6s by rotating which card is at slot 0.
 *   User can click any non-center card to bring it to center.
 *
 *   The aspect ratio of each card is 2:3 (portrait) so product
 *   images display fully without cropping, addressing the W3 round-2
 *   feedback that horizontal cinematic slides cropped the products.
 *
 * Implementation notes:
 *   - All 5 cards rendered in DOM continuously; they're positioned
 *     via transform: translateX/scale rather than scroll
 *   - Active slot is computed from each card's logical index relative
 *     to the activeIndex signal: slotOf(i) = i - activeIndex (mod 5)
 *     wrapped to range [-2, 2]
 *   - The "wrap" is necessary because we always show 5 cards regardless
 *     of which one is active — at activeIndex=0, card 4 sits at slot
 *     -1 (the left medium position) by wrapping
 *   - CSS transitions handle the animation between slots (no JS animation
 *     library)
 *
 * SSR safety:
 *   - Server renders with activeIndex=0 (first card centered)
 *   - Auto-advance only runs in the browser
 *   - First slide's image gets fetchpriority='high' for LCP
 *   - prefers-reduced-motion disables auto-advance entirely
 *
 * Accessibility:
 *   - Each slide has role='group' + aria-roledescription='slide'
 *   - Active slide gets aria-current
 *   - Dot indicators are role='tab' with aria-selected
 *   - Manual nav arrows have descriptive aria-labels
 *   - Tab order: dots → active slide CTA → next slide CTA, etc.
 */
@Component({
  selector: 'ui-hero-carousel',
  standalone: true,
  imports: [SkeletonShimmerComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="hero-carousel" role="region" aria-label="Featured products">

      @if (loading) {
        <!-- Skeleton state — center card placeholder, suggests the
             coverflow shape without trying to mimic the full 5-card
             arrangement. -->
        <div class="hero-carousel__skeleton">
          <ui-skeleton-shimmer width="100%" height="100%" [rounded]="false" />
        </div>
      } @else if (slides().length > 0) {

        <div
          class="hero-carousel__stage"
          (mouseenter)="onPause()"
          (mouseleave)="onResume()"
          (focusin)="onPause()"
          (focusout)="onResume()"
        >
          @for (product of slides(); track product.id; let i = $index) {
            <article
              class="hero-carousel__slide"
              [class.is-center]="slotOf(i) === 0"
              [class.is-near]="slotOf(i) === -1 || slotOf(i) === 1"
              [class.is-far]="slotOf(i) === -2 || slotOf(i) === 2"
              [attr.data-slot]="slotOf(i)"
              [attr.aria-hidden]="slotOf(i) !== 0 ? 'true' : 'false'"
              [attr.aria-current]="slotOf(i) === 0 ? 'true' : null"
              role="group"
              [attr.aria-roledescription]="'slide'"
              [attr.aria-label]="'Slide ' + (i + 1) + ' of ' + slides().length"
            >
              @if (slotOf(i) === 0) {
                <!-- Center card: full link to product. -->
                <a
                  [href]="productUrl(product.slug)"
                  class="hero-carousel__slide-link"
                  [attr.aria-label]="product.name + ' by ' + (product.vendor?.name ?? 'designer')"
                >
                  @if (product.primary_image; as img) {
                    <img
                      [src]="img.url"
                      [alt]="img.alt ?? product.name"
                      class="hero-carousel__image"
                      [attr.loading]="i === 0 ? 'eager' : 'lazy'"
                      [attr.fetchpriority]="i === 0 ? 'high' : 'auto'"
                      decoding="async"
                    />
                  } @else {
                    <div class="hero-carousel__image-fallback" aria-hidden="true">
                      <span>{{ initial(product.name) }}</span>
                    </div>
                  }

                  <div class="hero-carousel__overlay">
                    @if (product.vendor; as vendor) {
                      <p class="hero-carousel__vendor">{{ vendor.name }}</p>
                    }
                    <h3 class="hero-carousel__product-name">{{ product.name }}</h3>
                    <div class="hero-carousel__divider" aria-hidden="true">
                      <span class="hero-carousel__divider-line"></span>
                      <span class="hero-carousel__divider-mark"></span>
                      <span class="hero-carousel__divider-line"></span>
                    </div>
                    <p class="hero-carousel__price">
                      {{ product.price.currency }} {{ product.price.amount }}
                    </p>
                    <span class="hero-carousel__cta">
                      View product
                      <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M5 12h14M13 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                      </svg>
                    </span>
                  </div>
                </a>
              } @else {
                <!-- Non-center cards: button that brings the slide to center
                     when clicked. Image only, no overlay (overlay would clutter
                     the smaller frames). -->
                <button
                  type="button"
                  class="hero-carousel__slide-button"
                  (click)="goTo(i)"
                  [attr.aria-label]="'Show ' + product.name + ' by ' + (product.vendor?.name ?? 'designer')"
                  tabindex="-1"
                >
                  @if (product.primary_image; as img) {
                    <img
                      [src]="img.url"
                      [alt]="''"
                      class="hero-carousel__image"
                      loading="lazy"
                      decoding="async"
                    />
                  } @else {
                    <div class="hero-carousel__image-fallback" aria-hidden="true">
                      <span>{{ initial(product.name) }}</span>
                    </div>
                  }
                </button>
              }
            </article>
          }
        </div>

        @if (slides().length > 1) {
          <!-- Manual nav arrows — desktop hover-revealed via CSS. -->
          <button
            type="button"
            class="hero-carousel__arrow hero-carousel__arrow--left"
            (click)="goPrev()"
            aria-label="Previous slide"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>

          <button
            type="button"
            class="hero-carousel__arrow hero-carousel__arrow--right"
            (click)="goNext()"
            aria-label="Next slide"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>

          <!-- Dot indicators. -->
          <div class="hero-carousel__dots" role="tablist" aria-label="Choose slide">
            @for (product of slides(); track product.id; let i = $index) {
              <button
                type="button"
                role="tab"
                class="hero-carousel__dot"
                [class.is-active]="activeIndex() === i"
                [attr.aria-selected]="activeIndex() === i"
                [attr.aria-label]="'Go to slide ' + (i + 1)"
                (click)="goTo(i)"
              ></button>
            }
          </div>
        }

      }
    </div>
  `,
  styleUrl: './hero-carousel.scss',
})
export class HeroCarouselComponent implements OnDestroy {
  /**
   * Products to render as carousel slides. Capped to MAX_SLIDES because
   * coverflow only shows 5 at a time meaningfully — more wastes data
   * fetching for slots the user never sees.
   */
  @Input() set products(value: Product[] | null) {
    this._products.set(value ?? []);
  }

  /** Loading state — true = render skeleton, false = render slides. */
  @Input() loading: boolean = false;

  /* ----- Internal state -------------------------------------------- */

  private platformId = inject(PLATFORM_ID);
  private _products = signal<Product[]>([]);

  /** Coverflow shows exactly 5 slots (-2, -1, 0, +1, +2). More than 5
   *  products provides no extra value. */
  private readonly MAX_SLIDES = 5;

  /** How long each slide stays before auto-advancing. */
  private readonly AUTOPLAY_MS = 6000;

  /** The active slide index (0-based). Center card is slides()[activeIndex]. */
  readonly activeIndex = signal(0);

  /** The visible slides — capped to MAX_SLIDES. */
  readonly slides = computed(() => {
    return this._products().slice(0, this.MAX_SLIDES);
  });

  /** Auto-advance timer handle (browser only). */
  private autoplayTimer: ReturnType<typeof setInterval> | null = null;

  /** True while the carousel is being interacted with (paused). */
  private isPaused = false;

  /** Whether the user prefers reduced motion (disables auto-advance). */
  private prefersReducedMotion = false;

  constructor() {
    /* Boot autoplay once we're on the browser AND we have slides AND
       the user doesn't prefer reduced motion. The effect re-runs if
       slides change (e.g., loading -> loaded transition). */
    effect(() => {
      const slides = this.slides();
      if (!isPlatformBrowser(this.platformId)) return;
      if (slides.length <= 1) return;
      if (this.prefersReducedMotion) return;
      this.startAutoplay();
    });

    if (isPlatformBrowser(this.platformId)) {
      this.prefersReducedMotion =
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }
  }

  ngOnDestroy(): void {
    this.stopAutoplay();
  }

  /* ----- Slot computation ------------------------------------------ */

  /**
   * Compute which slot a slide should occupy given its index and the
   * current active index. Returns -2, -1, 0, 1, or 2.
   *
   * The slot is computed by taking (i - activeIndex) modulo total slides,
   * then wrapping the result into the range [-2, 2]. This means at
   * activeIndex=0 with 5 total slides:
   *   slide 0 → slot 0  (center)
   *   slide 1 → slot 1  (right medium)
   *   slide 2 → slot 2  (far right)
   *   slide 3 → slot -2 (far left)  — wrapped from slot 3 = -2
   *   slide 4 → slot -1 (left medium) — wrapped from slot 4 = -1
   */
  slotOf(i: number): number {
    const total = this.slides().length;
    if (total === 0) return 0;
    let slot = i - this.activeIndex();
    /* Wrap: e.g. with 5 slides, slot 3 → -2, slot 4 → -1. */
    const half = Math.floor(total / 2);
    if (slot > half) slot -= total;
    if (slot < -half) slot += total;
    return slot;
  }

  /* ----- Template event handlers ----------------------------------- */

  /** Move active slide back by 1; wraps at the start (so endless rotation). */
  goPrev(): void {
    const total = this.slides().length;
    if (total === 0) return;
    const next = (this.activeIndex() - 1 + total) % total;
    this.goTo(next);
  }

  /** Move active slide forward by 1; wraps at the end. */
  goNext(): void {
    const total = this.slides().length;
    if (total === 0) return;
    const next = (this.activeIndex() + 1) % total;
    this.goTo(next);
  }

  /** Jump directly to a specific slide index. */
  goTo(index: number): void {
    if (index < 0 || index >= this.slides().length) return;
    this.activeIndex.set(index);
    this.restartAutoplay();
  }

  /** User has hovered / focused → pause auto-advance. */
  onPause(): void {
    this.isPaused = true;
    this.stopAutoplay();
  }

  /** User has unhovered / unfocused → resume auto-advance. */
  onResume(): void {
    this.isPaused = false;
    this.startAutoplay();
  }

  /* ----- Helpers --------------------------------------------------- */

  productUrl(slug: string): string {
    return `/product/${slug}`;
  }

  initial(name: string | undefined): string {
    return (name?.[0] ?? '?').toUpperCase();
  }

  /* ----- Internal: autoplay machinery ------------------------------ */

  private startAutoplay(): void {
    if (this.autoplayTimer) return;
    if (this.slides().length <= 1) return;
    if (this.isPaused) return;
    if (!isPlatformBrowser(this.platformId)) return;

    this.autoplayTimer = setInterval(() => {
      this.goNext();
    }, this.AUTOPLAY_MS);
  }

  private stopAutoplay(): void {
    if (this.autoplayTimer) {
      clearInterval(this.autoplayTimer);
      this.autoplayTimer = null;
    }
  }

  private restartAutoplay(): void {
    this.stopAutoplay();
    this.startAutoplay();
  }
}
