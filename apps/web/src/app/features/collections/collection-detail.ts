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
import { handOffLoadMoreFocus } from '../catalog/load-more-focus';
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
 *     comes from the route param. As the scope is known from the route, the
 *     listing + facets are requested in PARALLEL with the header (a filtered
 *     deep link costs one round trip, not two).
 *
 * Unknown / inactive slug → API 404 → inline not-found state with a link
 * back to the /collection index (not a hard router error); any listing
 * started for it is discarded. A network / 5xx header failure offers a
 * Retry that re-requests the header (and the listing with it).
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
  private injector = inject(Injector);

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

  /** Bumped by retryHeader() to re-request the header for the same slug. */
  private readonly headerReload$ = new BehaviorSubject(0);

  readonly response = toSignal(
    combineLatest([this.route.paramMap, this.headerReload$]).pipe(
      switchMap(([params]) => {
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

  /** The product-grid region (tabindex="-1"), focus target for a listing retry. */
  private readonly gridRegion = viewChild<ElementRef<HTMLElement>>('gridRegion');
  /** Everything below the breadcrumb (tabindex="-1"), focus target for a header retry. */
  private readonly pageBody = viewChild<unknown, ElementRef<HTMLElement>>('pageBody', { read: ElementRef });
  /** The one "Load more" / retry button, while more pages exist. */
  private readonly loadMoreButton = viewChild<ElementRef<HTMLButtonElement>>('loadMoreBtn');

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

    // Drive catalog + facets whenever the slug, filters, a retry or the
    // header's failure state change.
    effect(() => {
      const filters = this.currentFilters();
      this.reloadTick();
      // The scope is the ROUTE slug, known before GET /collections/:slug
      // answers, so the listing + facets start in parallel with the header
      // instead of after it (no second serial round trip on a filtered deep
      // link). Only a header that has FAILED stops them: a 404 or a network /
      // 5xx failure renders its own state with no grid, so a listing for it
      // is discarded. A header retry (or a hop to another slug) clears that
      // state and lists again; a header that is merely in flight, or that
      // then resolves fine, does not re-run this. Until the listing is ready
      // the unfiltered grid shows the products the header embeds, as before.
      const headerFailed = this.notFound() || this.loadError();
      // The rest only writes state and issues requests: untracked, so a
      // signal the catalog happens to read can never re-trigger this effect.
      untracked(() => {
        const token = ++this.loadToken;
        this._page.set(0);
        this.loadMoreFailed.set(false);
        if (!filters.collection || headerFailed) {
          this.catalogState.set('idle');
          this.discardListing();
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
   * Re-request the collection header after a network / 5xx failure.
   * Clearing the error state also re-runs the listing + facets for the same
   * slug and filters (in parallel with the header). The Retry button that
   * triggered this gives way to the loading state, so focus moves to the
   * stable page body rather than dropping to <body>.
   */
  retryHeader(): void {
    this.headerReload$.next(this.headerReload$.value + 1);
    this.pageBody()?.nativeElement.focus({ preventScroll: true });
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

  // ── Private ──────────────────────────────────────────────────────

  /**
   * Drop a listing started for a collection whose header then failed (404 /
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
