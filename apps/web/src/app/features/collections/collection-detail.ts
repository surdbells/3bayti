import {
  Component,
  ChangeDetectionStrategy,
  DestroyRef,
  inject,
  computed,
  signal,
  effect,
} from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap, tap } from 'rxjs';
import { TranslatePipe } from '@ngx-translate/core';

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
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import { CatalogService, type CatalogFilters, type CatalogSort } from '../categories/catalog.service';
import { FilterBarComponent } from '../catalog/filter-bar';
import { ProductCardComponent } from '../catalog/product-card';
import type { CollectionDetail, CollectionDetailMeta } from './collection.model';
import { collectionUrl } from './collections.service';

/**
 * Collection detail page (PLP), `/collection/:slug`.
 *
 * Mirrors CategoryDetailComponent:
 *  1. Header + SEO from GET /collections/:slug (name, description, product
 *     count, image as a banner) which also embeds the first page of
 *     products, so the grid has content before the catalog call returns.
 *  2. Browser: a filterable, paginated grid via CatalogService
 *     (GET /products?collection=<slug> + GET /products/facets), with the
 *     shared FilterBar. The URL query string is the single source of truth
 *     for filters (shareable, survives reload); the collection slug always
 *     comes from the route param.
 *
 * Unknown / inactive slug → API 404 → inline not-found state with a link
 * back to the /collection index (not a hard router error).
 */
