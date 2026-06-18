import {
  Component,
  ChangeDetectionStrategy,
  inject,
  computed,
  signal,
  effect,
  ViewChild,
  ElementRef,
  AfterViewChecked,
  OnDestroy,
} from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { toSignal } from '@angular/core/rxjs-interop';
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
import type { Money, Product, ProductDetail, ProductSize, ProductColor } from './product.model';
import { RecommendationsService } from './recommendations.service';
import { CartService } from '../../core/cart/cart.service';
import { CartDrawerService } from '../../core/cart/cart-drawer.service';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';

/**
 * Product detail page (PDP) — `/product/:slug`.
 *
 * THIS IS THE SEO TARGET PAGE. Every product gets its own server-
 * rendered HTML page with full content visible to crawlers without
 * JavaScript. Bottom-of-funnel — these are the pages that need to
 * rank for "{vendor} {product name}", "{category} from {vendor}",
 * and brand-name + product-name search queries.
 *
 * Prerender strategy:
 *   /v2/products?limit=200&sort=newest at build time → prerender 200
 *   most recent products. The remaining ~1,457 products work via
 *   runtime SSR — slower first paint but still fully indexable.
 *   Build time stays under 5 minutes.
 *   See app.routes.server.ts for the cap.
 *
 * What's in W2.2a:
 *   - Image gallery (primary + thumbnails)
 *   - Title, vendor, price (with sale strike-through)
 *   - Sizes + colors display
 *   - Description (HTML stripped to plain text — no XSS risk)
 *   - Stock state
 *   - Visual breadcrumb (Home › Categories › {category} › {product})
 *   - Basic SEO meta (title, description, canonical, OG)
 *
 * What's in W2.2b:
 *   - Aggregate rating widget (5 stars + count, fractional support)
 *   - Reviews section (per-review stars, verified buyer badge,
 *     graceful date handling)
 *   - schema.org Product JSON-LD (price, availability, brand,
 *     aggregateRating, review[]) — eligible for Google rich results
 *   - BreadcrumbList JSON-LD mirroring the visual breadcrumb
 *   - Related products grid ("You may also like") — bottom of page
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
    ShareButtonsComponent,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './product-detail.html',
  styleUrl: './product-detail.scss',
})
export class ProductDetailComponent implements AfterViewChecked, OnDestroy {
  private route = inject(ActivatedRoute);
  private routed = inject(RoutedHttpClient);
  private seo = inject(SeoService);
  private recsService = inject(RecommendationsService);
  private cart = inject(CartService);
  private cartDrawer = inject(CartDrawerService);
  private i18n = inject(TranslateService);

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
   * The "you may also like" strip prefers these engine results and only
   * falls back to the product's own `related_products` (from the single
   * PDP fetch) when the engine returns nothing — so the section always
   * has the best data available and never regresses below the old
   * behaviour.
   */
  readonly recommendations = toSignal(
    this.route.paramMap.pipe(
      switchMap((params) => {
        const slug = params.get('slug') ?? '';
        if (slug === '') {
          return of([] as Product[]);
        }
        return from(this.recsService.forProduct(slug)).pipe(
          map((recs) => recs.map((r) => r.product)),
          catchError(() => of([] as Product[])),
        );
      }),
    ),
    { initialValue: [] as Product[] },
  );

  /**
   * The products to show in the "you may also like" grid: engine
   * recommendations when present, otherwise the PDP's related_products,
   * otherwise empty (section hidden). Capped at 10 — the grid is a
   * wrapping 5×2 layout (decision #5), 2 per row on mobile.
   */
  readonly relatedProducts = computed<Product[]>(() => {
    const engine = this.recommendations();
    const list = engine.length > 0 ? engine : (this.product()?.related_products ?? []);
    return list.slice(0, 10);
  });

  /**
   * Absolute canonical URL for this product, used by the share buttons.
   * Mirrors the SEO canonical (SITE_URL + /product/:slug).
   */
  readonly shareUrl = computed<string>(() => {
    const slug = this.product()?.slug;
    return slug ? `${environment.SITE_URL}/product/${slug}` : environment.SITE_URL;
  });

  /** Currently-displayed image (clicking thumbnails switches it). */
  readonly activeImageIndex = signal(0);

  readonly activeImage = computed(() => {
    const p = this.product();
    if (!p) return null;
    const images = p.images ?? [];
    const fallback = p.primary_image;
    return images[this.activeImageIndex()] ?? fallback;
  });

  /* ----- Gallery lightbox (PDP3) -----------------------------------------
   * Click-to-zoom fullscreen viewer. Focus moves to the close button on
   * open and is restored to the trigger on close; Tab is trapped within
   * the dialog; Esc closes; Left/Right cycle images. CSR-only. */
  readonly lightboxOpen = signal(false);
  private lightboxTrigger: HTMLElement | null = null;

  @ViewChild('lightbox') private lightboxEl?: ElementRef<HTMLElement>;
  @ViewChild('lightboxClose') private lightboxCloseBtn?: ElementRef<HTMLButtonElement>;

  /** Number of gallery images (used to bound prev/next + show controls). */
  readonly imageCount = computed(() => this.product()?.images?.length ?? 0);

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
    this.lightboxTrigger?.focus();
    this.lightboxTrigger = null;
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

  /* ----- Content tabs (PDP #10e) -----------------------------------------
   * Description / Reviews / Details are presented as a premium tab strip
   * (single visible panel) rather than stacked accordions. Reviews is
   * always present; Description and Details only when they have content,
   * so the tab strip is built dynamically. `activeContentTab` holds the
   * user's explicit choice (null = none yet); `effectiveContentTab` falls
   * back to the first available tab, so the default is Description when
   * present, otherwise Reviews. */
  readonly activeContentTab = signal<'description' | 'reviews' | 'details' | null>(null);

  readonly hasDescriptionContent = computed<boolean>(() => this.descriptionParagraphs().length > 0);
  readonly hasDetailsContent = computed<boolean>(() => {
    const p = this.product();
    return !!(p?.fabric || (p?.materials?.length ?? 0) > 0 || p?.care_instructions);
  });

  /** Tabs to render, in display order (Reviews always present). */
  readonly contentTabs = computed<Array<'description' | 'reviews' | 'details'>>(() => {
    const tabs: Array<'description' | 'reviews' | 'details'> = [];
    if (this.hasDescriptionContent()) tabs.push('description');
    tabs.push('reviews');
    if (this.hasDetailsContent()) tabs.push('details');
    return tabs;
  });

  /** The tab actually shown: the explicit selection if still valid, else the first. */
  readonly effectiveContentTab = computed<'description' | 'reviews' | 'details'>(() => {
    const sel = this.activeContentTab();
    const tabs = this.contentTabs();
    return sel && tabs.includes(sel) ? sel : tabs[0];
  });

  selectContentTab(tab: 'description' | 'reviews' | 'details'): void {
    this.activeContentTab.set(tab);
  }

  /** Left/Right arrows move between content tabs (WAI-ARIA tabs). */
  onContentTabKeydown(event: KeyboardEvent): void {
    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
    event.preventDefault();
    const tabs = this.contentTabs();
    const cur = tabs.indexOf(this.effectiveContentTab());
    const next =
      event.key === 'ArrowRight'
        ? (cur + 1) % tabs.length
        : (cur - 1 + tabs.length) % tabs.length;
    this.activeContentTab.set(tabs[next]);
  }

  /* ----- Mobile sticky CTA visibility (follow-up) ------------------------
   * The fixed bottom add-to-cart bar (mobile only) hides once the shopper
   * reaches the end of the content, so it never sits over the site footer.
   * A sentinel at the end of the page is observed; the negative bottom root
   * margin (~bar height) flips the bar off just before it would cover the
   * sentinel. CSR-only — IntersectionObserver is feature-guarded. */
  readonly stickyCtaHidden = signal(false);
  @ViewChild('ctaSentinel') private ctaSentinel?: ElementRef<HTMLElement>;
  private ctaObserver?: IntersectionObserver;

  ngAfterViewChecked(): void {
    if (this.ctaObserver || typeof IntersectionObserver === 'undefined') return;
    const el = this.ctaSentinel?.nativeElement;
    if (!el) return;
    this.ctaObserver = new IntersectionObserver(
      entries => {
        for (const entry of entries) this.stickyCtaHidden.set(entry.isIntersecting);
      },
      { rootMargin: '0px 0px -72px 0px' },
    );
    this.ctaObserver.observe(el);
  }

  ngOnDestroy(): void {
    this.ctaObserver?.disconnect();
  }

  /* ----- Buy box: variant selection + quantity + add-to-cart -------- */

  /** Selected size label (null until the shopper picks one). */
  readonly selectedSize = signal<string | null>(null);
  /** Selected colour label (null until the shopper picks one). */
  readonly selectedColor = signal<string | null>(null);
  /** Quantity to add (1–99). */
  readonly quantity = signal(1);
  /** True while an add-to-cart request is in flight. */
  readonly adding = signal(false);
  /** Surfaced when an add-to-cart attempt fails; cleared on the next try. */
  readonly addError = signal<string | null>(null);

  /** Whether this product offers a size / colour axis at all. */
  readonly hasSizes = computed(() => (this.product()?.sizes?.length ?? 0) > 0);
  readonly hasColors = computed(() => (this.product()?.colors?.length ?? 0) > 0);

  /**
   * True when every required variant axis has an in-stock selection.
   * Validates against the CURRENT product's options (not just "non-null")
   * so a stale selection carried across navigation can't pass.
   */
  readonly selectionValid = computed(() => {
    const p = this.product();
    if (!p) return false;
    const sizes = p.sizes ?? [];
    if (sizes.length > 0) {
      const s = this.selectedSize();
      if (!s || !sizes.some((x) => x.label === s && x.in_stock)) return false;
    }
    const colors = p.colors ?? [];
    if (colors.length > 0) {
      const c = this.selectedColor();
      if (!c || !colors.some((x) => x.label === c && x.in_stock)) return false;
    }
    return true;
  });

  /** The add-to-cart button is enabled only when all conditions hold. */
  readonly canAddToCart = computed(
    () => !!this.product()?.in_stock && this.selectionValid() && !this.adding(),
  );

  /**
   * Description as plain text. Source data contains HTML (<div>, <span>,
   * etc.) which we strip server-side-equivalent — no innerHTML, no XSS
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
   * to show. Used by both the visible star block and (W2.2b Phase 2)
   * the schema.org JSON-LD AggregateRating — both must reflect the
   * same numbers, which is why this is a single source of truth.
   *
   * Returns null when:
   *   - product hasn't loaded yet
   *   - rating is null/undefined (no rating data at all)
   *   - review_count is 0 or missing (no reviews → showing a 0-star
   *     widget would be visually misleading)
   *
   * Google's structured-data guidelines also reject AggregateRating
   * without a positive reviewCount, so this null guard protects both
   * surfaces simultaneously.
   */
  readonly aggregateRating = computed<{ value: number; count: number } | null>(() => {
    const p = this.product();
    if (!p) return null;
    const value = p.rating;
    const count = p.review_count ?? 0;
    if (value == null || count <= 0) return null;
    return { value, count };
  });

  /**
   * Static array used by the template to render star icons. Five
   * positions; the template fills/empties each based on whether the
   * average rating reaches that position. Sourced once here so the
   * template stays declarative.
   */
  readonly starPositions = [1, 2, 3, 4, 5] as const;

  /** Computed page title — fed to SeoService AND displayed in <title>. */
  readonly pageTitle = computed(() => {
    const p = this.product();
    if (!p) return null;
    const vendor = p.vendor?.name;
    return vendor ? `${p.name} by ${vendor}` : p.name;
  });

  constructor() {
    /* Deep link: /product/x#reviews opens directly on the Reviews tab.
       Guarded — some test harnesses provide ActivatedRoute without a
       snapshot. */
    if (this.route.snapshot?.fragment === 'reviews') {
      this.activeContentTab.set('reviews');
    }

    /* Reset the buy box whenever the product changes (navigation to a
       different slug) so a previous product's size/colour/quantity or a
       stale error never leaks into the next one. Reads product() to
       track it; writes only unrelated signals, so there's no feedback
       loop. */
    effect(() => {
      this.product();
      this.selectedSize.set(null);
      this.selectedColor.set(null);
      this.quantity.set(1);
      this.addError.set(null);
    });

    /* Apply SEO via effect() so it runs within Angular's CD cycle.
       During SSR prerender, this ensures meta tags are present in the
       captured HTML. (Microtask-based scheduling does NOT work for
       prerender — see CategoryDetailComponent commit history for
       background.) */
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
       *   1. Product — primary SEO win. Eligible for Google's product
       *      rich results (price, availability, star rating in SERPs).
       *   2. BreadcrumbList — eligible for the breadcrumb trail above
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
       * category_slug isn't known — never emit broken URLs in
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

  /** Click handler for thumbnail switching. */
  selectImage(index: number): void {
    this.activeImageIndex.set(index);
  }

  /** Keyboard handler for thumbnail buttons (Enter/Space). */
  onThumbnailKeydown(event: KeyboardEvent, index: number): void {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      this.selectImage(index);
    }
  }

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
   * Add the current selection to the cart. No-ops unless the product is
   * in stock and every required variant axis has a valid selection (the
   * button is disabled in that state too — this is the belt-and-braces
   * guard). On success the cart drawer opens; on failure an inline,
   * actionable message is shown.
   */
  async addToCart(): Promise<void> {
    const p = this.product();
    if (!p || !this.canAddToCart()) return;

    this.adding.set(true);
    this.addError.set(null);
    try {
      await this.cart.addItem({
        product_id: p.id,
        quantity: this.quantity(),
        size: this.selectedSize(),
        color: this.selectedColor(),
      });
      this.cartDrawer.open();
    } catch {
      this.addError.set('product.addError');
    } finally {
      this.adding.set(false);
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

  /** Letter for the image-fallback case. */
  initial(): string {
    return (this.product()?.name?.[0] ?? '?').toUpperCase();
  }

  /**
   * Returns the fill ratio (0–1) for the star at the given 1-based
   * position, given the current aggregate rating. Lets the template
   * render half-filled stars when the rating isn't a whole number.
   *
   * Examples (rating = 4.6):
   *   pos 1 → 4.6 − 0 = 4.6, clamped → 1.0 (full)
   *   pos 4 → 4.6 − 3 = 1.6, clamped → 1.0 (full)
   *   pos 5 → 4.6 − 4 = 0.6, clamped → 0.6 (60% filled)
   *   pos 5 with rating = 3 → 3 − 4 = −1, clamped → 0.0 (empty)
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
   * so the two stay in lockstep — Google rejects structured data
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

  /** URL for the vendor breadcrumb link. */
  vendorUrl(): string | null {
    const slug = this.product()?.vendor?.slug;
    return slug ? `/stores/${slug}` : null;
  }
}
