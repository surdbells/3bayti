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
import { breadcrumbSchema, itemListSchema } from '../../core/seo/schema.helpers';
import { ApiConfigService } from '../../core/api/api-config.service';
import { createSetSsrStatus } from '../../core/ssr/response-status';
import { environment } from '../../../environments/environment';
import {
  ContainerComponent,
  HeadingComponent,
  TextComponent,
  StackComponent,
} from '../../shared/ui';
import { ProductCardComponent } from '../catalog/product-card';
import type { CategoryDetail, CategoryDetailMeta } from './category.model';

/**
 * Category detail page — `/category/:slug`.
 *
 * Server-rendered landing page for each category (Abayas, Kaftans, etc.).
 * Pulls category metadata + first 20 products from a single API call to
 * `/v2/categories/:slug` and embeds the full result in the prerendered
 * HTML via TransferState. Crawlers see all 20 products as <a> tags
 * pointing to /product/:slug — exactly the SEO surface this site exists
 * to provide.
 *
 * Why a single API call instead of two:
 *   The /v2/categories/:slug endpoint returns metadata + an embedded
 *   products array (page 1, 20 items). Older approaches would fetch
 *   metadata then separately fetch /products?category=:slug — two
 *   round trips. The combined endpoint cuts that in half.
 *
 * Why TransferState:
 *   Same rationale as /category index. SSR fetches the data, embeds the
 *   JSON in <script id="ng-state">, browser hydration finds the cache
 *   and skips the re-fetch. Network: 1 request server-side, 0 browser-
 *   side after hydration.
 *
 * Image handling on product cards:
 *   ProductCardComponent (W2.1prep) handles missing/broken image URLs
 *   with a letter-fallback. So even if some products in this category
 *   have null primary_image, the grid renders cleanly.
 *
 * Pagination:
 *   This page renders the FIRST page only (20 products). Pagination
 *   beyond page 1 is deferred to W2.1b. Most users never paginate
 *   anyway, and SEO-wise page 1 is the canonical URL search engines
 *   should index. Subsequent pages use `?page=2` query strings which
 *   aren't prerenderable without listing every page slug — defer.
 */