@Component({
  selector: 'app-collection-detail',
  standalone: true,
  imports: [
    ContainerComponent,
    HeadingComponent,
    TextComponent,
    StackComponent,
    ProductCardComponent,
    FilterBarComponent,
    CfImagePipe,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './collection-detail.html',
  // Shares the category PLP's breadcrumb / filter layout / grid / shimmer /
  // empty / load-more styles; the collection banner lives in its own file.
  styleUrls: ['../categories/category-detail.scss', './collection-detail.scss'],
})
export class CollectionDetailComponent {
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private routed = inject(RoutedHttpClient);
  private seo = inject(SeoService);
  private catalog = inject(CatalogService);
  private destroyRef = inject(DestroyRef);

  // ── Collection metadata ───────────────────────────────────────────

  /** Current slug from the route. */
  readonly slug = toSignal(
    this.route.paramMap.pipe(map((params) => params.get('slug') ?? '')),
    { initialValue: '' },
  );

  /** API 404: unknown or inactive collection slug. */
  readonly notFound = signal(false);
  /** Any other fetch failure (network / 5xx). */
  readonly loadError = signal(false);

  readonly response = toSignal(
    this.route.paramMap.pipe(
      switchMap((params) => {
        const slug = params.get('slug') ?? '';
        this.notFound.set(false);
        this.loadError.set(false);
        if (!slug) return of(null);
        return this.fetchCollectionDetail$(slug);
      }),
    ),
    { initialValue: null as CollectionDetailEnvelope | null },
  );

  readonly collection = computed<CollectionDetail | null>(() => this.response()?.data ?? null);
  readonly meta       = computed<CollectionDetailMeta | null>(() => this.response()?.meta ?? null);
  readonly loading    = computed(
    () => this.response() === null && !this.notFound() && !this.loadError(),
  );

  // ── Filter state (URL-driven) ─────────────────────────────────────

  /**
   * Filters parsed from the URL query params (sizes / colours / price /
   * sort / q). The collection scope is NOT stored here; it is always the
   * current route slug, so navigating between collections can never leave
   * a stale scope behind.
   */
  readonly activeFilters = signal<CatalogFilters>({ sort: 'newest' });

  readonly currentFilters = computed<CatalogFilters>(() => ({
    ...this.activeFilters(),
    collection: this.slug(),
  }));

  /** Products from the catalog service (filtered + paginated). */
  readonly catalogProducts = this.catalog.products;
  readonly catalogTotal    = this.catalog.total;
  readonly catalogHasMore  = this.catalog.hasMore;
  readonly isLoadingGrid   = this.catalog.isLoadingList;
  readonly facets          = this.catalog.facets;

  /**
   * True once the catalog listing for the CURRENT slug + filters has loaded.
   * Until then the grid shows the products embedded in the collection
   * payload (unfiltered view only), never another page's leftovers from the
   * shared CatalogService. Once ready, the catalog result is authoritative,
   * including an empty result for filters that match nothing.
   */
  private readonly catalogState = signal<'idle' | 'loading' | 'ready' | 'failed'>('idle');
  private readonly catalogReady = computed(() => this.catalogState() === 'ready');
  /** Bumped per catalog (re)load; a settling call that no longer matches is ignored. */
  private loadToken = 0;
  /** Collection slug the shared CatalogService last loaded a listing for. */
  private loadedScope: string | null = null;
  /** Bumped by retryListing() to re-run the catalog effect for the same slug + filters. */
  private readonly reloadTick = signal(0);

  /** The page-0 listing request failed (network / 5xx), as opposed to matching nothing. */
  readonly listFailed = computed(() => this.catalogState() === 'failed');
  /** The last "load more" failed; the footer offers an inline retry. */
  readonly loadMoreFailed = signal(false);

  /** Whether the shopper has narrowed the listing (any non-default filter). */
  readonly hasUserFilters = computed(() => {
    const f = this.activeFilters();
    return (f.sizes?.length ?? 0) > 0
      || (f.colors?.length ?? 0) > 0
      || f.minPrice != null
      || f.maxPrice != null
      || (!!f.sort && f.sort !== 'newest')
      || !!f.q;
  });

  /** Products shown in the grid. */
  readonly products = computed(() => {
    if (this.catalogReady()) return this.catalogProducts();
    if (this.hasUserFilters()) return [];
    return this.response()?.data?.products ?? [];
  });

  /** The collection's full size (banner count), independent of filters. */
  readonly collectionTotal = computed(
    () => this.meta()?.total_products ?? this.collection()?.product_count ?? 0,
  );

  /** Matching total for "Showing X of Y" (filtered once the catalog loads). */
  readonly totalProducts = computed(() =>
    this.catalogReady() ? this.catalogTotal() : this.collectionTotal(),
  );

  readonly hasMore = computed(() => this.catalogReady() && this.catalogHasMore());

  /**
   * Nothing to show and nothing pending → the empty state. Only a listing
   * that actually loaded (or the unfiltered embedded view) can be "empty";
   * a failed listing gets the distinct error state below instead, so a
   * network error is never presented as "no products match your filters".
   */
  readonly showEmpty = computed(() => {
    if (this.products().length > 0) return false;
    const state = this.catalogState();
    return state === 'ready' || (state === 'idle' && !this.hasUserFilters());
  });

  /** The listing failed and there is nothing to fall back to → error + retry. */
  readonly showListError = computed(() => this.listFailed() && this.products().length === 0);

  /** Nothing to show yet but a listing is on its way → shimmer cards. */
  readonly showSkeleton = computed(
    () => this.products().length === 0 && !this.showEmpty() && !this.showListError(),
  );

  /**
   * The listing failed but the embedded first page is on screen (unfiltered
   * view) and the collection holds more than that: no "load more" is
   * possible, so the footer offers a retry of the listing instead.
   */
  readonly showFallbackRetry = computed(
    () => this.listFailed() && this.products().length > 0
      && this.products().length < this.collectionTotal(),
  );

  /** Current page index (used to calculate the offset for load-more). */
  private _page = signal(0);
  readonly page = this._page.asReadonly();

  constructor() {
    // SEO
    effect(() => {
      const col = this.collection();
      if (!col) return;
      const siteUrl = environment.SITE_URL;
      const url = `${siteUrl}${collectionUrl(col.slug)}`;
      const total = this.collectionTotal();
      const intro = (col.description ?? '').trim();
      const description = intro !== ''
        ? intro.length > 155 ? `${intro.slice(0, 152).trimEnd()}…` : intro
        : total === 0
          ? `The ${col.name} collection on 3bayti, curated pieces from independent UAE ` +
            'designers, more coming soon.'
          : `Shop the ${col.name} collection: ${total} hand-picked ` +
            `${total === 1 ? 'piece' : 'pieces'} from independent UAE designers on 3bayti.`;
      this.seo.set({
        title: `${col.name} · Curated Collection`,
        description,
        url,
        type: 'website',
        ...(col.image_url ? { image: col.image_url } : {}),
      });
      this.seo.setStructuredData([
        breadcrumbSchema([
          { name: 'Home', url: `${siteUrl}/` },
          { name: 'Collections', url: `${siteUrl}/collection` },
          { name: col.name, url },
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

    // Sync URL query params → activeFilters.
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
      // List products only for a collection whose header actually loaded:
      // a 404 (notFound), a failed fetch (loadError) or a header still on its
      // way renders its own state with no grid, so GET /products and
      // /products/facets would be wasted calls. The header embeds the first
      // page of products, so the unfiltered grid doesn't wait on this.
      const headerReady = this.collection() !== null && !this.notFound() && !this.loadError();
      const token = ++this.loadToken;
      this._page.set(0);
      this.loadMoreFailed.set(false);
      if (!filters.collection || !headerReady) {
        this.catalogState.set('idle');
        return;
      }
      this.catalogState.set('loading');
      // A new collection is a new scope: drop the previous listing AND its
      // facets. A filter change within the same collection keeps the facets
      // so the filter bar's options don't flicker while counts refresh.
      if (filters.collection !== this.loadedScope) {
        this.loadedScope = filters.collection;
        this.catalog.reset();
      } else {
        this.catalog.resetProducts();
      }
      this.catalog.loadProducts(filters, 0, false).then(
        () => { if (token === this.loadToken) this.catalogState.set('ready'); },
        // Listing failed: the unfiltered view falls back to the embedded
        // products; a filtered view shows the "couldn't load" error state.
        () => { if (token === this.loadToken) this.catalogState.set('failed'); },
      );
      void this.catalog.loadFacets(filters);
    });
  }

  /** Re-run the page-0 listing (+ facets) after a failure. */
  retryListing(): void {
    this.reloadTick.update((n) => n + 1);
  }

  /** Called by the filter bar; writes the new filter state to the URL. */
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

  // ── Private ──────────────────────────────────────────────────────

  private fetchCollectionDetail$(slug: string) {
    return this.routed.get<CollectionDetail>('GET /collections/:slug', { params: { slug } }).pipe(
      map((env) => env as unknown as CollectionDetailEnvelope),
      tap(() => {
        this.notFound.set(false);
        this.loadError.set(false);
      }),
      catchError((err: HttpErrorResponse) => {
        if (err.status === 404) {
          this.notFound.set(true);
        } else {
          console.error(`[/collection/${slug}] fetch failed:`, err.status);
          this.loadError.set(true);
        }
        return of(null);
      }),
      // A new slug starts from the loading state rather than showing the
      // previous collection's header until the new one arrives.
      startWith(null),
    );
  }
}

interface CollectionDetailEnvelope {
  data: CollectionDetail;
  meta: CollectionDetailMeta;
}
