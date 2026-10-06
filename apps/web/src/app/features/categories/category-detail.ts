import {
  Component,
  ChangeDetectionStrategy,
  DestroyRef,
  ElementRef,
  inject,
  computed,
  signal,
  effect,
  viewChild,
} from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap, tap } from 'rxjs';

import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema, itemListSchema } from '../../core/seo/schema.helpers';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { environment } from '../../../environments/environment';
import {
  ContainerComponent,
  HeadingComponent,
  TextComponent,
  StackComponent,
} from '../../shared/ui';
import type { CategoryDetail, CategoryDetailMeta } from './category.model';
import { CatalogService, type CatalogFilters, type CatalogSort } from './catalog.service';
import { FilterBarComponent } from '../catalog/filter-bar';
import { ProductCardComponent } from '../catalog/product-card';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * Category detail page, `/category/:slug`.
 *
 * Now serves two roles:
 *  1. SSR: fetches category metadata + embedded 20 products from
 *     `/v3/categories/:slug` for SEO (unchanged, crawlers still see
 *     a rich, pre-rendered product grid).
 *  2. Browser: drives a filterable, paginated grid via CatalogService
 *     (GET /v3/products + GET /v3/products/facets). Filter state is
 *     synced to / read from URL query params so filters are shareable;
 *     the category scope always comes from the route slug (as on
 *     CollectionDetailComponent), so an in-app hop from one category to
 *     another, even with identical query params, reloads for the new one.
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
    FilterBarComponent,
    TranslatePipe,
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
  private catalog = inject(CatalogService);
  private destroyRef = inject(DestroyRef);

  // ── Category metadata ─────────────────────────────────────────────

  /** Current slug from the route. */
  readonly slug = toSignal(
    this.route.paramMap.pipe(map((params) => params.get('slug') ?? '')),
    { initialValue: '' },
  );

  /** API 404: unknown category slug. */
  readonly notFound = signal(false);
  /** Any other fetch failure (network / 5xx). */
  readonly loadError = signal(false);

  readonly response = toSignal(
    this.route.paramMap.pipe(
      switchMap((params) => {
        const slug = params.get('slug') ?? '';
        // Header state is per slug: a new category never inherits the
        // previous one's not-found / error state.
        this.notFound.set(false);
        this.loadError.set(false);
        if (!slug) return of(null);
        return this.fetchCategoryDetail$(slug);
      }),
    ),
    { initialValue: null as CategoryDetailEnvelope | null },
  );

  readonly category  = computed<CategoryDetail | null>(() => this.response()?.data ?? null);
  readonly meta      = computed<CategoryDetailMeta | null>(() => this.response()?.meta ?? null);
  /** Header still on its way (a failed fetch renders its own state instead). */
  readonly loading   = computed(
    () => this.response() === null && !this.notFound() && !this.loadError(),
  );

  // ── Filter state (URL-driven) ─────────────────────────────────────

  /**
   * Filters parsed from the URL query params (sizes / colours / price /
   * sort / q). The URL is the single source of truth so filters survive
   * page reload and are shareable via link; kept in sync by
   * `onFilterChange` (router.navigate). The category scope is NOT stored
   * here: it is always the current route slug (see currentFilters), so a
   * slug change without a query-param change can't leave a stale scope.
   */
  readonly activeFilters = signal<CatalogFilters>({ sort: 'newest' });

  readonly currentFilters = computed<CatalogFilters>(() => ({
    ...this.activeFilters(),
    category: this.slug(),
  }));

  /** Products from the catalog service (filtered + paginated). */
  readonly catalogProducts = this.catalog.products;
  readonly catalogTotal    = this.catalog.total;
  readonly catalogHasMore  = this.catalog.hasMore;
  readonly isLoadingGrid   = this.catalog.isLoadingList;
  readonly facets          = this.catalog.facets;
  readonly isLoadingFacets = this.catalog.isLoadingFacets;

  /**
   * State of the page-0 listing for the CURRENT slug + filters. Once
   * 'ready' the catalog result is authoritative (including an empty result
   * for filters that match nothing); 'failed' is a network / server error,
   * shown as such rather than as "no products match your filters".
   */
  private readonly listState = signal<'idle' | 'loading' | 'ready' | 'failed'>('idle');
  private readonly listReady = computed(() => this.listState() === 'ready');
  /** Bumped per listing (re)load; a settling call that no longer matches is ignored. */
  private loadToken = 0;
  /** Category slug the shared CatalogService last loaded a listing for. */
  private loadedScope: string | null = null;
  /** Bumped by retryListing() to re-run the catalog effect for the same filters. */
  private readonly reloadTick = signal(0);

  /** The page-0 listing request failed (network / 5xx). */
  readonly listFailed = computed(() => this.listState() === 'failed');
  /** The last "load more" failed; the footer offers an inline retry. */
  readonly loadMoreFailed = signal(false);

  /** Whether the shopper has narrowed the listing (any non-default filter). */
  readonly hasUserFilters = computed(() => {
    const f = this.currentFilters();
    return (f.sizes?.length ?? 0) > 0
      || (f.colors?.length ?? 0) > 0
      || f.minPrice != null
      || f.maxPrice != null
      || (!!f.sort && f.sort !== 'newest')
      || !!f.q;
  });

  /**
   * Products shown in the grid:
   *   - once the catalog listing has loaded: the filtered catalog results
   *   - before that (or if it failed): the products embedded in the
   *     category metadata, but only for the unfiltered view; a filtered
   *     view never shows unfiltered products as if they matched
   */
  readonly products = computed(() => {
    if (this.listReady()) return this.catalogProducts();
    if (this.hasUserFilters()) return [];
    return this.response()?.data?.products ?? [];
  });

  /** Matching total for "Showing X of Y" (the category size until the listing loads). */
  readonly totalProducts = computed(() =>
    this.listReady()
      ? this.catalogTotal()
      : this.meta()?.total_products ?? this.category()?.product_count ?? 0,
  );

  readonly hasMore = computed(() => this.listReady() && this.catalogHasMore());

  /**
   * Nothing to show and nothing pending → the empty state. Only a listing
   * that actually loaded (or the unfiltered embedded view) can be "empty";
   * a failed listing gets the distinct error state instead, so a network
   * error is never presented as "no products match your filters".
   */
  readonly showEmpty = computed(() => {
    if (this.products().length > 0) return false;
    const state = this.listState();
    return state === 'ready' || (state === 'idle' && !this.hasUserFilters());
  });

  /** The listing failed and there is nothing to fall back to → error + retry. */
  readonly showListError = computed(() => this.listFailed() && this.products().length === 0);

  /**
   * Nothing to show yet but a listing is on its way → shimmer cards. While
   * a page loads with products already on screen (a "load more", or the
   * embedded first page) the grid stays, and so does a focused button.
   */
  readonly showSkeleton = computed(
    () => this.products().length === 0 && !this.showEmpty() && !this.showListError(),
  );

  /**
   * The listing failed but the embedded products are on screen and the
   * category holds more: no "load more" is possible, so offer a retry.
   */
  readonly showFallbackRetry = computed(
    () => this.listFailed() && this.products().length > 0
      && this.products().length < this.totalProducts(),
  );

  /** Current page index (used to calculate the offset for load-more). */
  private _page = signal(0);
  readonly page = this._page.asReadonly();

  /** The product-grid region (tabindex="-1"), focus target for a listing retry. */
  private readonly gridRegion = viewChild<ElementRef<HTMLElement>>('gridRegion');

  constructor() {
    // SEO
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

    // Sync URL query params → activeFilters (the scope comes from the slug).
    this.route.queryParamMap
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((qp) => {
        const raw = (key: string) => qp.get(key) ?? '';
        const sizes  = raw('sizes')  ? raw('sizes').split(',')  : [];
        const colors = raw('colors') ? raw('colors').split(',') : [];
        const sort   = (raw('sort') || 'newest') as CatalogSort;
        const minP   = parseFloat(raw('min_price'));
        const maxP   = parseFloat(raw('max_price'));
        this.activeFilters.set({
          sizes, colors, sort,
          minPrice: isNaN(minP) ? null : minP,
          maxPrice: isNaN(maxP) ? null : maxP,
          q: raw('q') || null,
        });
      });

    // Drive catalog + facets whenever the slug, filters or a retry change.
    effect(() => {
      const filters = this.currentFilters();
      this.reloadTick();
      // List products only for a category whose header actually loaded: a
      // 404 (notFound), a failed fetch (loadError) or a header still on its
      // way (e.g. right after a hop to another category) renders its own
      // state with no grid, so GET /products and /products/facets would be
      // wasted calls. The header embeds the first page of products, so the
      // unfiltered grid doesn't wait on this.
      const headerReady = this.category() !== null && !this.notFound() && !this.loadError();
      const token = ++this.loadToken;
      this._page.set(0);
      this.loadMoreFailed.set(false);
      if (!filters.category || !headerReady) {
        this.listState.set('idle');
        return;
      }
      this.listState.set('loading');
      // A new category is a new scope: drop the previous listing AND its
      // facets. A filter change within the same category keeps the facets
      // so the filter bar's options don't flicker while counts refresh.
      if (filters.category !== this.loadedScope) {
        this.loadedScope = filters.category;
        this.catalog.reset();
      } else {
        this.catalog.resetProducts();
      }
      this.catalog.loadProducts(filters, 0, false).then(
        () => { if (token === this.loadToken) this.listState.set('ready'); },
        () => { if (token === this.loadToken) this.listState.set('failed'); },
      );
      void this.catalog.loadFacets(filters);
    });
  }

  /**
   * Re-run the page-0 listing (+ facets) after a failure. The Retry button
   * that triggered this disappears (the error / fallback state gives way to
   * the shimmer or the reloading grid), so focus moves to the stable grid
   * region rather than dropping to <body>.
   */
  retryListing(): void {
    this.reloadTick.update((n) => n + 1);
    this.gridRegion()?.nativeElement.focus({ preventScroll: true });
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

  /**
   * Load the next page and append to the grid. Never rejects: on failure
   * the page index is rolled back (so the retry re-requests the same page
   * instead of skipping one) and the footer shows an inline retry.
   */
  async loadMore(): Promise<void> {
    if (this.isLoadingGrid()) return;
    const token = this.loadToken;
    const prevPage = this._page();
    const nextPage = prevPage + 1;
    this._page.set(nextPage);
    this.loadMoreFailed.set(false);
    try {
      await this.catalog.loadProducts(this.currentFilters(), nextPage, true);
    } catch {
      // A filter / slug change since the click already restarted paging
      // from page 0; only roll back the page this call advanced.
      if (token !== this.loadToken) return;
      this._page.set(prevPage);
      this.loadMoreFailed.set(true);
    }
  }

  categoriesIndexUrl(): string { return '/category'; }

  // ── Private ──────────────────────────────────────────────────────

  private fetchCategoryDetail$(slug: string) {
    return this.routed.get<CategoryDetail>('GET /categories/:slug', { params: { slug } }).pipe(
      map((env) => env as unknown as CategoryDetailEnvelope),
      tap(() => {
        this.notFound.set(false);
        this.loadError.set(false);
      }),
      catchError((err: HttpErrorResponse) => {
        if (err.status === 404) {
          this.notFound.set(true);
        } else {
          console.error(`[/category/${slug}] fetch failed:`, err.status);
          this.loadError.set(true);
        }
        return of(null);
      }),
      // A new slug starts from the loading state rather than showing the
      // previous category's header (and embedded products) until the new
      // one arrives.
      startWith(null),
    );
  }
}

interface CategoryDetailEnvelope {
  data: CategoryDetail;
  meta: CategoryDetailMeta;
}
