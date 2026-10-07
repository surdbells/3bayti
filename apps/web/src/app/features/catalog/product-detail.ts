import {
  Component,
  ChangeDetectionStrategy,
  inject,
  computed,
  signal,
  effect,
  untracked,
  afterNextRender,
  afterRenderEffect,
  ViewChild,
  ElementRef,
  OnDestroy,
} from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { toSignal, toObservable } from '@angular/core/rxjs-interop';
import { catchError, from, map, of, switchMap, tap } from 'rxjs';

import { SeoService } from '../../core/seo/seo.service';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import {
  productSchema,
  breadcrumbSchema,
  type ProductSchemaOpts,
} from '../../core/seo/schema.helpers';
import { environment } from '../../../environments/environment';
import {
  ButtonComponent,
  ContainerComponent,
  HeadingComponent,
  TextComponent,
  StackComponent,
  ShareButtonsComponent,
} from '../../shared/ui';
import { ProductCardComponent } from './product-card';
import { ScrollRailDirective } from './scroll-rail.directive';
import { GiftCardNudgeComponent } from '../gift-cards/gift-card-nudge';
import type {
  Money,
  Product,
  ProductDetail,
  ProductReview,
  ProductSize,
  ProductColor,
  PublicReview,
  StoreSizeChartRow,
} from './product.model';
import { mapPublicReview } from './product.model';
import { RecommendationsService } from './recommendations.service';
import { CompleteTheLookService, type CompleteLookItem, type CompleteLookResult } from './complete-the-look.service';
import { AnalyticsService } from '../../core/monitoring/analytics.service';
import { StoreService } from './store.service';
import { CartService } from '../../core/cart/cart.service';
import { CartDrawerService } from '../../core/cart/cart-drawer.service';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import { cfImage, CF_PRESETS, type CfImageOptions } from '../../shared/util/image-transform';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { AuthService } from '../../core/auth/auth.service';
import { MeasurementService, MEASUREMENT_FIELDS } from '../account/measurement.service';
import { ConciergeService } from '../ai-concierge/concierge.service';
import { WishlistService } from '../wishlist/wishlist.service';

/** Categories where size selection is optional, a size (incl. CUSTOM) is
 *  never required before add-to-cart, and CUSTOM doesn't force measurements. */
const SIZE_OPTIONAL_CATEGORIES = ['bags', 'accessories', 'kaftans', 'mukhawars'];

/** "You may also like" is a single scrollable row: at most this many cards
 *  (also the limit asked of the recommendations engine). */
const RELATED_LIMIT = 8;

/** Widths offered to the browser for the gallery images (srcset). */
const GALLERY_WIDTHS = [480, 720, 900, 1200] as const;

/**
 * Rendered gallery width (mirrors the SCSS layout in product-detail.scss):
 *   - >= 1024px: the stage width the desktop CSS derives from the viewport
 *     HEIGHT, i.e. 3/4 of --pdp-stage-h = clamp(min(420px, 100vh - 226.67px),
 *     100vh - 270.67px, 650px) (the floor / first-screen fit / cap; the px
 *     terms are --pdp-header-rest + offsets + the thumbnail row). Rounded
 *     so the slot is never under-declared. Without this entry a 1280x720
 *     window (337px stage) would fetch the 900w file at 2x DPR.
 *   - otherwise the 3:4 stage is never taller than 650px, so never wider
 *     than 487.5px; below that it is edge-to-edge on phones (100vw).
 * A browser that can't parse the math in the first entry skips it and uses
 * the next one (a valid upper bound).
 */
const GALLERY_SIZES =
  '(min-width: 1024px) calc(clamp(min(420px, 100vh - 226px), 100vh - 270px, 650px) * 3 / 4), ' +
  '(min-width: 488px) 488px, 100vw';

/** Image transform for the small "Complete the look" cards (~120-140px wide). */
const CTL_IMAGE: CfImageOptions = { width: 320, quality: 80, fit: 'cover', format: 'auto' };

/** Empty "Complete the look" result (no request / failure). */
const EMPTY_LOOK: CompleteLookResult = { interactionId: null, items: [] };

/** Placeholder cards in the "Complete the look" skeleton (the API's default limit). */
const CTL_SKELETON_SLOTS = [0, 1, 2, 3, 4, 5] as const;

/** A "Complete the look" response, tagged with the product id it belongs to. */
interface LookForProduct {
  productId: number | null;
  result: CompleteLookResult;
}

/**
 * "Complete the look" request state for the CURRENT product:
 *   - idle:    no product shown (nothing to reserve);
 *   - loading: the product is shown but its complements are not in yet
 *              (the strip's skeleton holds its slot, so nothing shifts);
 *   - ready:   answered (the real strip, or nothing when it is empty / failed).
 */
export type CompleteTheLookStatus = 'idle' | 'loading' | 'ready';

/** One gallery slide, precomputed so the template stays declarative. */
export interface GallerySlide {
  url: string;
  alt: string | null;
  width: number;
  height: number;
  src: string;
  srcset: string | null;
}

/** Content accordions under the buy area (Description / Details). */
export type PdpSection = 'description' | 'details';

/** Per-complement quick-add state in the "Complete the look" strip. */
type QuickAddState = 'adding' | 'added' | 'error';

/**
 * Build a srcset for a gallery image, or null when the URL is not on the
 * Cloudflare-transformable origin (passthrough: every width would be the
 * same file, so a srcset would only add noise).
 */
function gallerySrcset(url: string): string | null {
  const probe = cfImage(url, { ...CF_PRESETS.detail, width: GALLERY_WIDTHS[0] });
  if (probe === url) return null;
  return GALLERY_WIDTHS.map((w) => `${cfImage(url, { ...CF_PRESETS.detail, width: w })} ${w}w`).join(', ');
}

