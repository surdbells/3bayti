import {
  Component,
  ChangeDetectionStrategy,
  DestroyRef,
  ElementRef,
  Injector,
  inject,
  computed,
  signal,
  effect,
  untracked,
  viewChild,
} from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, catchError, combineLatest, map, of, startWith, switchMap, tap } from 'rxjs';

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
import { handOffLoadMoreFocus } from '../catalog/load-more-focus';
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
 *     As the scope is known from the route, the listing + facets are
 *     requested in PARALLEL with the header (a filtered deep link costs one
 *     round trip, not two); a header that 404s / fails discards them.
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
  private injector = inject(Injector);

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

  /** Bumped by retryHeader() to re-request the header for the same slug. */
  private readonly headerReload$ = new BehaviorSubject(0);

  readonly response = toSignal(
    combineLatest([this.route.paramMap, this.headerReload$]).pipe(
      switchMap(([params]) => {
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

  /** Slug a header Retry was made for (its outcome is announced politely). */
  private readonly retriedSlug = signal<string | null>(null);

  /**
   * What the status region announces after a header Retry: the content
   * under the (focused) page body is swapped, which screen readers would
   * otherwise not report. Empty until a Retry, and for any other slug.
   */
  readonly retryAnnouncement = computed<'' | 'loading' | 'restored' | 'error' | 'notFound'>(() => {
    const retried = this.retriedSlug();
    if (retried === null || retried !== this.slug()) return '';
    if (this.loading()) return 'loading';
    if (this.category()) return 'restored';
    if (this.loadError()) return 'error';
    if (this.notFound()) return 'notFound';
    return '';
  });

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
  /** Everything below the breadcrumb (tabindex="-1"), focus target for a header retry. */
  private readonly pageBody = viewChild<unknown, ElementRef<HTMLElement>>('pageBody', { read: ElementRef });
  /** The one "Load more" / retry button, while more pages exist. */
  private readonly loadMoreButton = viewChild<ElementRef<HTMLButtonElement>>('loadMoreBtn');

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

    // Drive catalog + facets whenever the slug, filters, a retry or the
    // header's failure state change.
    effect(() => {
      const filters = this.currentFilters();
      this.reloadTick();
      // The scope is the ROUTE slug, known before GET /categories/:slug
      // answers, so the listing + facets start in parallel with the header
      // instead of after it (no second serial round trip on a filtered deep
      // link). Only a header that has FAILED stops them: a 404 or a network /
      // 5xx failure renders its own state with no grid, so a listing for it
      // is discarded. A header retry (or a hop to another slug) clears that
      // state and lists again; a header that is merely in flight, or that
      // then resolves fine, does not re-run this.
      const headerFailed = this.notFound() || this.loadError();
      // The rest only writes state and issues requests: untracked, so a
      // signal the catalog happens to read can never re-trigger this effect.
      untracked(() => {
        const token = ++this.loadToken;
        this._page.set(0);
        this.loadMoreFailed.set(false);
        if (!filters.category || headerFailed) {
          this.listState.set('idle');
          this.discardListing();
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

  /**
   * Re-request the category header after a network / 5xx failure. Clearing
   * the error state also re-runs the listing + facets for the same slug and
   * filters (in parallel with the header). The Retry button that triggered
   * this gives way to the loading state, so focus moves to the stable page
   * body rather than dropping to <body>.
   */
  retryHeader(): void {
    this.retriedSlug.set(this.slug());
    this.headerReload$.next(this.headerReload$.value + 1);
    this.pageBody()?.nativeElement.focus({ preventScroll: true });
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
   * instead of skipping one) and the footer shows an inline retry. When it
   * fetched the LAST page the button is removed, so a shopper who was on it
   * moves to the first appended product instead of dropping to <body>.
   */
  async loadMore(): Promise<void> {
    if (this.isLoadingGrid()) return;
    const token = this.loadToken;
    const prevPage = this._page();
    const nextPage = prevPage + 1;
    const shownBefore = this.products().length;
    const button = this.loadMoreButton()?.nativeElement;
    const focusedAtStart = !!button && button.ownerDocument.activeElement === button;
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
      return;
    }
    if (token !== this.loadToken || this.hasMore()) return;
    handOffLoadMoreFocus({
      injector: this.injector,
      button,
      focusedAtStart,
      firstNewIndex: shownBefore,
      cards: () => this.gridRegion()?.nativeElement.querySelectorAll('.product-grid > ui-product-card'),
      fallback: () => this.gridRegion()?.nativeElement,
    });
  }

  categoriesIndexUrl(): string { return '/category'; }

  // ── Private ──────────────────────────────────────────────────────

  /**
   * Drop a listing started for a category whose header then failed (404 /
   * network / 5xx). The caller has already bumped loadToken, so its pending
   * page-0 callbacks are ignored; the full reset also invalidates the shared
   * CatalogService's in-flight product + facet requests and clears whatever
   * they already stored, so nothing listed for the failed header can
   * surface, and the next listing starts from a clean scope.
   */
  private discardListing(): void {
    if (this.loadedScope === null) return;
    this.loadedScope = null;
    this.catalog.reset();
  }

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
