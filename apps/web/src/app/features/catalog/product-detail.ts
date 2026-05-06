import {
  Component,
  ChangeDetectionStrategy,
  inject,
  PLATFORM_ID,
  TransferState,
  makeStateKey,
  computed,
  signal,
  effect,
} from '@angular/core';
import { isPlatformServer } from '@angular/common';
import { ActivatedRoute } from '@angular/router';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, switchMap, tap } from 'rxjs';

import { SeoService } from '../../core/seo/seo.service';
import { ApiConfigService } from '../../core/api/api-config.service';
import { createSetSsrStatus } from '../../core/ssr/response-status';
import {
  productSchema,
  breadcrumbSchema,
  type ProductSchemaOpts,
} from '../../core/seo/schema.helpers';
import { environment } from '../../../environments/environment';
import {
  ContainerComponent,
  HeadingComponent,
  TextComponent,
  StackComponent,
} from '../../shared/ui';
import type { Money, ProductDetail } from './product.model';
import { ProductCardComponent } from './product-card';

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
  imports: [
    ContainerComponent,
    HeadingComponent,
    TextComponent,
    StackComponent,
    ProductCardComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './product-detail.html',
  styleUrl: './product-detail.scss',
})
export class ProductDetailComponent {
  private route = inject(ActivatedRoute);
  private http = inject(HttpClient);
  private apiConfig = inject(ApiConfigService);
  private seo = inject(SeoService);
  private state = inject(TransferState);
  private platformId = inject(PLATFORM_ID);

  /**
   * Sets the SSR HTTP response status. Called when the API returns 404
   * for the product slug — without this the response would be HTTP 200
   * with a "not found" page, which crawlers index as real content.
   * No-op on the browser. (W2.2c)
   */
  private setSsrStatus = createSetSsrStatus();

  /**
   * True if the API returned 404 for this slug.
   *
   * NOTE on field order: this MUST be declared BEFORE `product`
   * below. TypeScript class fields initialize top-to-bottom, and
   * `product`'s `toSignal()` subscribes synchronously on creation;
   * its inner `switchMap` may then call `fetchProduct$()` which
   * touches `this.notFound.set(...)`. If `notFound` were declared
   * after `product`, that .set call would throw at construction time
   * with `Cannot read properties of undefined (reading 'set')` —
   * surfaced specifically when the client hydrates with a populated
   * TransferState (the cache-hit path is synchronous, so the .set
   * fires before the rest of the class fields are initialized).
   *
   * SSR prerender doesn't hit this because the cache-miss path is
   * async (HTTP latency) — by the time tap()/catchError() runs,
   * `notFound` exists.
   *
   * (`loading` below references `notFound` too, but that's safe
   * because `computed()` is lazy — its body doesn't run at
   * declaration time, only on first read.)
   */
  readonly notFound = signal(false);

  /**
   * Product detail fetched from /v2/products/:slug.
   * Embeds in TransferState for SSR-to-client hand-off.
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

  /** Currently-displayed image (clicking thumbnails switches it). */
  readonly activeImageIndex = signal(0);

  readonly activeImage = computed(() => {
    const p = this.product();
    if (!p) return null;
    const images = p.images ?? [];
    const fallback = p.primary_image;
    return images[this.activeImageIndex()] ?? fallback;
  });

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
   * Fetch product detail with TransferState caching.
   */
  private fetchProduct$(slug: string) {
    const stateKey = makeStateKey<ProductDetail>(`product-detail-${slug}`);

    const cached = this.state.get(stateKey, null);
    if (cached !== null) {
      this.notFound.set(false);
      return of(cached);
    }

    const url = `${this.apiConfig.v2BaseUrl}/products/${slug}`;
    return this.http.get<{ data: ProductDetail }>(url).pipe(
      map((envelope) => envelope.data),
      tap((product) => {
        if (isPlatformServer(this.platformId)) {
          this.state.set(stateKey, product);
        }
        this.notFound.set(false);
      }),
      catchError((err: HttpErrorResponse) => {
        if (err.status === 404) {
          this.notFound.set(true);
          /* W2.2c: propagate 404 to the HTTP response so search engines
           * see a real 404 instead of indexing this URL as a 200 page. */
          this.setSsrStatus(404);
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
    return count === 1 ? '1 review' : `${count} reviews`;
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
      || 'Product image';
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
    return slug ? `/designer/${slug}` : null;
  }
}