function prefersReducedMotion(): boolean {
  return typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Product detail page (PDP), `/product/:slug`.
 *
 * Layout (compact / modern pass):
 *   - Desktop (>= 1024px): gallery left (single swipeable 3:4 stage, never
 *     taller than 650px, with a horizontal thumbnail row below it, sized to
 *     fit the first screen), info column right. Both columns are
 *     position:sticky below the (measured) site header, so whichever is
 *     shorter stays in view while the other scrolls.
 *   - Mobile (< 768px): full-bleed swipeable gallery (scroll-snap, dots +
 *     count), stacked info, and a sticky bottom bar that appears only once
 *     the main Add to cart has scrolled out of view.
 *   - The info column carries, top to bottom: one "Sold by · Visit store"
 *     row, title, rating/stock, price, options (colours collapse to two
 *     rows), an optional seller-note disclosure, the buy box, secondary
 *     actions (try-on, customization), the "Complete the look" strip,
 *     a one-line gift-card nudge, share + trust rows and the Description /
 *     Details accordions.
 *   - Below the fold: Reviews and "You may also like" (single row), both
 *     rendered with @defer (on viewport; prefetch on idle).
 *
 * SEO: title / meta / canonical / OG plus Product + BreadcrumbList JSON-LD
 * (see the SEO effect in the constructor).
 */
@Component({
  selector: 'app-product-detail',
  standalone: true,
  imports: [CfImagePipe,
    ButtonComponent,
    ContainerComponent,
    HeadingComponent,
    TextComponent,
    StackComponent,
    ProductCardComponent,
    ScrollRailDirective,
    GiftCardNudgeComponent,
    ShareButtonsComponent,
    TranslatePipe,
    RouterLink,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './product-detail.html',
  styleUrl: './product-detail.scss',
})
export class ProductDetailComponent implements OnDestroy {
  private route = inject(ActivatedRoute);
  private routed = inject(RoutedHttpClient);
  private seo = inject(SeoService);
  private recsService = inject(RecommendationsService);
  private ctlService = inject(CompleteTheLookService);
  private analytics = inject(AnalyticsService);
  private stores = inject(StoreService);
  private cart = inject(CartService);
  private cartDrawer = inject(CartDrawerService);
  private i18n = inject(TranslateService);
  private auth = inject(AuthService);
  private measurements = inject(MeasurementService);
  private concierge = inject(ConciergeService);
  private wishlist = inject(WishlistService);
  private router = inject(Router);
  /** Signed-in state, gates the custom-size measurement form. */
  protected readonly isAuthenticated = this.auth.isAuthenticated;

  /** Template constants. */
  protected readonly gallerySizes = GALLERY_SIZES;
  protected readonly ctlImage = CTL_IMAGE;

  /** Last product id we beaconed a `product_viewed` for (de-dupes effect re-runs). */
  private lastViewedId: number | null = null;

  /** True if the API returned 404 for this slug. */
  readonly notFound = signal(false);

  /**
   * Product detail fetched for the current route slug.
   */
  readonly product = toSignal(
    this.route.paramMap.pipe(
      switchMap((params) => {
        const slug = params.get('slug') ?? '';
        if (!slug) return of(null);
        return this.fetchProduct$(slug);
      }),
    ),
    { initialValue: null as ProductDetail | null },
  );

  /** True between route change and data arrival. */
  readonly loading = computed(() => this.product() === null && !this.notFound());

  /**
   * Recommendations from the X.12 engine (co-purchase + category +
   * popular fallback), loaded for the current product slug.
   *
   * The "you may also like" row prefers these engine results and only
   * falls back to the product's own `related_products` (from the single
   * PDP fetch) when the engine returns nothing.
   */
  readonly recommendations = toSignal(
    this.route.paramMap.pipe(
      switchMap((params) => {
        const slug = params.get('slug') ?? '';
        if (slug === '') {
          return of([] as Product[]);
        }
        return from(this.recsService.forProduct(slug, RELATED_LIMIT)).pipe(
          map((recs) => recs.map((r) => r.product)),
          catchError(() => of([] as Product[])),
        );
      }),
    ),
    { initialValue: [] as Product[] },
  );

  /** v3 id of the loaded product; equal ids never re-emit (computed dedupes). */
  private readonly productId = computed<number | null>(() => this.product()?.id ?? null);

  /**
   * "Complete the Look" complements. Unlike recommendations (slug-keyed), this
   * endpoint is keyed by the v3 numeric id, which is only known once the product
   * has loaded, so the stream is driven off the product id (deduped, so the
   * same product never triggers a second request). Each answer is tagged with
   * the id it was fetched for (a failure answers EMPTY_LOOK for that id).
   */
  private readonly lookFetch = toSignal(
    toObservable(this.productId).pipe(
      switchMap((id) => {
        if (!id) {
          return of<LookForProduct>({ productId: null, result: EMPTY_LOOK });
        }
        return from(this.ctlService.forProduct(id)).pipe(
          map((result): LookForProduct => ({ productId: id, result })),
          catchError(() => of<LookForProduct>({ productId: id, result: EMPTY_LOOK })),
        );
      }),
    ),
    { initialValue: { productId: null, result: EMPTY_LOOK } as LookForProduct },
  );

  /**
   * Request state for the CURRENT product. Derived synchronously from the
   * product id, so it reads 'loading' in the very render that first paints a
   * product (and again the moment the id changes, e.g. after clicking a
   * complement), before the request stream has even emitted: the skeleton
   * is in place from the first product paint and the strip never pops in.
   */
  readonly completeTheLookStatus = computed<CompleteTheLookStatus>(() => {
    const id = this.productId();
    if (!id) return 'idle';
    return this.lookFetch().productId === id ? 'ready' : 'loading';
  });

  /** The current product's complements (never a previous product's while the next loads). */
  readonly completeTheLook = computed<CompleteLookResult>(() => {
    const fetched = this.lookFetch();
    const id = this.productId();
    return id && fetched.productId === id ? fetched.result : EMPTY_LOOK;
  });

  /** The complement cards for the "Complete the look" strip. */
  readonly completeTheLookItems = computed<CompleteLookItem[]>(() => this.completeTheLook().items);

  /** Placeholder cards for the strip's loading skeleton. */
  protected readonly ctlSkeletonSlots = CTL_SKELETON_SLOTS;

  /**
   * The products for the "you may also like" row: engine recommendations
   * when present, otherwise the PDP's related_products, otherwise empty
   * (section hidden). Capped at {@link RELATED_LIMIT}.
   */
  readonly relatedProducts = computed<Product[]>(() => {
    const engine = this.recommendations();
    const list = engine.length > 0 ? engine : (this.product()?.related_products ?? []);
    return list.slice(0, RELATED_LIMIT);
  });

  /**
   * Absolute canonical URL for this product, used by the share buttons.
   * Mirrors the SEO canonical (SITE_URL + /product/:slug).
   */
  readonly shareUrl = computed<string>(() => {
    const slug = this.product()?.slug;
    return slug ? `${environment.SITE_URL}/product/${slug}` : environment.SITE_URL;
  });

  /* ----- Gallery -----------------------------------------------------------
   * One scroll-snap track holds every image at all breakpoints: swipe on
   * touch, trackpad-scroll / arrows / thumbnails on desktop. The track's
   * scroll position and `activeImageIndex` are kept in sync both ways. */

  /** Currently-displayed image (thumbnails / arrows / swipes switch it). */
  readonly activeImageIndex = signal(0);

  readonly activeImage = computed(() => {
    const p = this.product();
    if (!p) return null;
    const images = p.images ?? [];
    const fallback = p.primary_image;
    return images[this.activeImageIndex()] ?? fallback;
  });

  /** Gallery slides: every image, or the primary image alone. */
  readonly slides = computed<GallerySlide[]>(() => {
    const p = this.product();
    if (!p) return [];
    const source = p.images?.length ? p.images : p.primary_image ? [p.primary_image] : [];
    return source
      .filter((img) => !!img?.url)
      .map((img) => ({
        url: img.url,
        alt: img.alt || null,
        width: img.width || 900,
        height: img.height || 1200,
        src: cfImage(img.url, CF_PRESETS.detail),
        srcset: gallerySrcset(img.url),
      }));
  });

  @ViewChild('track') private trackEl?: ElementRef<HTMLElement>;
  /** The horizontal thumbnail row under the stage (>= 768px). */
  @ViewChild('thumbs') private thumbsEl?: ElementRef<HTMLElement>;
  /** Re-reveals the active thumb when the row appears (display:none ->
   *  flex at 768px) or changes width (it follows the viewport height). */
  private thumbsObserver?: ResizeObserver;
  private observedThumbs: HTMLElement | null = null;
  /** Slide the track is programmatically scrolling to (ignore the frames in between). */
  private trackTarget: number | null = null;
  private trackTargetTimer: ReturnType<typeof setTimeout> | null = null;
  private trackRaf = 0;
  /** Set on product change: snap the (reused) track back to the first slide. */
  private pendingTrackReset = false;

  /* ----- Gallery lightbox (PDP3) -----------------------------------------
   * Click-to-zoom fullscreen viewer. Focus moves to the close button on
   * open and returns to the slide now showing on close; Tab is trapped within
   * the dialog; Esc closes; Left/Right cycle images. CSR-only. */
  readonly lightboxOpen = signal(false);
  private lightboxTrigger: HTMLElement | null = null;

  @ViewChild('lightbox') private lightboxEl?: ElementRef<HTMLElement>;
  @ViewChild('lightboxClose') private lightboxCloseBtn?: ElementRef<HTMLButtonElement>;

  /** Number of gallery images (used to bound prev/next + show controls). */
  readonly imageCount = computed(() => this.slides().length);

  openLightbox(): void {
    if (!this.activeImage()) return;
    this.lightboxTrigger = (typeof document !== 'undefined'
      ? (document.activeElement as HTMLElement | null)
      : null);
    this.lightboxOpen.set(true);
    // Focus the close button once the overlay has rendered.
    setTimeout(() => this.lightboxCloseBtn?.nativeElement.focus(), 0);
  }

  closeLightbox(): void {
    if (!this.lightboxOpen()) return;
    this.lightboxOpen.set(false);
    const trigger = this.lightboxTrigger;
    this.lightboxTrigger = null;
    const track = this.trackEl?.nativeElement;
    const fromSlide =
      !trigger ||
      !trigger.isConnected ||
      (typeof document !== 'undefined' && trigger === document.body) ||
      !!track?.contains(trigger);
    if (fromSlide) {
      /* The lightbox may have cycled images: bring the track along and
       * return focus to the CURRENT slide, not the one it was opened from.
       * Focusing that (now off-screen) slide would scroll the track back to
       * it, out of sync with the active index; goToSlide focuses with
       * preventScroll. */
      this.goToSlide(this.activeImageIndex(), { focus: true, smooth: false });
    } else {
      this.scrollTrackTo(this.activeImageIndex(), false);
      trigger?.focus({ preventScroll: true });
    }
  }

  lightboxNext(): void {
    const n = this.imageCount();
    if (n > 1) this.activeImageIndex.set((this.activeImageIndex() + 1) % n);
  }

  lightboxPrev(): void {
    const n = this.imageCount();
    if (n > 1) this.activeImageIndex.set((this.activeImageIndex() - 1 + n) % n);
  }

  /** Close only when the backdrop itself (not the dialog) is clicked. */
  onLightboxBackdrop(event: MouseEvent): void {
    if (event.target === event.currentTarget) this.closeLightbox();
  }

  /** Esc closes; arrows cycle; Tab is trapped within the dialog. */
  onLightboxKeydown(event: KeyboardEvent): void {
    switch (event.key) {
      case 'Escape':
        event.preventDefault();
        this.closeLightbox();
        return;
      case 'ArrowRight':
        event.preventDefault();
        this.lightboxNext();
        return;
      case 'ArrowLeft':
        event.preventDefault();
        this.lightboxPrev();
        return;
      case 'Tab': {
        const root = this.lightboxEl?.nativeElement;
        if (!root) return;
        const focusables = Array.from(
          root.querySelectorAll<HTMLElement>('button:not([disabled])'),
        );
        if (focusables.length === 0) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        const active = document.activeElement;
        if (event.shiftKey && active === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && active === last) {
          event.preventDefault();
          first.focus();
        }
        return;
      }
    }
  }

  /* ----- Content accordions (Description / Details) ----------------------
   * Disclosure buttons (aria-expanded + aria-controls) over labelled
   * regions; Description is open by default. Reviews is its own section
   * below the fold. */
  private static readonly DEFAULT_SECTIONS: ReadonlySet<PdpSection> = new Set<PdpSection>(['description']);
  readonly openSections = signal<ReadonlySet<PdpSection>>(ProductDetailComponent.DEFAULT_SECTIONS);

  readonly hasDescriptionContent = computed<boolean>(() => this.descriptionParagraphs().length > 0);
  readonly hasDetailsContent = computed<boolean>(() => {
    const p = this.product();
    return !!(p?.fabric || (p?.materials?.length ?? 0) > 0 || p?.care_instructions || p?.sku);
  });

  isSectionOpen(section: PdpSection): boolean {
    return this.openSections().has(section);
  }

  toggleSection(section: PdpSection): void {
    this.openSections.update((open) => {
      const next = new Set(open);
      if (next.has(section)) next.delete(section);
      else next.add(section);
      return next;
    });
  }

  /* ----- Reviews section anchor ------------------------------------------
   * The rating link and the /product/x#reviews deep link scroll to the
   * Reviews section (which also triggers its @defer viewport block). A
   * plain href="#reviews" would resolve against <base href="/"> and leave
   * the page, so the link is handled here. */
  @ViewChild('reviewsSection') private reviewsSectionEl?: ElementRef<HTMLElement>;
  private pendingReviewsScroll = false;

  scrollToReviews(event?: Event): void {
    event?.preventDefault();
    const el = this.reviewsSectionEl?.nativeElement;
    if (!el) return;
    if (typeof el.scrollIntoView === 'function') {
      el.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
    }
    el.focus({ preventScroll: true });
  }

  /* ----- Sticky bottom bar (mobile / tablet) ------------------------------
   * The fixed price + Add to cart bar appears only once the main buy box has
   * scrolled ABOVE the viewport, and hides again near the end of the page
   * so it never covers the footer. Two IntersectionObservers (feature-
   * guarded; CSR-only) drive the two signals. */
  readonly buyBoxOutOfView = signal(false);
  readonly nearPageEnd = signal(false);
  readonly stickyCtaVisible = computed(() => this.buyBoxOutOfView() && !this.nearPageEnd());

  @ViewChild('buyBox') private buyBoxEl?: ElementRef<HTMLElement>;
  @ViewChild('ctaSentinel') private ctaSentinel?: ElementRef<HTMLElement>;
  private buyBoxObserver?: IntersectionObserver;
  private sentinelObserver?: IntersectionObserver;
  private observedBuyBox: HTMLElement | null = null;
  private observedSentinel: HTMLElement | null = null;

  /* ----- Site header height ----------------------------------------------
   * The sticky columns (and the reviews scroll target) sit just below the
   * sticky site header, whose height changes as it condenses on scroll.
   * Measured with a ResizeObserver; the SCSS falls back to 112px. */
  readonly headerHeight = signal<number | null>(null);
  private headerObserver?: ResizeObserver;

  ngOnDestroy(): void {
    this.buyBoxObserver?.disconnect();
    this.sentinelObserver?.disconnect();
    this.headerObserver?.disconnect();
    this.colorChipsObserver?.disconnect();
    this.thumbsObserver?.disconnect();
    if (this.trackTargetTimer !== null) clearTimeout(this.trackTargetTimer);
    if (this.trackRaf && typeof cancelAnimationFrame !== 'undefined') cancelAnimationFrame(this.trackRaf);
    if (this.tryOnPollHandle !== null) {
      clearTimeout(this.tryOnPollHandle);
      this.tryOnPollHandle = null;
    }
  }

  /* ----- Buy box: variant selection + quantity + add-to-cart -------- */

  /** Selected size label (null until the shopper picks one). */
  readonly selectedSize = signal<string | null>(null);
  /** Selected colour label (null until the shopper picks one). */
  readonly selectedColor = signal<string | null>(null);
  /** Vendor's EXTRA measurement (free text), additional measurements the
   *  seller needs beyond the account profile. Empty until the shopper types. */
  readonly extraMeasurement = signal('');
  /** Optional free-text note / instructions for the seller, sent with the order. */
  readonly note = signal('');
  /** Quantity to add (1–99). */
  readonly quantity = signal(1);
  /** True while an add-to-cart request is in flight. */
  readonly adding = signal(false);
  /** Surfaced when an add-to-cart attempt fails; cleared on the next try. */
  readonly addError = signal<string | null>(null);
  /**
   * Set once the shopper tries to add to cart without a required selection.
   * The "Select a size / colour…" hints only render after that attempt.
   */
  readonly attemptedAdd = signal(false);

  /** Whether this product offers a size / colour axis at all. */
  readonly hasSizes = computed(() => (this.product()?.sizes?.length ?? 0) > 0);
  readonly hasColors = computed(() => (this.product()?.colors?.length ?? 0) > 0);
  /** Whether the product asks for the vendor's EXTRA measurement field. */
  readonly requiresExtraMeasurement = computed(() => this.product()?.requires_measurement === true);
  /** Optional on-PDP guidance shown beside the extra-measurement field. */
  readonly measurementInstructions = computed(() => this.product()?.measurement_instructions ?? null);

  @ViewChild('sizeGroup') private sizeGroupEl?: ElementRef<HTMLElement>;
  @ViewChild('colorGroup') private colorGroupEl?: ElementRef<HTMLElement>;
  @ViewChild('measurementGroup') private measurementGroupEl?: ElementRef<HTMLElement>;
  @ViewChild('customGroup') private customGroupEl?: ElementRef<HTMLElement>;

  /* ----- Colour chips: collapse to two rows --------------------------------
   * Long colour lists (24 text chips on some products) collapse to two rows
   * with a "+N more" toggle. Which chips fall past row 2 is measured from
   * the laid-out chips (offsetTop), so it adapts to any width/language; the
   * overflow chips stay in the layout (visibility:hidden, so they are not
   * focusable) which keeps the measurement stable. The expanded state
   * persists while on the page. A selected colour is never hidden: if a
   * collapse (or a resize) would push it past row 2 it is pinned first. */
  readonly colorsExpanded = signal(false);
  /** Labels of the colour chips laid out past the second row. */
  readonly colorOverflow = signal<readonly string[]>([]);
  /** A selected colour moved to the front so a collapse can't hide it. */
  private readonly pinnedColor = signal<string | null>(null);
  @ViewChild('colorChips') private colorChipsEl?: ElementRef<HTMLElement>;
  private colorChipsObserver?: ResizeObserver;
  private observedColorChips: HTMLElement | null = null;

  /** Colours in display order (the pinned selection, if any, first). */
  readonly orderedColors = computed<ProductColor[]>(() => {
    const colors = this.product()?.colors ?? [];
    const pin = this.pinnedColor();
    if (!pin) return colors;
    const hit = colors.find((c) => c.label === pin);
    return hit ? [hit, ...colors.filter((c) => c !== hit)] : colors;
  });

  /** Number of colours hidden by the collapse (0 when expanded). */
  readonly hiddenColorCount = computed(() => (this.colorsExpanded() ? 0 : this.colorOverflow().length));

  /** Whether the "+N more / Show less" toggle is needed at all. */
  readonly colorsCollapsible = computed(() => this.colorOverflow().length > 0);

  /** True when this colour chip is currently hidden by the collapse. */
  isColorHidden(label: string): boolean {
    return !this.colorsExpanded() && this.colorOverflow().includes(label);
  }

  toggleColors(): void {
    const expand = !this.colorsExpanded();
    this.colorsExpanded.set(expand);
    if (!expand) this.pinSelectedColorIfHidden();
  }

  /**
   * Measure which colour chips sit past the second row. Public so specs can
   * drive it with stubbed layout; in the browser it runs after render and
   * on every resize of the chip list.
   */
  measureColors(): void {
    const host = this.colorChipsEl?.nativeElement;
    if (!host) {
      if (this.colorOverflow().length > 0) this.colorOverflow.set([]);
      return;
    }
    const chips = Array.from(host.querySelectorAll<HTMLElement>('[data-color-label]'));
    const rowTops: number[] = [];
    for (const chip of chips) {
      const top = chip.offsetTop;
      if (!rowTops.some((t) => Math.abs(t - top) <= 4)) rowTops.push(top);
    }
    rowTops.sort((a, b) => a - b);
    const overflow =
      rowTops.length > 2
        ? chips
            .filter((chip) => chip.offsetTop >= rowTops[2] - 4)
            .map((chip) => chip.dataset['colorLabel'] ?? '')
        : [];
    const current = this.colorOverflow();
    if (overflow.length !== current.length || overflow.some((l, i) => l !== current[i])) {
      this.colorOverflow.set(overflow);
    }
    this.pinSelectedColorIfHidden();
  }

  private pinSelectedColorIfHidden(): void {
    const selected = this.selectedColor();
    if (
      selected &&
      !this.colorsExpanded() &&
      this.colorOverflow().includes(selected) &&
      this.pinnedColor() !== selected
    ) {
      this.pinnedColor.set(selected);
    }
  }

  /* ----- Seller note disclosure -------------------------------------------
   * The optional note sits behind an "Add a note for the seller" toggle. It
   * is always open while it holds text, so a typed note is never hidden
   * (and it is still sent exactly as before: trimmed, or null). */
  readonly noteExpanded = signal(false);
  readonly noteOpen = computed(() => this.noteExpanded() || this.note().trim() !== '');
  @ViewChild('noteInput') private noteInputEl?: ElementRef<HTMLTextAreaElement>;

  toggleNote(): void {
    if (this.note().trim() !== '') {
      /* Never collapse over text the shopper typed: just return to it. */
      this.noteExpanded.set(true);
      this.noteInputEl?.nativeElement.focus();
      return;
    }
    const open = !this.noteExpanded();
    this.noteExpanded.set(open);
    if (open) setTimeout(() => this.noteInputEl?.nativeElement.focus(), 0);
  }

  /* ----- Size guide (store size chart) -----------------------------------
   * A "Size guide" link sits beside the Sizes label and opens a modal
   * table of the STORE's published size chart (GET /vendors/:slug/
   * size-chart). Mirrors the mobile size-chart sheet.
   *
   * Shown only when the product offers sizes AND is not a made-to-measure
   * product (web equivalent of mobile's `!size_custom`): a custom-measurement
   * product is cut to the shopper's own body, so a generic store size chart
   * is meaningless there. requiresExtraMeasurement() is the product-level
   * made-to-measure flag.
   *
   * Columns mirror mobile: `size` plus a fixed dimension order, each row
   * flattening its values{} map. Dimensions with no data in ANY row are
   * dropped so the table never shows an all-empty column. */
  private static readonly SIZE_GUIDE_DIMENSIONS = [
    'bust', 'waist', 'hip', 'length', 'neck', 'arm', 'armhole', 'shoulder',
  ] as const;

  /** Whether to show the "Size guide" trigger beside the Sizes label. */
  readonly showSizeGuide = computed(
    () => this.hasSizes() && !this.requiresExtraMeasurement() && !this.isBagOrAccessory(),
  );

  /** Modal open/close. */
  readonly sizeGuideOpen = signal(false);
  /** True while the chart request is in flight. */
  readonly sizeGuideLoading = signal(false);
  /** Set when the fetch fails outright (vs. an empty-but-successful chart). */
  readonly sizeGuideError = signal(false);
  /** The fetched chart rows (empty array = no chart published). */
  readonly sizeGuideRows = signal<StoreSizeChartRow[]>([]);
  /** Guards re-fetching the chart once it's loaded for this product. */
  private sizeGuideLoadedFor: string | null = null;
  /** Restores focus to the trigger when the modal closes. */
  private sizeGuideTrigger: HTMLElement | null = null;

  @ViewChild('sizeGuideDialog') private sizeGuideDialogEl?: ElementRef<HTMLElement>;
  @ViewChild('sizeGuideClose') private sizeGuideCloseBtn?: ElementRef<HTMLButtonElement>;

  /* ----- Virtual try-on (Ain) -------------------------------------------
   * A "Try it on" CTA on eligible products (try_on_enabled) opens a modal
   * where the shopper uploads a photo, consents to AI photo processing, and
   * gets an AI image of themselves wearing the garment. Enqueues
   * POST /ai/try-on then polls GET /ai/try-on/:reference. Env-gated on the
   * API (TRYON_ENABLED) — a disabled deployment returns a friendly error. */
  private static readonly TRYON_ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
  private static readonly TRYON_MAX_BYTES = 8_000_000;
  private static readonly TRYON_POLL_MS = 2500;
  private static readonly TRYON_MAX_POLLS = 48; // ~2 minutes
  private static readonly TRYON_CONSENT_KEY = 'bayti_tryon_consent_v1';

  /** Whether this product offers AI virtual try-on. */
  readonly tryOnEnabled = computed(() => this.product()?.try_on_enabled === true);
  /** Modal open/close. */
  readonly tryOnOpen = signal(false);
  /** The selected photo as a data URL + mime, or null before one is chosen. */
  readonly tryOnPhoto = signal<{ dataUrl: string; mime: string; name: string } | null>(null);
  /** Explicit consent to AI photo processing (required to generate). */
  readonly tryOnConsent = signal(false);
  /** True while a generation is in flight (request + polling). */
  readonly tryOnLoading = signal(false);
  /** The generated try-on image URL once ready. */
  readonly tryOnImageUrl = signal<string | null>(null);
  /** A friendly error message when generation fails. */
  readonly tryOnError = signal<string | null>(null);
  /** Pending poll timer, cleared on close. */
  private tryOnPollHandle: ReturnType<typeof setTimeout> | null = null;
  /** Restores focus to the trigger when the modal closes. */
  private tryOnTrigger: HTMLElement | null = null;

  @ViewChild('tryOnDialog') private tryOnDialogEl?: ElementRef<HTMLElement>;
  @ViewChild('tryOnClose') private tryOnCloseBtn?: ElementRef<HTMLButtonElement>;

  /**
   * Dimension columns to render, the fixed order above, filtered to those
   * with at least one numeric value across the loaded rows. Keeps the table
   * compact: a store that only fills bust/waist/length shows three columns,
   * not eight mostly-empty ones.
   */
  readonly sizeGuideColumns = computed<string[]>(() => {
    const rows = this.sizeGuideRows();
    if (rows.length === 0) return [];
    return ProductDetailComponent.SIZE_GUIDE_DIMENSIONS.filter((dim) =>
      rows.some((r) => {
        const v = r.values?.[dim];
        return typeof v === 'number' && Number.isFinite(v);
      }),
    );
  });

  /** True once a successful fetch returned zero rows (empty-state in modal). */
  readonly sizeGuideEmpty = computed(
    () =>
      !this.sizeGuideLoading() &&
      !this.sizeGuideError() &&
      this.sizeGuideRows().length === 0,
  );

  /** Read a single cell value for the template (formatted, or an em-dash). */
  sizeGuideCell(row: StoreSizeChartRow, dim: string): string {
    const v = row.values?.[dim];
    if (typeof v !== 'number' || !Number.isFinite(v)) return '—';
    /* Trim a trailing .0 so whole numbers read "92" not "92.0". */
    return Number.isInteger(v) ? String(v) : String(v);
  }

  /** Localised label for a dimension column header. */
  sizeGuideColumnLabel(dim: string): string {
    return this.i18n.instant(`product.sizeGuide.dimensions.${dim}`);
  }

  /** Open the try-on modal; pre-check consent from a prior acknowledgement. */
  openTryOn(): void {
    if (!this.tryOnEnabled()) return;
    this.tryOnTrigger =
      typeof document !== 'undefined' ? (document.activeElement as HTMLElement | null) : null;
    this.tryOnConsent.set(this.readConsentAck());
    this.tryOnOpen.set(true);
    setTimeout(() => this.tryOnCloseBtn?.nativeElement.focus(), 0);
  }

  /** Close the modal, abort any polling, and restore focus. */
  closeTryOn(): void {
    if (!this.tryOnOpen()) return;
    this.tryOnOpen.set(false);
    this.tryOnLoading.set(false);
    if (this.tryOnPollHandle !== null) {
      clearTimeout(this.tryOnPollHandle);
      this.tryOnPollHandle = null;
    }
    this.tryOnTrigger?.focus();
    this.tryOnTrigger = null;
  }

  onTryOnBackdrop(event: MouseEvent): void {
    if (event.target === event.currentTarget) this.closeTryOn();
  }

  onTryOnKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      event.preventDefault();
      this.closeTryOn();
      return;
    }
    if (event.key !== 'Tab') return;
    const root = this.tryOnDialogEl?.nativeElement;
    if (!root) return;
    const focusables = Array.from(
      root.querySelectorAll<HTMLElement>(
        'button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
      ),
    );
    if (focusables.length === 0) return;
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    const active = document.activeElement;
    if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  /** Read the chosen photo file into a data URL (client-side validated). */
  onTryOnFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    this.tryOnError.set(null);
    this.tryOnImageUrl.set(null);

    if (!ProductDetailComponent.TRYON_ALLOWED_TYPES.includes(file.type)) {
      this.tryOnError.set(this.i18n.instant('tryOn.errorType'));
      return;
    }
    if (file.size > ProductDetailComponent.TRYON_MAX_BYTES) {
      this.tryOnError.set(this.i18n.instant('tryOn.errorSize'));
      return;
    }

    const reader = new FileReader();
    reader.onload = () => {
      const dataUrl = typeof reader.result === 'string' ? reader.result : '';
      if (dataUrl === '') {
        this.tryOnError.set(this.i18n.instant('tryOn.errorRead'));
        return;
      }
      this.tryOnPhoto.set({ dataUrl, mime: file.type, name: file.name });
    };
    reader.onerror = () => this.tryOnError.set(this.i18n.instant('tryOn.errorRead'));
    reader.readAsDataURL(file);
  }

  /** Whether the Generate button is enabled. */
  readonly canGenerateTryOn = computed(
    () => this.tryOnPhoto() !== null && this.tryOnConsent() && !this.tryOnLoading(),
  );

  /** Kick off generation: POST the job, then poll for the result. */
  startTryOn(): void {
    const p = this.product();
    const photo = this.tryOnPhoto();
    if (!p || !photo || !this.tryOnConsent() || this.tryOnLoading()) return;

    this.writeConsentAck();
    this.tryOnLoading.set(true);
    this.tryOnError.set(null);
    this.tryOnImageUrl.set(null);

    this.routed
      .post<{ job_reference: string; status: string }>('POST /ai/try-on', {
        body: {
          image: photo.dataUrl,
          mime_type: photo.mime,
          product_id: p.id,
          consent: true,
        },
      })
      .subscribe({
        next: (res) => {
          const ref = res.data?.job_reference;
          if (!ref) {
            this.failTryOn();
            return;
          }
          this.pollTryOn(ref, 0);
        },
        error: () => this.failTryOn(),
      });
  }

  private pollTryOn(reference: string, attempt: number): void {
    if (!this.tryOnOpen()) return; // modal closed → abort
    if (attempt >= ProductDetailComponent.TRYON_MAX_POLLS) {
      this.failTryOn();
      return;
    }

    this.routed
      .get<{ status: string; result_image_url: string | null; error: string | null }>(
        'GET /ai/try-on/:reference',
        { params: { reference } },
      )
      .subscribe({
        next: (res) => {
          const d = res.data;
          if (!d) {
            this.failTryOn();
            return;
          }
          if (d.status === 'succeeded' && d.result_image_url) {
            this.tryOnImageUrl.set(d.result_image_url);
            this.tryOnLoading.set(false);
            return;
          }
          if (d.status === 'failed') {
            this.failTryOn();
            return;
          }
          this.tryOnPollHandle = setTimeout(
            () => this.pollTryOn(reference, attempt + 1),
            ProductDetailComponent.TRYON_POLL_MS,
          );
        },
        error: () => {
          /* Transient poll error — retry within the attempt budget. */
          this.tryOnPollHandle = setTimeout(
            () => this.pollTryOn(reference, attempt + 1),
            ProductDetailComponent.TRYON_POLL_MS,
          );
        },
      });
  }

  private failTryOn(): void {
    this.tryOnLoading.set(false);
    this.tryOnError.set(this.i18n.instant('tryOn.errorGenerate'));
  }

  private readConsentAck(): boolean {
    try {
      return localStorage.getItem(ProductDetailComponent.TRYON_CONSENT_KEY) === '1';
    } catch {
      return false;
    }
  }

  private writeConsentAck(): void {
    try {
      localStorage.setItem(ProductDetailComponent.TRYON_CONSENT_KEY, '1');
    } catch {
      /* storage unavailable — consent still enforced server-side per request */
    }
  }

  /** Open the size-guide modal, lazily fetching the chart on first open. */
  openSizeGuide(): void {
    const slug = this.product()?.vendor?.slug;
    if (!slug) return;

    this.sizeGuideTrigger =
      typeof document !== 'undefined'
        ? (document.activeElement as HTMLElement | null)
        : null;
    this.sizeGuideOpen.set(true);
    setTimeout(() => this.sizeGuideCloseBtn?.nativeElement.focus(), 0);

    if (this.sizeGuideLoadedFor === slug) return;
    void this.loadSizeGuide(slug);
  }

  /** Close the modal and restore focus to the trigger. */
  closeSizeGuide(): void {
    if (!this.sizeGuideOpen()) return;
    this.sizeGuideOpen.set(false);
    this.sizeGuideTrigger?.focus();
    this.sizeGuideTrigger = null;
  }

  /** Close only when the backdrop itself (not the dialog) is clicked. */
  onSizeGuideBackdrop(event: MouseEvent): void {
    if (event.target === event.currentTarget) this.closeSizeGuide();
  }

  /** Esc closes; Tab is trapped within the dialog. */
  onSizeGuideKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      event.preventDefault();
      this.closeSizeGuide();
      return;
    }
    if (event.key !== 'Tab') return;
    const root = this.sizeGuideDialogEl?.nativeElement;
    if (!root) return;
    const focusables = Array.from(
      root.querySelectorAll<HTMLElement>('button:not([disabled])'),
    );
    if (focusables.length === 0) return;
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    const active = document.activeElement;
    if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  private async loadSizeGuide(slug: string): Promise<void> {
    this.sizeGuideLoading.set(true);
    this.sizeGuideError.set(false);
    try {
      const rows = await this.stores.getSizeChart(slug);
      this.sizeGuideRows.set(rows);
      this.sizeGuideLoadedFor = slug;
    } catch {
      /* 404 (unknown slug) or transient failure → error state; the modal
         offers a retry by reopening (loadedFor stays null so it re-fetches). */
      this.sizeGuideRows.set([]);
      this.sizeGuideError.set(true);
    } finally {
      this.sizeGuideLoading.set(false);
    }
  }

  /** Whether the chosen size is the made-to-order "CUSTOM" option. */
  readonly isCustomSize = computed(() => (this.selectedSize() ?? '').toUpperCase() === 'CUSTOM');
  /** True when the product's category makes size selection optional. */
  readonly isSizeOptional = computed(() => {
    const key = (this.product()?.category_slug ?? '').toLowerCase().replace(/-\d+$/, '');
    return SIZE_OPTIONAL_CATEGORIES.includes(key);
  });
  /** True for bags/accessories only, these hide the size + colour selectors
   *  and the size guide, and never require a colour on add-to-cart. Scoped
   *  narrower than isSizeOptional (which also covers kaftans/mukhawars). */
  readonly isBagOrAccessory = computed(() => {
    const key = (this.product()?.category_slug ?? '').toLowerCase().replace(/-\d+$/, '');
    return key === 'bags' || key === 'accessories';
  });
  /** Canonical body-measurement fields (mirrors the account profile). */
  readonly measurementFields = MEASUREMENT_FIELDS;
  /** Custom-size body measurements: field -> input string (cm). */
  readonly customMeasurement = signal<Record<string, string>>({});
  /** True once every measurement field holds a positive number. */
  readonly customMeasurementComplete = computed(() => {
    const m = this.customMeasurement();
    return this.measurementFields.every((f) => {
      const v = parseFloat(m[f] ?? '');
      return Number.isFinite(v) && v > 0;
    });
  });
  /** Guards a one-time prefill from the saved account default. */
  private readonly customPrefilled = signal(false);
  /** Where to send the shopper back after a sign-in prompt. */
  readonly loginReturnUrl = computed(() => {
    const slug = this.product()?.slug;
    return slug ? `/product/${slug}` : '/';
  });

  /** Read a custom-measurement field's current input string (for the template). */
  customMeasurementValue(field: string): string {
    return this.customMeasurement()[field] ?? '';
  }

  /* ----- Required-selection rules ------------------------------------------
   * Each axis reports whether it is still missing a valid selection,
   * validated against the CURRENT product's options (not just "non-null")
   * so a stale selection carried across navigation can't pass. */

  /** A required size has no valid in-stock selection. Size is required
   *  unless the category makes it optional (bags, accessories, kaftans,
   *  mukhawars); out-of-stock sizes can't be picked (the chips disable them). */
  readonly sizeMissing = computed(() => {
    const p = this.product();
    if (!p) return false;
    const sizes = p.sizes ?? [];
    if (sizes.length === 0 || this.isSizeOptional()) return false;
    const s = this.selectedSize();
    return !s || !sizes.some((x) => x.label === s && x.in_stock);
  });

  /** A required colour has no valid in-stock selection (never for bags/accessories). */
  readonly colorMissing = computed(() => {
    const p = this.product();
    if (!p) return false;
    const colors = p.colors ?? [];
    if (colors.length === 0 || this.isBagOrAccessory()) return false;
    const c = this.selectedColor();
    return !c || !colors.some((x) => x.label === c && x.in_stock);
  });

  /** Products that ask for an extra measurement need it filled in. */
  readonly measurementMissing = computed(
    () => this.product()?.requires_measurement === true && this.extraMeasurement().trim() === '',
  );

  /** CUSTOM size requires a signed-in shopper with a complete measurement,
   *  unless the category makes size optional, where it's never forced. */
  readonly customMissing = computed(
    () =>
      this.isCustomSize() &&
      !this.isSizeOptional() &&
      (!this.isAuthenticated() || !this.customMeasurementComplete()),
  );

  /** True when every required variant axis has an in-stock selection. */
  readonly selectionValid = computed(() => {
    if (!this.product()) return false;
    return !this.sizeMissing() && !this.colorMissing() && !this.measurementMissing() && !this.customMissing();
  });

  /** Whether an add-to-cart would go through right now. */
  readonly canAddToCart = computed(
    () => !!this.product()?.in_stock && this.selectionValid() && !this.adding(),
  );

  /* The hints only appear after an add-to-cart attempt, next to the option
   * they concern (inside an aria-live region). */
  private readonly hintsActive = computed(() => this.attemptedAdd() && !!this.product()?.in_stock);
  readonly showSizeHint = computed(() => this.hintsActive() && this.sizeMissing());
  readonly showColorHint = computed(() => this.hintsActive() && this.colorMissing());
  readonly showMeasurementHint = computed(() => this.hintsActive() && this.measurementMissing());
  readonly showCustomHint = computed(
    () =>
      this.hintsActive() &&
      this.isCustomSize() &&
      !this.isSizeOptional() &&
      this.isAuthenticated() &&
      !this.customMeasurementComplete(),
  );

  /**
   * Description as plain text. Source data contains HTML (<div>, <span>,
   * etc.) which we strip server-side-equivalent, no innerHTML, no XSS
   * vector, no risk of an editor injecting <script>. The description
   * isn't rich content; just product copy. Plain text is fine.
   *
   * Splits on double-line-break (or <br><br>, common in CMS output)
   * to produce paragraphs.
   */
  readonly descriptionParagraphs = computed<string[]>(() => {
    const raw = this.product()?.description ?? '';
    if (!raw) return [];

    /* Decode HTML entities (&#8212; → em-dash, &nbsp; → space). */
    const decoded = raw
      .replace(/&#(\d+);/g, (_, code) => String.fromCharCode(Number(code)))
      .replace(/&nbsp;/g, ' ')
      .replace(/&amp;/g, '&')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')
      .replace(/&#39;/g, "'");

    /* Convert structural breaks into paragraph delimiters BEFORE
       stripping tags. */
    const withBreaks = decoded
      .replace(/<\/(?:div|p|h[1-6]|li|tr)>/gi, '\n\n')
      .replace(/<br\s*\/?>/gi, '\n');

    /* Strip all remaining tags. */
    const stripped = withBreaks.replace(/<[^>]+>/g, '');

    /* Split into paragraphs, trim, drop empties. */
    return stripped
      .split(/\n\s*\n+/)
      .map((p) => p.trim())
      .filter((p) => p.length > 0);
  });

  /** True if there's a sale_price LOWER than regular price. */
  readonly isOnSale = computed(() => {
    const p = this.product();
    if (!p?.sale_price?.amount) return false;
    return p.sale_price.amount < p.price.amount;
  });

  /**
   * Savings as a whole-number percent off, or null when not on sale.
   * Percent is currency-independent (computed from the canonical AED
   * amounts), so it stays correct regardless of display currency.
   */
  readonly savingsPercent = computed(() => {
    const p = this.product();
    if (!p?.sale_price?.amount || !this.isOnSale()) return null;
    const orig = p.price.amount;
    const now = p.sale_price.amount;
    if (orig <= now) return null;
    return Math.round(((orig - now) / orig) * 100);
  });

  /**
   * Aggregate rating to display, or null if there's nothing meaningful
   * to show. Used by both the visible star block and the schema.org
   * JSON-LD AggregateRating, both must reflect the same numbers, which is
   * why this is a single source of truth.
   *
   * Returns null when the product hasn't loaded, the rating is missing, or
   * review_count is 0 (Google also rejects AggregateRating without a
   * positive reviewCount).
   */
  readonly aggregateRating = computed<{ value: number; count: number } | null>(() => {
    const p = this.product();
    if (!p) return null;
    const value = p.rating;
    const count = p.review_count ?? 0;
    if (value == null || count <= 0) return null;
    return { value, count };
  });

  /** Five star positions for the declarative star templates. */
  readonly starPositions = [1, 2, 3, 4, 5] as const;

  /* ----- Reviews: read (embedded + paginated load-more) ------------------
   * First PDP render uses the reviews already embedded in the detail
   * response (p.recent_reviews, up to 10 approved, newest first). The
   * "see all reviews" button pages the rest in via
   * GET /products/:productId/reviews (approved-only, ReviewSerializer::
   * publicShape) and appends, de-duped by id. `loadedReviews` holds the
   * extra pages; `reviews()` merges embedded + loaded for the template. */
  private readonly REVIEWS_PAGE_SIZE = 10;
  /** Extra review pages fetched beyond the embedded first render. */
  private readonly loadedReviews = signal<ProductReview[]>([]);
  /** Offset for the next page fetch (starts past the embedded reviews). */
  private readonly reviewsOffset = signal(0);
  /** Total approved reviews on the server (from the list meta). */
  private readonly reviewsTotal = signal<number | null>(null);
  /** True while a "load more reviews" request is in flight. */
  readonly reviewsLoading = signal(false);
  /** Set when a reviews page fetch fails outright. */
  readonly reviewsError = signal(false);

  /** Embedded reviews from the PDP detail payload (first render). */
  private readonly embeddedReviews = computed<ProductReview[]>(
    () => this.product()?.recent_reviews ?? [],
  );

  /**
   * All reviews to render: the embedded first page plus any paged-in
   * extras, de-duplicated by id (a re-fetched first page can't double
   * up). Newest-first ordering is preserved by the server.
   */
  readonly reviews = computed<ProductReview[]>(() => {
    const seen = new Set<number>();
    const out: ProductReview[] = [];
    for (const r of [...this.embeddedReviews(), ...this.loadedReviews()]) {
      if (seen.has(r.id)) continue;
      seen.add(r.id);
      out.push(r);
    }
    return out;
  });

  /**
   * Whether there are more approved reviews on the server than we've
   * loaded so far, gates the "see all / load more" button. Uses the
   * review_count aggregate as the initial source of truth (before any
   * page fetch), then the list meta total once a page has loaded.
   */
  readonly hasMoreReviews = computed<boolean>(() => {
    const total = this.reviewsTotal() ?? this.product()?.review_count ?? 0;
    return this.reviews().length < total;
  });

  /**
   * Fetch the next page of approved reviews and append. Keyed by the
   * product's numeric v3 id (product().id) per the reviews route
   * contract. Idempotent-ish: guarded against concurrent loads.
   */
  loadMoreReviews(): void {
    const p = this.product();
    if (!p || this.reviewsLoading()) return;

    this.reviewsLoading.set(true);
    this.reviewsError.set(false);
    /* First "see all" picks up right after the reviews embedded in the
       PDP detail payload (offset = how many we already show); subsequent
       pages advance the stored cursor. */
    const offset =
      this.loadedReviews().length === 0 ? this.embeddedReviews().length : this.reviewsOffset();

    this.routed
      .get<PublicReview[]>('GET /products/:productId/reviews', {
        params: { productId: p.id },
        query: { limit: this.REVIEWS_PAGE_SIZE, offset },
      })
      .subscribe({
        next: (env) => {
          const rows = (env.data ?? []).map(mapPublicReview);
          this.loadedReviews.update((prev) => [...prev, ...rows]);
          this.reviewsOffset.set(offset + this.REVIEWS_PAGE_SIZE);
          const total = env.meta?.total;
          if (typeof total === 'number') this.reviewsTotal.set(total);
          this.reviewsLoading.set(false);
        },
        error: () => {
          this.reviewsError.set(true);
          this.reviewsLoading.set(false);
        },
      });
  }

  /* ----- Reviews: write (auth-gated submission) --------------------------
   * Signed-in shoppers can leave a review: a 1–5 star rating (required),
   * an optional title, and a comment. Submits to POST /products/:productId
   * /reviews (Bearer; the auth interceptor attaches the token). The review
   * lands status=pending, so on success we show a "submitted for
   * moderation" confirmation rather than optimistically injecting it into
   * the public list. Guests see a sign-in prompt instead of the form. */
  /** Whether the write-a-review form is expanded. */
  readonly reviewFormOpen = signal(false);
  /** Selected star rating in the form (0 = none yet). */
  readonly reviewStar = signal(0);
  /** Hovered star (for the interactive rating preview); 0 = none. */
  readonly reviewStarHover = signal(0);
  /** Title input (optional). */
  readonly reviewTitle = signal('');
  /** Comment input. */
  readonly reviewComment = signal('');
  /** True while the submit request is in flight. */
  readonly reviewSubmitting = signal(false);
  /** Set true once a review has been accepted (pending moderation). */
  readonly reviewSubmitted = signal(false);
  /** Inline validation / submit error key (null = none). */
  readonly reviewFormError = signal<string | null>(null);

  /** The star fill to show in the form control (hover wins over click). */
  reviewFormStarActive(position: number): boolean {
    const active = this.reviewStarHover() || this.reviewStar();
    return position <= active;
  }

  /** Open the form (no-op if already submitted). */
  openReviewForm(): void {
    if (this.reviewSubmitted()) return;
    this.reviewFormOpen.set(true);
  }

  /** Pick a star rating in the form. */
  setReviewStar(value: number): void {
    this.reviewStar.set(value);
    this.reviewFormError.set(null);
  }

  /** Hover preview for the star control. */
  hoverReviewStar(value: number): void {
    this.reviewStarHover.set(value);
  }

  /** Bind the title input. */
  onReviewTitleInput(event: Event): void {
    this.reviewTitle.set((event.target as HTMLInputElement).value);
  }

  /** Bind the comment textarea. */
  onReviewCommentInput(event: Event): void {
    this.reviewComment.set((event.target as HTMLTextAreaElement).value);
  }

  /**
   * Submit the review. Validates the star rating client-side first, then
   * POSTs. A 400/422 from the server surfaces inline; any other failure
   * shows a generic submit error. On success the form collapses into a
   * "submitted for moderation" confirmation.
   */
  submitReview(): void {
    const p = this.product();
    if (!p || this.reviewSubmitting()) return;

    const star = this.reviewStar();
    if (star < 1 || star > 5) {
      this.reviewFormError.set('product.reviews.form.errorStar');
      return;
    }

    this.reviewSubmitting.set(true);
    this.reviewFormError.set(null);

    this.routed
      .post<unknown>('POST /products/:productId/reviews', {
        params: { productId: p.id },
        body: {
          star,
          title: this.reviewTitle().trim() || null,
          comment: this.reviewComment().trim() || null,
        },
      })
      .subscribe({
        next: () => {
          this.reviewSubmitting.set(false);
          this.reviewSubmitted.set(true);
          this.reviewFormOpen.set(false);
        },
        error: (err: HttpErrorResponse) => {
          this.reviewSubmitting.set(false);
          this.reviewFormError.set(
            err.status === 400 || err.status === 422
              ? 'product.reviews.form.errorValidation'
              : 'product.reviews.form.errorSubmit',
          );
        },
      });
  }

  // ===== Request customization (P5) ==================================
  readonly customizationOpen = signal(false);
  readonly customizationDescription = signal('');
  readonly customizationIncludeMeasurements = signal(true);
  readonly customizationSubmitting = signal(false);
  readonly customizationSubmitted = signal(false);
  readonly customizationError = signal<string | null>(null);

  openCustomizationForm(): void {
    this.customizationOpen.set(true);
    this.customizationError.set(null);
  }

  closeCustomizationForm(): void {
    this.customizationOpen.set(false);
  }

  onCustomizationDescriptionInput(event: Event): void {
    this.customizationDescription.set((event.target as HTMLTextAreaElement).value);
  }

  onCustomizationMeasurementsToggle(event: Event): void {
    this.customizationIncludeMeasurements.set((event.target as HTMLInputElement).checked);
  }

  /**
   * Open a bespoke-customization request on this product. Optionally attaches
   * a snapshot of the shopper's saved default measurements so the vendor can
   * size the work. A 409 means they already have an in-flight request here.
   */
  async submitCustomization(): Promise<void> {
    const p = this.product();
    if (!p || this.customizationSubmitting()) return;

    const description = this.customizationDescription().trim();
    if (description.length < 3) {
      this.customizationError.set('product.customization.errorDescription');
      return;
    }

    this.customizationSubmitting.set(true);
    this.customizationError.set(null);

    let snapshot: Record<string, number> | null = null;
    if (this.customizationIncludeMeasurements()) {
      try {
        const saved = await this.measurements.getDefault();
        if (saved !== null) {
          const values: Record<string, number> = {};
          for (const f of this.measurementFields) {
            const v = saved.values[f];
            if (typeof v === 'number' && !Number.isNaN(v)) {
              values[f] = v;
            }
          }
          if (Object.keys(values).length > 0) {
            snapshot = values;
          }
        }
      } catch {
        /* Non-fatal: submit the request without measurements. */
      }
    }

    const body: {
      product_slug: string;
      description: string;
      measurement_snapshot?: Record<string, number>;
    } = { product_slug: p.slug, description };
    if (snapshot !== null) {
      body.measurement_snapshot = snapshot;
    }

    this.routed.post<unknown>('POST /me/customization-requests', { body }).subscribe({
      next: () => {
        this.customizationSubmitting.set(false);
        this.customizationSubmitted.set(true);
        this.customizationOpen.set(false);
      },
      error: (err: HttpErrorResponse) => {
        this.customizationSubmitting.set(false);
        this.customizationError.set(
          err.status === 409
            ? 'product.customization.errorDuplicate'
            : err.status === 400 || err.status === 422
              ? 'product.customization.errorValidation'
              : 'product.customization.errorSubmit',
        );
      },
    });
  }

  /** Computed page title, fed to SeoService AND displayed in <title>. */
  readonly pageTitle = computed(() => {
    const p = this.product();
    if (!p) return null;
    const vendor = p.vendor?.name;
    return vendor ? `${p.name} by ${vendor}` : p.name;
  });

  constructor() {
    /* Deep link: /product/x#reviews scrolls to the Reviews section once the
       product has rendered. Guarded, some test harnesses provide
       ActivatedRoute without a snapshot. */
    if (this.route.snapshot?.fragment === 'reviews') {
      this.pendingReviewsScroll = true;
    }

    /* Reset the page state whenever the product changes (navigation to a
       different slug, the component instance is reused by the router) so a
       previous product's selections, note, gallery position, open panels
       or a stale error never leak into the next one. Reads product() to
       track it; writes only unrelated signals, so there's no feedback
       loop. */
    effect(() => {
      this.product();
      this.selectedSize.set(null);
      this.selectedColor.set(null);
      this.extraMeasurement.set('');
      this.customMeasurement.set({});
      this.customPrefilled.set(false);
      this.quantity.set(1);
      this.addError.set(null);
      this.attemptedAdd.set(false);
      this.note.set('');
      this.noteExpanded.set(false);
      this.colorsExpanded.set(false);
      this.colorOverflow.set([]);
      this.pinnedColor.set(null);
      this.openSections.set(ProductDetailComponent.DEFAULT_SECTIONS);
      /* Gallery: back to the first image, lightbox closed. */
      this.activeImageIndex.set(0);
      this.lightboxOpen.set(false);
      this.pendingTrackReset = true;
      /* "Complete the look" per-card states belong to the old product. */
      this.ctlItemState.set({});
      this.ctlStatus.set('');
      /* Customization request state is per product. */
      this.customizationOpen.set(false);
      this.customizationDescription.set('');
      this.customizationIncludeMeasurements.set(true);
      this.customizationSubmitting.set(false);
      this.customizationSubmitted.set(false);
      this.customizationError.set(null);
      /* Reset the size-guide modal so a previous store's chart never leaks
         into the next product. */
      this.sizeGuideOpen.set(false);
      this.sizeGuideRows.set([]);
      this.sizeGuideError.set(false);
      this.sizeGuideLoadedFor = null;
      /* Reset the try-on modal so a previous product's photo / generated
         image / error never leaks into the next product. */
      if (this.tryOnPollHandle !== null) {
        clearTimeout(this.tryOnPollHandle);
        this.tryOnPollHandle = null;
      }
      this.tryOnOpen.set(false);
      this.tryOnPhoto.set(null);
      this.tryOnLoading.set(false);
      this.tryOnImageUrl.set(null);
      this.tryOnError.set(null);
      /* Reset the reviews read + write state so a previous product's
         loaded pages, pagination cursor, or in-flight submission never
         leak into the next product. */
      this.loadedReviews.set([]);
      this.reviewsOffset.set(0);
      this.reviewsTotal.set(null);
      this.reviewsLoading.set(false);
      this.reviewsError.set(false);
      this.reviewFormOpen.set(false);
      this.reviewStar.set(0);
      this.reviewStarHover.set(0);
      this.reviewTitle.set('');
      this.reviewComment.set('');
      this.reviewSubmitting.set(false);
      this.reviewSubmitted.set(false);
      this.reviewFormError.set(null);
    });

    /* Product-view signal for the Ain Personal Style Profile: beacon a
       `product_viewed` event once per loaded product (v3 id). Best-effort and
       de-duped per id so it never fires twice for the same product or blocks
       the page. Feeds the "For You" rails' personalisation. */
    effect(() => {
      const p = this.product();
      const id = p?.id;
      if (typeof id === 'number' && id > 0 && id !== this.lastViewedId) {
        this.lastViewedId = id;
        this.concierge.recordEvent('product_viewed', { product_id: id, surface: 'web' });
      }
    });

    /* Prefill the custom-size form from the saved account default the first
       time a signed-in shopper selects CUSTOM. Order-only: we never write
       back to their account default from here. */
    effect(() => {
      if (this.isCustomSize() && this.isAuthenticated() && !this.customPrefilled()) {
        this.customPrefilled.set(true);
        void this.prefillCustomMeasurement();
      }
    });

    /* Measure the site header once (browser only) and keep it current. */
    afterNextRender(() => this.observeHeader());

    /* After each render that changes the product or the colour order:
       (re)attach the observers to the current elements, re-measure the
       colour rows, snap a reused gallery track back to slide 1, and honour
       a pending #reviews deep link. Runs in the browser only. */
    afterRenderEffect({
      read: () => {
        this.product();
        this.orderedColors();
        untracked(() => this.afterProductRender());
      },
    });

    /* Whenever the active image changes (thumbnail, stage arrows, swipe,
       keyboard or the lightbox), keep its thumbnail in view inside the
       horizontally scrolling thumbnail row. Browser only. */
    afterRenderEffect(() => {
      const index = this.activeImageIndex();
      untracked(() => this.revealActiveThumb(index));
    });

    /* Apply SEO via effect() so it runs within Angular's CD cycle. */
    effect(() => {
      const p = this.product();
      if (!p) return;

      const siteUrl = environment.SITE_URL;
      const url = `${siteUrl}/product/${p.slug}`;

      /* Description summary: first paragraph, truncated, plain text. */
      const summary = (this.descriptionParagraphs()[0] ?? '')
        .slice(0, 160)
        .trim();
      const fallback = p.vendor?.name
        ? `${p.name} by ${p.vendor.name}. Premium modest wear from independent UAE designers on 3bayti.`
        : `${p.name}. Premium modest wear from independent UAE designers on 3bayti.`;

      this.seo.set({
        title: this.pageTitle() ?? p.name,
        description: summary || fallback,
        url,
        type: 'product',
        image: p.primary_image?.url ?? p.images?.[0]?.url,
      });

      /* ----- JSON-LD structured data --------------------------------
       *
       * Two schema.org graphs:
       *   1. Product, primary SEO win. Eligible for Google's product
       *      rich results (price, availability, star rating in SERPs).
       *   2. BreadcrumbList, eligible for the breadcrumb trail above
       *      the result snippet. Mirrors the visual breadcrumb in
       *      the template.
       *
       * Both go through SeoService.setStructuredData() which handles
       * the @context boilerplate and dedupes prior <script type=
       * "application/ld+json"> tags between navigations.
       */

      /* Build the image list. Schema.org accepts string or string[];
       * we pass an array when there's more than one image for richer
       * results, otherwise a single string. Use absolute URLs (the
       * API already returns CDN-absolute URLs so no concat needed). */
      const imageUrls = (p.images?.length ? p.images : [p.primary_image])
        .filter((i): i is NonNullable<typeof i> => !!i?.url)
        .map((i) => i.url);
      const image = imageUrls.length > 1 ? imageUrls : imageUrls[0] || '';

      /* Description for schema is the FULL plain-text description
       * (joined paragraphs), not the meta-description summary. Google
       * uses Product.description for the rich-result preview, where
       * more context is helpful. Falls back to the meta summary if
       * the product has no description. */
      const schemaDescription =
        this.descriptionParagraphs().join(' ').trim() || summary || fallback;

      /* Price for the Offer: the price the user actually pays. When
       * on sale, that's sale_price; otherwise it's price. Currency is
       * always taken from the same Money object to stay consistent. */
      const offerMoney = this.isOnSale() && p.sale_price ? p.sale_price : p.price;

      const schemaOpts: ProductSchemaOpts = {
        name: p.name,
        description: schemaDescription,
        url,
        image,
        price: offerMoney.amount,
        priceCurrency: offerMoney.currency,
        inStock: p.in_stock,
      };
      if (p.sku) schemaOpts.sku = p.sku;
      if (p.vendor?.name) schemaOpts.brand = p.vendor.name;

      /* aggregateRating shares its source (this.aggregateRating()) with
       * the visual rating block in the template. This is intentional:
       * Google's rich-results guidelines reject structured-data ratings
       * that aren't visible on the page. Single source = no drift. */
      const agg = this.aggregateRating();
      if (agg) schemaOpts.rating = agg;

      /* Map recent_reviews to the schema's review shape. Only fields
       * present in the API are forwarded; the helper drops empty
       * optional fields (title, body, date) so the resulting JSON is
       * minimal. */
      if (p.recent_reviews?.length) {
        schemaOpts.reviews = p.recent_reviews.map((r) => ({
          author: r.author,
          rating: r.rating,
          ...(r.body ? { body: r.body } : {}),
          ...(r.title ? { title: r.title } : {}),
          ...(r.created_at ? { date: r.created_at } : {}),
        }));
      }

      /* Breadcrumb mirrors the visual trail (Home › Categories ›
       * {category} › {product}). Skip the category step when
       * category_slug isn't known, never emit broken URLs in
       * structured data. The visual breadcrumb in the template uses
       * the same categoryLabel() helper, so the two stay in sync. */
      const crumbs = [
        { name: 'Home', url: `${siteUrl}/` },
        { name: 'Categories', url: `${siteUrl}/category` },
      ];
      const catLabel = this.categoryLabel();
      if (p.category_slug && catLabel) {
        crumbs.push({
          name: catLabel,
          url: `${siteUrl}/category/${p.category_slug}`,
        });
      }
      crumbs.push({ name: p.name, url });

      this.seo.setStructuredData([
        productSchema(schemaOpts),
        breadcrumbSchema(crumbs),
      ]);
    });
  }

  /**
   * Fetch product detail for the given slug.
   */
  private fetchProduct$(slug: string) {
    return this.routed.get<ProductDetail>('GET /products/:slug', { params: { slug } }).pipe(
      map((envelope) => envelope.data),
      tap(() => {
        this.notFound.set(false);
      }),
      catchError((err: HttpErrorResponse) => {
        if (err.status === 404) {
          this.notFound.set(true);
        } else {
          console.error(`[/product/${slug}] fetch failed:`, err.status);
        }
        return of(null as unknown as ProductDetail);
      }),
    );
  }

  /* ----- Post-render DOM work (browser only) ------------------------------ */

  private afterProductRender(): void {
    this.syncObservers();
    this.measureColors();
    if (this.pendingTrackReset) {
      const track = this.trackEl?.nativeElement;
      if (track) {
        track.scrollLeft = 0;
        /* A reused thumbnail row starts over at the first thumb too. */
        const thumbs = this.thumbsEl?.nativeElement;
        if (thumbs) thumbs.scrollLeft = 0;
        this.pendingTrackReset = false;
      }
    }
    if (this.pendingReviewsScroll && this.reviewsSectionEl) {
      this.pendingReviewsScroll = false;
      this.scrollToReviews();
    }
  }

  /** Attach the IntersectionObservers / ResizeObserver to the CURRENT elements. */
  private syncObservers(): void {
    if (typeof IntersectionObserver !== 'undefined') {
      const buyBox = this.buyBoxEl?.nativeElement ?? null;
      if (buyBox !== this.observedBuyBox) {
        /* Shrink the root by the sticky header so a buy box hidden under
           the header counts as scrolled away. */
        this.buyBoxObserver ??= new IntersectionObserver(
          (entries) => {
            for (const entry of entries) {
              const rootTop = entry.rootBounds?.top ?? 0;
              this.buyBoxOutOfView.set(
                !entry.isIntersecting && entry.boundingClientRect.bottom <= rootTop + 1,
              );
            }
          },
          { rootMargin: '-96px 0px 0px 0px' },
        );
        if (this.observedBuyBox) this.buyBoxObserver.unobserve(this.observedBuyBox);
        if (buyBox) this.buyBoxObserver.observe(buyBox);
        this.observedBuyBox = buyBox;
        if (!buyBox) this.buyBoxOutOfView.set(false);
      }

      const sentinel = this.ctaSentinel?.nativeElement ?? null;
      if (sentinel !== this.observedSentinel) {
        /* The negative bottom margin (~bar height) hides the bar just
           before it would cover the end-of-content sentinel / footer. */
        this.sentinelObserver ??= new IntersectionObserver(
          (entries) => {
            for (const entry of entries) this.nearPageEnd.set(entry.isIntersecting);
          },
          { rootMargin: '0px 0px -72px 0px' },
        );
        if (this.observedSentinel) this.sentinelObserver.unobserve(this.observedSentinel);
        if (sentinel) this.sentinelObserver.observe(sentinel);
        this.observedSentinel = sentinel;
      }
    }

    if (typeof ResizeObserver !== 'undefined') {
      const chips = this.colorChipsEl?.nativeElement ?? null;
      if (chips !== this.observedColorChips) {
        this.colorChipsObserver ??= new ResizeObserver(() => this.measureColors());
        if (this.observedColorChips) this.colorChipsObserver.unobserve(this.observedColorChips);
        if (chips) this.colorChipsObserver.observe(chips);
        this.observedColorChips = chips;
      }

      /* The active-thumb reveal otherwise runs only when the index changes:
         a row that was hidden (phone layout) or narrower when the image
         changed would keep the active thumb out of view after a rotate /
         resize. Instant (not smooth): it follows a layout change. */
      const thumbs = this.thumbsEl?.nativeElement ?? null;
      if (thumbs !== this.observedThumbs) {
        this.thumbsObserver ??= new ResizeObserver(() =>
          this.revealActiveThumb(this.activeImageIndex(), 'auto'),
        );
        if (this.observedThumbs) this.thumbsObserver.unobserve(this.observedThumbs);
        if (thumbs) this.thumbsObserver.observe(thumbs);
        this.observedThumbs = thumbs;
      }
    }
  }

  private observeHeader(): void {
    if (typeof document === 'undefined' || typeof ResizeObserver === 'undefined') return;
    const header = document.querySelector<HTMLElement>('.site-header');
    if (!header) return;
    const update = () => {
      const h = Math.round(header.getBoundingClientRect().height);
      if (h > 0 && h !== this.headerHeight()) this.headerHeight.set(h);
    };
    update();
    this.headerObserver = new ResizeObserver(update);
    this.headerObserver.observe(header);
  }

  /* ----- Gallery navigation ----------------------------------------------- */

  /** Thumbnail click: show that image. */
  selectImage(index: number): void {
    this.goToSlide(index);
  }

  /** Keyboard handler for thumbnail buttons (Enter/Space). */
  onThumbnailKeydown(event: KeyboardEvent, index: number): void {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      this.selectImage(index);
    }
  }

  /**
   * Previous / next arrows on the stage. At the first / last image the
   * arrow is aria-disabled but stays focusable, so its click is a no-op
   * (focus stays on it instead of dropping to <body>).
   */
  galleryPrev(): void {
    if (this.activeImageIndex() <= 0) return;
    this.goToSlide(this.activeImageIndex() - 1);
  }

  galleryNext(): void {
    if (this.activeImageIndex() >= this.slides().length - 1) return;
    this.goToSlide(this.activeImageIndex() + 1);
  }

  /**
   * Keep thumbnail `index` fully visible inside the horizontal thumbnail
   * row by scrolling the ROW itself (never scrollIntoView, which would also
   * scroll the page). The maths is physical: both rects are viewport-
   * relative and scrollLeft moves content the same way in LTR and RTL (in
   * RTL it runs from 0 towards negative values), so `scrollLeft + delta`
   * is direction-agnostic. The row's own padding (room for the focus ring)
   * is kept clear. No-op while the row is hidden (phones) or not laid out.
   * `behavior` defaults to smooth (unless reduced motion is preferred);
   * the resize observer passes 'auto' (instant).
   */
  private revealActiveThumb(index: number, behavior?: ScrollBehavior): void {
    const row = this.thumbsEl?.nativeElement;
    const item = row?.children.item(index) as HTMLElement | null | undefined;
    if (!row || !item) return;
    const rowRect = row.getBoundingClientRect();
    if (rowRect.width === 0) return;
    const style = typeof getComputedStyle === 'function' ? getComputedStyle(row) : null;
    const viewLeft = rowRect.left + (parseFloat(style?.paddingLeft ?? '') || 0);
    const viewRight = rowRect.right - (parseFloat(style?.paddingRight ?? '') || 0);
    const itemRect = item.getBoundingClientRect();
    let delta = 0;
    if (itemRect.left < viewLeft) delta = itemRect.left - viewLeft;
    else if (itemRect.right > viewRight) delta = itemRect.right - viewRight;
    if (Math.abs(delta) < 1) return;
    const left = row.scrollLeft + delta;
    behavior ??= prefersReducedMotion() ? 'auto' : 'smooth';
    if (typeof row.scrollTo === 'function') row.scrollTo({ left, behavior });
    else row.scrollLeft = left;
  }

  /**
   * Show slide `index` (clamped): updates the active index and scrolls the
   * track to it. With `focus`, moves keyboard focus to that slide's button.
   */
  goToSlide(index: number, opts: { focus?: boolean; smooth?: boolean } = {}): void {
    const n = this.slides().length;
    if (n === 0) return;
    const i = Math.max(0, Math.min(n - 1, index));
    this.activeImageIndex.set(i);
    this.scrollTrackTo(i, opts.smooth ?? true);
    if (opts.focus) {
      const buttons = this.trackEl?.nativeElement.querySelectorAll<HTMLElement>('.pdp-slide__btn');
      buttons?.[i]?.focus({ preventScroll: true });
    }
  }

  /**
   * Arrow keys on the gallery move between slides (visual order: in RTL
   * ArrowLeft is "next"); Home / End jump to the first / last image.
   */
  onGalleryKeydown(event: KeyboardEvent): void {
    const n = this.slides().length;
    if (n < 2) return;
    const current = this.activeImageIndex();
    let next: number | null = null;
    if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = n - 1;
    else if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
      const forward = (event.key === 'ArrowRight') !== this.isRtl(this.trackEl?.nativeElement);
      next = current + (forward ? 1 : -1);
    }
    if (next === null) return;
    event.preventDefault();
    this.goToSlide(next, { focus: true });
  }

  /** Track scrolled (swipe / trackpad / programmatic): sync the active index. */
  onTrackScroll(): void {
    if (typeof requestAnimationFrame === 'undefined') {
      this.syncIndexFromTrack();
      return;
    }
    if (this.trackRaf) return;
    this.trackRaf = requestAnimationFrame(() => {
      this.trackRaf = 0;
      this.syncIndexFromTrack();
    });
  }

  private syncIndexFromTrack(): void {
    const el = this.trackEl?.nativeElement;
    if (!el || el.clientWidth === 0) return;
    const n = this.slides().length;
    const idx = Math.max(0, Math.min(n - 1, Math.round(Math.abs(el.scrollLeft) / el.clientWidth)));
    if (this.trackTarget !== null) {
      /* Mid programmatic scroll: ignore the intermediate frames. */
      if (idx !== this.trackTarget) return;
      this.trackTarget = null;
    }
    if (idx !== this.activeImageIndex()) this.activeImageIndex.set(idx);
  }

  private scrollTrackTo(index: number, smooth: boolean): void {
    const el = this.trackEl?.nativeElement;
    if (!el) return;
    const left = (this.isRtl(el) ? -1 : 1) * index * el.clientWidth;
    this.trackTarget = index;
    if (this.trackTargetTimer !== null) clearTimeout(this.trackTargetTimer);
    this.trackTargetTimer = setTimeout(() => {
      this.trackTarget = null;
      this.trackTargetTimer = null;
    }, 800);
    const behavior: ScrollBehavior = smooth && !prefersReducedMotion() ? 'smooth' : 'auto';
    if (typeof el.scrollTo === 'function') el.scrollTo({ left, behavior });
    else el.scrollLeft = left;
  }

  private isRtl(el?: HTMLElement | null): boolean {
    if (!el || typeof getComputedStyle !== 'function') return false;
    return getComputedStyle(el).direction === 'rtl';
  }

  /* ----- Options + add to cart ------------------------------------------- */

  /** Select a size (ignored if that size is out of stock). */
  selectSize(size: ProductSize): void {
    if (!size.in_stock) return;
    this.selectedSize.set(this.selectedSize() === size.label ? null : size.label);
    this.addError.set(null);
  }

  /** Select a colour (ignored if that colour is out of stock). */
  selectColor(color: ProductColor): void {
    if (!color.in_stock) return;
    this.selectedColor.set(this.selectedColor() === color.label ? null : color.label);
    this.addError.set(null);
  }

  /** Quantity stepper (clamped to 1–99). */
  incrementQuantity(): void {
    this.quantity.update((q) => Math.min(q + 1, 99));
  }

  decrementQuantity(): void {
    this.quantity.update((q) => Math.max(q - 1, 1));
  }

  /**
   * Add the current selection to the cart. Shared by the main buy box and
   * the sticky bottom bar. When a required selection is missing nothing is
   * sent: the hints appear next to the options and the first missing one is
   * scrolled into view and focused. On success the cart drawer opens; on
   * failure an inline, actionable message is shown.
   */
  async addToCart(): Promise<void> {
    const p = this.product();
    if (!p || !p.in_stock || this.adding()) return;
    if (!this.selectionValid()) {
      this.attemptedAdd.set(true);
      this.revealFirstMissing();
      return;
    }
    if (!this.canAddToCart()) return;

    this.adding.set(true);
    this.addError.set(null);
    try {
      const custom = this.isCustomSize();
      await this.cart.addItem({
        product_id: p.id,
        quantity: this.quantity(),
        size: this.selectedSize(),
        color: this.selectedColor(),
        is_custom: custom,
        // CUSTOM size: snapshot the entered body measurements onto THIS order
        // (order-only, we never write back to the account default here).
        measurement: custom ? JSON.stringify(this.customMeasurementValues()) : null,
        extra_measurement: this.requiresExtraMeasurement() ? this.extraMeasurement().trim() : null,
        note: this.note().trim() || null,
      });
      this.attemptedAdd.set(false);
      this.cartDrawer.open();
    } catch {
      this.addError.set('product.addError');
    } finally {
      this.adding.set(false);
    }
  }

  /** Scroll to (and focus) the first option that still needs a selection. */
  private revealFirstMissing(): void {
    let group: HTMLElement | undefined;
    let focusTarget: HTMLElement | null = null;
    if (this.sizeMissing() && this.sizeGroupEl) {
      group = this.sizeGroupEl.nativeElement;
      focusTarget = group.querySelector<HTMLElement>('.pdp-chip:not(:disabled)');
    } else if (this.colorMissing() && this.colorGroupEl) {
      group = this.colorGroupEl.nativeElement;
      focusTarget = group.querySelector<HTMLElement>('.pdp-chip:not(:disabled):not(.is-hidden)');
    } else if (this.measurementMissing() && this.measurementGroupEl) {
      group = this.measurementGroupEl.nativeElement;
      focusTarget = group.querySelector<HTMLElement>('textarea');
    } else if (this.customMissing() && this.customGroupEl) {
      group = this.customGroupEl.nativeElement;
      focusTarget = group.querySelector<HTMLElement>('input, a[href]');
    }
    if (!group) return;
    if (typeof group.scrollIntoView === 'function') {
      group.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'center' });
    }
    focusTarget?.focus({ preventScroll: true });
  }

  /* ----- Complete the look (Ain) ------------------------------------------ */

  /** True while "Add the look" is looping cart adds. */
  readonly addingLook = signal(false);
  /** Quick-add state per complement id. */
  readonly ctlItemState = signal<Record<number, QuickAddState>>({});
  /** Polite live announcement for the strip's quick adds. */
  readonly ctlStatus = signal('');

  ctlState(id: number): QuickAddState | null {
    return this.ctlItemState()[id] ?? null;
  }

  private setCtlState(id: number, state: QuickAddState): void {
    this.ctlItemState.update((s) => ({ ...s, [id]: state }));
  }

  /**
   * Add every complement to the cart in one tap. Uses CartService.addItem
   * directly (works for guests + authed) — NOT the seed-product addToCart(),
   * which reads the seed's size/measurement state.
   */
  async addTheLook(): Promise<void> {
    const result = this.completeTheLook();
    const items = result.items;
    if (items.length === 0 || this.addingLook()) {
      return;
    }
    this.addingLook.set(true);
    try {
      for (const item of items) {
        try {
          await this.cart.addItem({ product_id: item.id, quantity: 1, size: null, color: null, is_custom: false });
          this.setCtlState(item.id, 'added');
          this.ctlService.recordEvent('complete_look_item_added', {
            ...(result.interactionId ? { interaction_id: result.interactionId } : {}),
            product_id: item.id,
          });
        } catch {
          // skip an item that can't be added; keep going
        }
      }
      this.ctlService.recordEvent('complete_look_added', {
        ...(result.interactionId ? { interaction_id: result.interactionId } : {}),
        count: items.length,
      });
      this.analytics.event('complete_look_added', { count: items.length });
      this.cartDrawer.open();
    } finally {
      this.addingLook.set(false);
    }
  }

  /**
   * Quick-add a single complement (same cart contract + beacon as one step
   * of "Add the look"). Announces the outcome politely and opens the drawer.
   */
  async quickAddComplement(item: CompleteLookItem): Promise<void> {
    if (!item.in_stock || this.ctlState(item.id) === 'adding') return;
    const result = this.completeTheLook();
    this.setCtlState(item.id, 'adding');
    try {
      await this.cart.addItem({ product_id: item.id, quantity: 1, size: null, color: null, is_custom: false });
      this.setCtlState(item.id, 'added');
      this.ctlStatus.set(this.i18n.instant('product.ctl.added', { name: item.name }));
      this.ctlService.recordEvent('complete_look_item_added', {
        ...(result.interactionId ? { interaction_id: result.interactionId } : {}),
        product_id: item.id,
      });
      this.analytics.event('complete_look_item_added', { product_id: item.id });
      this.cartDrawer.open();
    } catch {
      this.setCtlState(item.id, 'error');
      this.ctlStatus.set(this.i18n.instant('product.ctl.addFailed', { name: item.name }));
    }
  }

  /** Beacon a complement click (the card navigates itself). */
  onComplementClick(item: CompleteLookItem): void {
    const iid = this.completeTheLook().interactionId;
    this.ctlService.recordEvent('ai_product_clicked', {
      ...(iid ? { interaction_id: iid } : {}),
      product_id: item.id,
    });
  }

  /** Whether a complement is on the shopper's wishlist (heart state). */
  isComplementSaved(id: number): boolean {
    return this.wishlist.isSaved(id);
  }

  /**
   * Wishlist heart on a complement card (same behaviour as ProductCard's):
   * toggles for signed-in shoppers; guests are sent to sign in, returning
   * to this product afterwards.
   */
  toggleComplementSaved(item: CompleteLookItem): void {
    if (!this.isAuthenticated()) {
      void this.router.navigate(['/login'], { queryParams: { returnUrl: this.loginReturnUrl() } });
      return;
    }
    void this.wishlist.toggle(item).catch(() => undefined);
  }

  /** Effective price of a complement (sale price when lower). */
  complementPrice(item: CompleteLookItem): Money {
    return item.sale_price?.amount && item.sale_price.amount < item.price.amount
      ? item.sale_price
      : item.price;
  }

  /** Bind the extra-measurement textarea to its signal. */
  onExtraMeasurementInput(event: Event): void {
    this.extraMeasurement.set((event.target as HTMLTextAreaElement).value);
  }

  /** Bind the seller-note textarea to its signal. */
  onNoteInput(event: Event): void {
    this.note.set((event.target as HTMLTextAreaElement).value);
  }

  /** Bind a custom-size measurement input to its field in the signal map. */
  onCustomMeasurementInput(field: string, event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.customMeasurement.update((m) => ({ ...m, [field]: value }));
  }

  /** Parse the custom-measurement string map into a numeric values map. */
  private customMeasurementValues(): Record<string, number> {
    const m = this.customMeasurement();
    const out: Record<string, number> = {};
    for (const f of this.measurementFields) {
      const v = parseFloat(m[f] ?? '');
      if (Number.isFinite(v)) out[f] = v;
    }
    return out;
  }

  /** Prefill the custom-size form from the saved account default (if any). */
  private async prefillCustomMeasurement(): Promise<void> {
    try {
      const saved = await this.measurements.getDefault();
      if (saved === null) return;
      const next: Record<string, string> = {};
      for (const f of this.measurementFields) {
        const v = saved.values[f];
        next[f] = v !== undefined && v !== null ? String(v) : '';
      }
      this.customMeasurement.set(next);
    } catch {
      /* Non-fatal: the shopper can fill the form in manually. */
    }
  }

  /** Format Money (AED 530.00 → "AED 530"). */
  formatMoney(money: Money): string {
    const amount = Number(money.amount);
    const isInt = Number.isInteger(amount);
    const formatted = isInt
      ? amount.toLocaleString('en-AE')
      : amount.toLocaleString('en-AE', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        });
    return `${money.currency} ${formatted}`;
  }

  /** Letter for the image-fallback case (first code point, surrogate-safe). */
  initial(): string {
    return this.initialOf(this.product()?.name);
  }

  /** Same surrogate-safe first letter, for any name (e.g. Complete the Look items). */
  initialOf(name: string | null | undefined): string {
    return (Array.from((name ?? '').trim())[0] ?? '?').toUpperCase();
  }

  /**
   * Returns the fill ratio (0–1) for the star at the given 1-based
   * position, given the current aggregate rating. Lets the template
   * render half-filled stars when the rating isn't a whole number.
   *
   * Examples (rating = 4.6):
   *   pos 1 → 4.6 − 0 = 4.6, clamped → 1.0 (full)
   *   pos 5 → 4.6 − 4 = 0.6, clamped → 0.6 (60% filled)
   */
  starFillFor(position: number): number {
    const rating = this.aggregateRating()?.value ?? 0;
    return Math.max(0, Math.min(1, rating - (position - 1)));
  }

  /**
   * Grammatically correct review count text: "1 review" vs "5 reviews".
   * Saves the template from inline conditionals.
   */
  reviewCountLabel(count: number): string {
    return this.i18n.instant(count === 1 ? 'product.reviews.countOne' : 'product.reviews.countMany', { count });
  }

  /**
   * Formats a review's ISO `created_at` to a short, locale-aware date.
   * Returns null when the field is null/empty so the template can omit
   * the date entirely (some reviews in the dataset have no date).
   */
  formatReviewDate(iso: string | null | undefined): string | null {
    if (!iso) return null;
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return null;
    /* en-AE locale matches the rest of the site (en-AE for currency,
       ditto for dates). */
    return date.toLocaleDateString('en-AE', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  }

  /** Alt text for the active image. Falls back to product name. */
  activeImageAlt(): string {
    return this.activeImage()?.alt
      || this.product()?.name
      || this.i18n.instant('product.gallery.fallbackAlt');
  }

  /** URL for the category breadcrumb link. */
  categoryUrl(): string | null {
    const slug = this.product()?.category_slug;
    return slug ? `/category/${slug}` : null;
  }

  /**
   * Human-readable label for the category, derived from the slug when
   * the API doesn't return a friendlier name in the product payload.
   *
   * The catalogue's category slugs follow a 'name-id' convention
   * (e.g. 'abayas-1', 'mukhawars-2', 'kaftans-3'). We strip the
   * trailing numeric suffix, then capitalize. Hyphens within the
   * remaining name (rare but possible) are replaced with spaces.
   *
   * Used by both the visual breadcrumb and the BreadcrumbList JSON-LD
   * so the two stay in lockstep, Google rejects structured data
   * that doesn't match what's visible on the page.
   *
   * Returns null when there's no category_slug.
   */
  categoryLabel(): string | null {
    const slug = this.product()?.category_slug;
    if (!slug) return null;
    /* Strip a trailing '-<digits>' (e.g. 'abayas-1' → 'abayas'),
     * but ONLY if the number is at the very end. Slugs without a
     * numeric suffix pass through unchanged. */
    const withoutSuffix = slug.replace(/-\d+$/, '');
    /* Replace any remaining hyphens with spaces and capitalize. */
    return withoutSuffix
      .split('-')
      .map((word) => (word ? word[0].toUpperCase() + word.slice(1) : ''))
      .join(' ');
  }

  /** URL of the seller's storefront (/stores/:slug, slug only, never an id).
   *  Used by the "Sold by · Visit store" row above the title. */
  vendorUrl(): string | null {
    const slug = this.product()?.vendor?.slug;
    return slug ? `/stores/${slug}` : null;
  }

  /** Store-name initial for the "Sold by" row's avatar. Takes the first
   *  code point (Array.from), not the first UTF-16 unit, so a name starting
   *  with an emoji / astral character never renders half a surrogate pair. */
  vendorInitial(): string {
    const name = (this.product()?.vendor?.name ?? '').trim();
    return (Array.from(name)[0] ?? '?').toUpperCase();
  }
}
