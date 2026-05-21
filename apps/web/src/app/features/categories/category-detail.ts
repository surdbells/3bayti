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
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, switchMap, tap } from 'rxjs';

import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema, itemListSchema } from '../../core/seo/schema.helpers';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { createSetSsrStatus } from '../../core/ssr/response-status';
import { environment } from '../../../environments/environment';
import {
  ContainerComponent,
  HeadingComponent,
  TextComponent,
  StackComponent,
} from '../../shared/ui';
import type { CategoryDetail, CategoryDetailMeta } from './category.model';
import { CatalogService, type CatalogFilters, type CatalogSort } from './catalog.service';
import { FacetFiltersComponent } from './facet-filters.component';
import { ProductCardComponent } from '../catalog/product-card';

/**
 * Category detail page — `/category/:slug`.
 *
 * Now serves two roles:
 *  1. SSR: fetches category metadata + embedded 20 products from
 *     `/v3/categories/:slug` for SEO (unchanged — crawlers still see
 *     a rich, pre-rendered product grid).
 *  2. Browser: drives a filterable, paginated grid via CatalogService
 *     (GET /v3/products + GET /v3/products/facets). Filter state is
 *     synced to / read from URL query params so filters are shareable.
 *
 * The filter sidebar is visible only browser-side (facets require a
 * live products call; they're not embedded in the SSR metadata).
 * On first hydration the grid shows the SSR products; filters layer
 * on top asynchronously when the browser loads.
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
    FacetFiltersComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './category-detail.html',
  styleUrl: './category-detail.scss',
})
export class CategoryDetailComponent {
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private routed = inject(RoutedHttpClient);
  private seo = inject(SeoService);
  private state = inject(TransferState);
  private platformId = inject(PLATFORM_ID);
  private catalog = inject(CatalogService);
  private setSsrStatus = createSetSsrStatus();

  // ── SSR metadata ──────────────────────────────────────────────────
  // (unchanged from original; keeps SEO intact)

  /** Current slug from the route. */
  readonly slug = toSignal(
    this.route.paramMap.pipe(map((params) => params.get('slug') ?? '')),
    { initialValue: '' },
  );

  readonly notFound = signal(false);

  readonly response = toSignal(
    this.route.paramMap.pipe(
      switchMap((params) => {
        const slug = params.get('slug') ?? '';
        if (!slug) return of(null);
        return this.fetchCategoryDetail$(slug);
      }),
    ),
    { initialValue: null as CategoryDetailEnvelope | null },
  );

  readonly category  = computed<CategoryDetail | null>(() => this.response()?.data ?? null);
  readonly meta      = computed<CategoryDetailMeta | null>(() => this.response()?.meta ?? null);
  readonly loading   = computed(() => this.response() === null);

  // ── Filter state (URL-driven) ─────────────────────────────────────

  /**
   * Active filters, derived from the URL query params. The URL is the
   * single source of truth so filters survive page reload and are
   * shareable via link. Kept in sync by `onFilterChange` (router.navigate).
   */
  readonly activeFilters = signal<CatalogFilters>({ category: '', sort: 'newest' });

  readonly currentFilters = computed<CatalogFilters>(() => {
    const f = this.activeFilters();
    return f.category ? f : { category: this.slug(), sort: 'newest' };
  });

  /** Products from the catalog service (filtered + paginated). */
  readonly catalogProducts = this.catalog.products;
  readonly catalogTotal    = this.catalog.total;
  readonly catalogHasMore  = this.catalog.hasMore;
  readonly isLoadingGrid   = this.catalog.isLoadingList;
  readonly facets          = this.catalog.facets;
  readonly isLoadingFacets = this.catalog.isLoadingFacets;

  /**
   * Products shown in the grid:
   *   - SSR: the embedded products from the category metadata (fast,
   *     no extra fetch, crawlers see them)
   *   - Browser after first catalog load: the filtered catalog results
   */
  readonly products = computed(() => {
    const cat = this.catalogProducts();
    if (cat.length > 0) return cat;
    // Fallback to SSR-embedded products before the catalog call completes
    return this.response()?.data?.products ?? [];
  });

  readonly hasMore = computed(() => this.catalogHasMore());

  /** Current page index (used to calculate the offset for load-more). */
  private _page = signal(0);
  readonly page = this._page.asReadonly();

  readonly isBrowser = computed(() => !isPlatformServer(this.platformId));

  constructor() {
    // SEO effect (unchanged)
    effect(() => {
      const cat = this.category();
      if (!cat) return;
      const siteUrl = environment.SITE_URL;
      const url = `${siteUrl}/category/${cat.slug}`;
      const total = this.meta()?.total_products ?? cat.product_count;
      const description = total === 0
        ? `Browse ${cat.name.toLowerCase()} from independent UAE designers on 3bayti — ` +
          'more pieces coming soon.'
        : total === 1
          ? `One hand-picked ${cat.name.toLowerCase().replace(/s$/, '')} from an independent ` +
            'UAE designer on 3bayti.'
          : `Shop ${total} hand-picked ${cat.name.toLowerCase()} from independent UAE designers. ` +
            'Curated styles, made-to-measure fits, delivered to your door.';
      this.seo.set({
        title: `${cat.name} · Modest Wear & Designer Pieces`,
        description, url, type: 'website',
      });
      this.seo.setStructuredData([
        breadcrumbSchema([
          { name: 'Home', url: `${siteUrl}/` },
          { name: 'Categories', url: `${siteUrl}/category` },
          { name: cat.name, url },
        ]),
        itemListSchema(
          this.products().map((p, idx) => ({
            position: idx + 1, name: p.name,
            url: `${siteUrl}/product/${p.slug}`,
            image: p.primary_image?.url,
          })),
        ),
      ]);
    });

    // Sync URL query params → activeFilters signal (browser + SSR).
    // We subscribe directly (not via toSignal) to avoid the overload
    // resolution issues with nullable initialValue in this Angular version.
    this.route.queryParamMap.subscribe((qp) => {
      const raw = (key: string) => qp.get(key) ?? '';
      const sizes  = raw('sizes')  ? raw('sizes').split(',')  : [];
      const colors = raw('colors') ? raw('colors').split(',') : [];
      const sort   = (raw('sort') || 'newest') as CatalogSort;
      const minP   = parseFloat(raw('min_price'));
      const maxP   = parseFloat(raw('max_price'));
      this.activeFilters.set({
        category: this.slug(),
        sizes, colors, sort,
        minPrice: isNaN(minP) ? null : minP,
        maxPrice: isNaN(maxP) ? null : maxP,
        q: raw('q') || null,
      });
    });

    // Drive catalog + facets whenever filters change (browser only)
    effect(() => {
      const filters = this.currentFilters();
      if (isPlatformServer(this.platformId) || !filters.category) return;
      this._page.set(0);
      this.catalog.reset();
      void this.catalog.loadProducts(filters, 0, false);
      void this.catalog.loadFacets(filters);
    });
  }

  /** Called by the filter panel; writes the new filter state to the URL. */
  onFilterChange(filters: CatalogFilters): void {
    const q: Record<string, string> = {};
    if (filters.sizes?.length)  q['sizes']     = filters.sizes.join(',');
    if (filters.colors?.length) q['colors']    = filters.colors.join(',');
    if (filters.sort && filters.sort !== 'newest') q['sort'] = filters.sort;
    if (filters.minPrice != null) q['min_price'] = String(filters.minPrice);
    if (filters.maxPrice != null) q['max_price'] = String(filters.maxPrice);
    if (filters.q) q['q'] = filters.q;
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: q,
      replaceUrl: true,    // don't push a history entry per filter click
    });
  }

  /** Load the next page and append to the grid. */
  async loadMore(): Promise<void> {
    const nextPage = this._page() + 1;
    this._page.set(nextPage);
    await this.catalog.loadProducts(this.currentFilters(), nextPage, true);
  }

  categoriesIndexUrl(): string { return '/category'; }

  // ── Private ──────────────────────────────────────────────────────

  private fetchCategoryDetail$(slug: string) {
    const stateKey = makeStateKey<CategoryDetailEnvelope>(`category-detail-${slug}`);
    const cached = this.state.get(stateKey, null);
    if (cached !== null) {
      this.notFound.set(false);
      return of(cached);
    }
    return this.routed.get<CategoryDetail>('GET /categories/:slug', { params: { slug } }).pipe(
      map((env) => env as unknown as CategoryDetailEnvelope),
      tap((envelope) => {
        if (isPlatformServer(this.platformId)) {
          this.state.set(stateKey, envelope);
        }
        this.notFound.set(false);
      }),
      catchError((err: HttpErrorResponse) => {
        if (err.status === 404) {
          this.notFound.set(true);
          this.setSsrStatus(404);
        } else {
          console.error(`[/category/${slug}] fetch failed:`, err.status);
        }
        return of(null as unknown as CategoryDetailEnvelope);
      }),
    );
  }
}

interface CategoryDetailEnvelope {
  data: CategoryDetail;
  meta: CategoryDetailMeta;
}