@Component({
  selector: 'app-category-detail',
  standalone: true,
  imports: [
    ContainerComponent,
    HeadingComponent,
    TextComponent,
    StackComponent,
    ProductCardComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './category-detail.html',
  styleUrl: './category-detail.scss',
})
export class CategoryDetailComponent {
  private route = inject(ActivatedRoute);
  private http = inject(HttpClient);
  private apiConfig = inject(ApiConfigService);
  private seo = inject(SeoService);
  private state = inject(TransferState);
  private platformId = inject(PLATFORM_ID);

  /**
   * Sets the SSR HTTP response status. Called when the API returns 404
   * for the category slug — without this the response would be HTTP 200
   * with a "not found" page, which crawlers index as real content.
   * No-op on the browser. (W2.2c)
   */
  private setSsrStatus = createSetSsrStatus();

  /** Current slug from the route. Drives the API call. */
  readonly slug = toSignal(
    this.route.paramMap.pipe(map((params) => params.get('slug') ?? '')),
    { initialValue: '' },
  );

  /**
   * True when the category was not found (404 from the API).
   *
   * NOTE on field order: this MUST be declared BEFORE `response`
   * below. TypeScript class fields initialize top-to-bottom, and
   * `response`'s `toSignal()` subscribes synchronously on creation;
   * its inner `switchMap` may then call `fetchCategoryDetail$()` which
   * touches `this.notFound.set(...)`. If `notFound` were declared
   * after `response`, that .set call would throw at construction time
   * with `Cannot read properties of undefined (reading 'set')` —
   * surfaced specifically when the client hydrates with a populated
   * TransferState (the cache-hit path is synchronous, so the .set
   * fires before the rest of the class fields are initialized).
   *
   * SSR prerender doesn't hit this because the cache-miss path is
   * async (HTTP latency) — by the time tap()/catchError() runs,
   * `notFound` exists.
   */
  readonly notFound = signal(false);

  /**
   * Category detail + embedded products, fetched once per slug.
   *
   * The fetch result includes both `data` (CategoryDetail) and `meta`
   * (CategoryDetailMeta), since we need total_products for the page
   * header. We store the whole envelope in TransferState so hydration
   * doesn't lose the meta.
   */
  readonly response = toSignal(
    this.route.paramMap.pipe(
      switchMap((params) => {
        const slug = params.get('slug') ?? '';
        if (!slug) {
          return of(null);
        }
        return this.fetchCategoryDetail$(slug);
      }),
    ),
    { initialValue: null as CategoryDetailEnvelope | null },
  );

  /** Just the category metadata. */
  readonly category = computed<CategoryDetail | null>(() => this.response()?.data ?? null);

  /** Just the meta (total_products, page_size). */
  readonly meta = computed<CategoryDetailMeta | null>(() => this.response()?.meta ?? null);

  /** Just the products list — what the grid iterates over. */
  readonly products = computed(() => this.response()?.data?.products ?? []);

  /** Loading state — true between route change and data arrival. */
  readonly loading = computed(() => this.response() === null);

  constructor() {
    /* SEO + JSON-LD: re-apply whenever the response signal changes.
       Using effect() instead of queueMicrotask because Angular SSR
       runs change detection synchronously during prerender — micro-
       tasks scheduled outside the CD cycle don't get to run before
       HTML capture. effect() integrates with the signal graph and
       fires within the CD cycle, ensuring SEO tags ARE present in
       the prerendered HTML. */
    effect(() => {
      const cat = this.category();
      if (!cat) return;

      const siteUrl = environment.SITE_URL;
      const url = `${siteUrl}/category/${cat.slug}`;
      const total = this.meta()?.total_products ?? cat.product_count;

      /* Build a description that reads naturally regardless of the
         category being well-stocked, sparse, or empty. */
      const description = total === 0
        ? `Browse ${cat.name.toLowerCase()} from independent UAE designers on 3bayti — ` +
          `more pieces coming soon.`
        : total === 1
          ? `One hand-picked ${cat.name.toLowerCase().replace(/s$/, '')} from an independent ` +
            `UAE designer on 3bayti.`
          : `Shop ${total} hand-picked ${cat.name.toLowerCase()} from independent UAE designers. ` +
            `Curated styles, made-to-measure fits, delivered to your door.`;

      this.seo.set({
        title: `${cat.name} · Modest Wear & Designer Pieces`,
        description,
        url,
        type: 'website',
      });

      /* JSON-LD: BreadcrumbList + ItemList for SERP rich results.
         The ItemList helps Google understand this page lists
         products that link out to individual product pages. */
      this.seo.setStructuredData([
        breadcrumbSchema([
          { name: 'Home', url: `${siteUrl}/` },
          { name: 'Categories', url: `${siteUrl}/category` },
          { name: cat.name, url },
        ]),
        itemListSchema(
          this.products().map((p, idx) => ({
            position: idx + 1,
            name: p.name,
            url: `${siteUrl}/product/${p.slug}`,
            image: p.primary_image?.url,
          })),
        ),
      ]);
    });
  }

  /**
   * Fetch (or hydrate from TransferState) the category detail envelope.
   *
   * Cache key includes the slug so each category gets its own state
   * entry — switching categories client-side picks up the right cache.
   */
  private fetchCategoryDetail$(slug: string) {
    const stateKey = makeStateKey<CategoryDetailEnvelope>(`category-detail-${slug}`);

    /* Browser-side: check if SSR seeded the cache. */
    const cached = this.state.get(stateKey, null);
    if (cached !== null) {
      /* Re-apply SEO since the cached path skips the constructor's
         queueMicrotask in a freshly-loaded route. */
      this.notFound.set(false);
      return of(cached);
    }

    const url = `${this.apiConfig.v2BaseUrl}/categories/${slug}`;
    return this.http.get<CategoryDetailEnvelope>(url).pipe(
      tap((envelope) => {
        if (isPlatformServer(this.platformId)) {
          this.state.set(stateKey, envelope);
        }
        this.notFound.set(false);
      }),
      catchError((err: HttpErrorResponse) => {
        /* 404 from API → set notFound flag, return null so loading clears. */
        if (err.status === 404) {
          this.notFound.set(true);
          /* W2.2c: propagate 404 to the HTTP response so crawlers see
           * a real 404 instead of indexing this slug as a 200 page. */
          this.setSsrStatus(404);
        } else {
          console.error(`[/category/${slug}] fetch failed:`, err.status);
        }
        return of(null as unknown as CategoryDetailEnvelope);
      }),
    );
  }

  /**
   * URL for the back-to-categories link in the breadcrumb. Used in
   * the template.
   */
  categoriesIndexUrl(): string {
    return '/category';
  }
}

/** Local type for the full envelope (data + meta) returned by /v2/categories/:slug. */
interface CategoryDetailEnvelope {
  data: CategoryDetail;
  meta: CategoryDetailMeta;
}
