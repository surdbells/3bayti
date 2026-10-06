import { Injectable, signal, computed, Signal, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type { Product } from '../catalog/product.model';

/** Default page size, matches the API's GET /products default (24). */
export const CATALOG_PAGE_SIZE = 24;

/** Valid sort values accepted by GET /v3/products. */
export type CatalogSort =
  | 'newest' | 'oldest' | 'price_asc' | 'price_desc' | 'relevance' | 'best_seller';

export const CATALOG_SORTS: readonly CatalogSort[] = [
  'newest', 'oldest', 'price_asc', 'price_desc', 'relevance', 'best_seller',
] as const;

/**
 * The active catalog filter state. All fields optional; absent = unset.
 * `category` is normally fixed by the page route (category listing) but
 * is modelled here so the service is reusable for other listings.
 */
export interface CatalogFilters {
  category?: string | null;
  /** Admin-curated collection slug (GET /v3/products?collection=<slug>);
   *  fixed by the route on the collection listing page. */
  collection?: string | null;
  vendor?: string | null;
  sizes?: string[];
  colors?: string[];
  minPrice?: number | null;
  maxPrice?: number | null;
  sort?: CatalogSort;
  q?: string | null;
  /** When true, restrict to on-sale products (GET /v3/products?sale=true). */
  sale?: boolean;
}

/** A single selectable facet value with its live count. */
export interface FacetValue {
  value: string;
  label?: string;     // present for vendor / category facets
  count: number;
  min?: number;       // price bands
  max?: number;       // price bands
}

/** The full facet set returned by GET /v3/products/facets. */
export interface Facets {
  size: { values: FacetValue[]; total_distinct: number };
  color: { values: FacetValue[]; total_distinct: number };
  price: { values: FacetValue[] };
  vendor: { values: FacetValue[]; total_distinct: number };
  category: { values: FacetValue[]; total_distinct: number };
  total_products: number;
}

/** A page of filtered products. */
export interface CatalogPage {
  items: Product[];
  total: number;
  hasMore: boolean;
  /**
   * True when a newer loadProducts() / reset() superseded this call before
   * it settled: the page was NOT applied to the accumulator (and a failure
   * was swallowed rather than rethrown). Absent for an applied page.
   */
  stale?: boolean;
}

interface ProductsMeta {
  total?: number;
  limit?: number;
  offset?: number;
  has_more?: boolean;
}

/**
 * CatalogService, filtered, paginated product listing + facet counts
 * for the category listing page (M3.2.W.2). Consumes the X.10 backend:
 *
 *   GET /v3/products          (filtered, paginated grid)
 *   GET /v3/products/facets   (disjunctive facet counts)
 *
 * Public catalog reads → RoutedHttpClient (like StoreService).
 *
 * State surface
 * -------------
 * The product list accumulates across "load more" pages and lives in
 * the service so the listing can paginate without re-fetching page 0:
 *   - products()      Signal<Product[]>  (accumulated)
 *   - total()         Signal<number>     (matching the active filters)
 *   - hasMore()       computed boolean
 *   - facets()        Signal<Facets | null>
 *   - isLoadingList() / isLoadingFacets()
 *
 * Filters are owned by the page (so they can be driven from / synced to
 * the URL query string) and passed in on each call; the service holds
 * only the result accumulator, not the filter state. This keeps the
 * service reusable and the URL the single source of truth for filters.
 *
 * Request sequencing
 * ------------------
 * The service is a ROOT singleton shared by every listing page, and a
 * shopper can change filters (or click "load more") while an earlier
 * request is still in flight. Each loadProducts() / loadFacets() call
 * takes a sequence number (separate counters); when a call settles and
 * is no longer the latest of its kind, its result is discarded without
 * touching any state, so a slow superseded response can never overwrite
 * or be appended to a newer listing. reset() / resetProducts() /
 * resetFacets() also invalidate in-flight calls of the kind they clear.
 *
 * Scope vs filters
 * ----------------
 * Facets describe a listing SCOPE (category / collection / vendor /
 * search / sale) and are kept across plain filter changes within that
 * scope, so the filter bar's options don't flicker away on every click.
 * reset() clears them (call it when the scope changes, e.g. on entering
 * a page); resetProducts() keeps them (call it for a filter change on
 * the same page). loadFacets() additionally drops facets that belong to
 * a different scope before fetching, so a page can never show (or, on a
 * failed load, keep) another page's size / colour / price options.
 */
@Injectable({ providedIn: 'root' })
export class CatalogService {
  private readonly http = inject(RoutedHttpClient);

  private readonly _products = signal<Product[]>([]);
  private readonly _total = signal<number>(0);
  private readonly _lastPageHasMore = signal<boolean>(false);
  private readonly _facets = signal<Facets | null>(null);
  private readonly _isLoadingList = signal<boolean>(false);
  private readonly _isLoadingFacets = signal<boolean>(false);

  /** Latest loadProducts() call; older calls settle as stale no-ops. */
  private productsSeq = 0;
  /** Latest loadFacets() call; older calls settle as stale no-ops. */
  private facetsSeq = 0;
  /** Scope key of the facets currently held (null = none held). */
  private facetsScope: string | null = null;

  readonly products: Signal<Product[]> = this._products.asReadonly();
  readonly total: Signal<number> = this._total.asReadonly();
  readonly facets: Signal<Facets | null> = this._facets.asReadonly();
  readonly isLoadingList: Signal<boolean> = this._isLoadingList.asReadonly();
  readonly isLoadingFacets: Signal<boolean> = this._isLoadingFacets.asReadonly();
  readonly hasMore = computed(() => this._lastPageHasMore());
  readonly loadedCount = computed(() => this._products().length);

  /**
   * Full reset: clears the product accumulator AND the facets, and
   * invalidates every in-flight product / facet load. Call it when the
   * listing scope changes (entering a page, switching category or
   * collection) so nothing from the previous scope can surface.
   */
  reset(): void {
    this.resetProducts();
    this.resetFacets();
  }

  /**
   * Clear only the product accumulator (and invalidate in-flight product
   * loads), keeping the current facets. Call it before a fresh page-0 load
   * after a filter change within the same scope.
   */
  resetProducts(): void {
    this.productsSeq++;
    this._products.set([]);
    this._total.set(0);
    this._lastPageHasMore.set(false);
    this._isLoadingList.set(false);
  }

  /** Clear the facets and invalidate in-flight facet loads. */
  resetFacets(): void {
    this.facetsSeq++;
    this.facetsScope = null;
    this._facets.set(null);
    this._isLoadingFacets.set(false);
  }

  /**
   * Load a page of products for the given filters. When `append` is
   * false (default) the accumulator is replaced (fresh filter change);
   * when true the page is appended ("load more").
   *
   * Rejects when the LATEST call fails (the accumulator is left as it
   * was). A call superseded by a newer loadProducts() / reset() before it
   * settles resolves with `stale: true` and changes nothing, whether its
   * request succeeded or failed.
   */
  async loadProducts(
    filters: CatalogFilters,
    page = 0,
    append = false,
  ): Promise<CatalogPage> {
    const seq = ++this.productsSeq;
    this._isLoadingList.set(true);
    try {
      const query = {
        ...this.toQuery(filters),
        limit: CATALOG_PAGE_SIZE,
        offset: page * CATALOG_PAGE_SIZE,
      };
      const res = await firstValueFrom(
        this.http.get<Product[]>('GET /products', { query }),
      );
      const items = Array.isArray(res.data) ? res.data : [];
      const meta = (res.meta ?? {}) as ProductsMeta;
      const total = typeof meta.total === 'number' ? meta.total : items.length;
      const hasMore = meta.has_more === true;

      if (seq !== this.productsSeq) {
        return { items, total, hasMore, stale: true };
      }

      this._products.set(append ? [...this._products(), ...items] : items);
      this._total.set(total);
      this._lastPageHasMore.set(hasMore);

      return { items, total, hasMore };
    } catch (err) {
      if (seq !== this.productsSeq) {
        return { items: [], total: 0, hasMore: false, stale: true };
      }
      throw err;
    } finally {
      if (seq === this.productsSeq) {
        this._isLoadingList.set(false);
      }
    }
  }

  /**
   * Load facet counts for the given filters. Disjunctive semantics:
   * each facet's counts reflect what the user would get if they
   * switched that one dimension, so counts stay meaningful while
   * filtering.
   *
   * Facets held for a different scope are dropped before the request, so
   * the filter bar never shows another listing's options. Within the same
   * scope a failure leaves the current facets in place (filters still
   * work without fresh counts); after a scope change a failure leaves
   * none. A superseded call settles without touching any state.
   */
  async loadFacets(filters: CatalogFilters): Promise<Facets | null> {
    const seq = ++this.facetsSeq;
    const scope = this.scopeKey(filters);
    if (scope !== this.facetsScope) {
      this.facetsScope = scope;
      this._facets.set(null);
    }
    this._isLoadingFacets.set(true);
    try {
      const res = await firstValueFrom(
        this.http.get<Facets>('GET /products/facets', { query: this.toQuery(filters) }),
      );
      const data = (res.data ?? null) as Facets | null;
      if (seq !== this.facetsSeq) return data;
      if (data) {
        this._facets.set(data);
      }
      return data;
    } catch {
      if (seq !== this.facetsSeq) return null;
      return this._facets();
    } finally {
      if (seq === this.facetsSeq) {
        this._isLoadingFacets.set(false);
      }
    }
  }

  /**
   * The listing scope a facet set describes: everything that changes the
   * product universe rather than narrowing within it (size / colour /
   * price / sort are filters inside a scope).
   */
  private scopeKey(filters: CatalogFilters): string {
    return JSON.stringify([
      filters.category || null,
      filters.collection || null,
      filters.vendor || null,
      filters.q || null,
      filters.sale === true,
    ]);
  }

  /**
   * Serialise filters to the API's query vocabulary. Multi-value facets
   * are sent comma-joined (the backend accepts both array and CSV form).
   * Empty / null fields are omitted entirely.
   */
  toQuery(filters: CatalogFilters): Record<string, string | number> {
    const q: Record<string, string | number> = {};
    if (filters.category) q['category'] = filters.category;
    if (filters.collection) q['collection'] = filters.collection;
    if (filters.vendor) q['vendor'] = filters.vendor;
    if (filters.sizes && filters.sizes.length > 0) q['sizes'] = filters.sizes.join(',');
    if (filters.colors && filters.colors.length > 0) q['colors'] = filters.colors.join(',');
    if (typeof filters.minPrice === 'number') q['min_price'] = filters.minPrice;
    if (typeof filters.maxPrice === 'number') q['max_price'] = filters.maxPrice;
    if (filters.sort && filters.sort !== 'newest') q['sort'] = filters.sort;
    if (filters.q) q['q'] = filters.q;
    if (filters.sale) q['sale'] = 'true';
    return q;
  }
}
