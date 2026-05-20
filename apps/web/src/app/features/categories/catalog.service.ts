import { Injectable, signal, computed, Signal, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type { Product } from '../catalog/product.model';

/** Default page size — matches the API's GET /products default (24). */
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
  vendor?: string | null;
  sizes?: string[];
  colors?: string[];
  minPrice?: number | null;
  maxPrice?: number | null;
  sort?: CatalogSort;
  q?: string | null;
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
}

interface ProductsMeta {
  total?: number;
  limit?: number;
  offset?: number;
  has_more?: boolean;
}

/**
 * CatalogService — filtered, paginated product listing + facet counts
 * for the category listing page (M3.2.W.2). Consumes the X.10 backend:
 *
 *   GET /v3/products          (filtered, paginated grid)
 *   GET /v3/products/facets   (disjunctive facet counts)
 *
 * Public catalog reads → RoutedHttpClient (like DesignerService).
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

  readonly products: Signal<Product[]> = this._products.asReadonly();
  readonly total: Signal<number> = this._total.asReadonly();
  readonly facets: Signal<Facets | null> = this._facets.asReadonly();
  readonly isLoadingList: Signal<boolean> = this._isLoadingList.asReadonly();
  readonly isLoadingFacets: Signal<boolean> = this._isLoadingFacets.asReadonly();
  readonly hasMore = computed(() => this._lastPageHasMore());
  readonly loadedCount = computed(() => this._products().length);

  /** Reset the accumulator (call before a fresh filter load). */
  reset(): void {
    this._products.set([]);
    this._total.set(0);
    this._lastPageHasMore.set(false);
  }

  /**
   * Load a page of products for the given filters. When `append` is
   * false (default) the accumulator is replaced (fresh filter change);
   * when true the page is appended ("load more").
   */
  async loadProducts(
    filters: CatalogFilters,
    page = 0,
    append = false,
  ): Promise<CatalogPage> {
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

      this._products.set(append ? [...this._products(), ...items] : items);
      this._total.set(total);
      this._lastPageHasMore.set(hasMore);

      return { items, total, hasMore };
    } finally {
      this._isLoadingList.set(false);
    }
  }

  /**
   * Load facet counts for the given filters. Disjunctive semantics:
   * each facet's counts reflect what the user would get if they
   * switched that one dimension, so counts stay meaningful while
   * filtering. Failure leaves the previous facets in place (filters
   * still work without counts).
   */
  async loadFacets(filters: CatalogFilters): Promise<Facets | null> {
    this._isLoadingFacets.set(true);
    try {
      const res = await firstValueFrom(
        this.http.get<Facets>('GET /products/facets', { query: this.toQuery(filters) }),
      );
      const data = (res.data ?? null) as Facets | null;
      if (data) {
        this._facets.set(data);
      }
      return data;
    } catch {
      return this._facets();
    } finally {
      this._isLoadingFacets.set(false);
    }
  }

  /**
   * Serialise filters to the API's query vocabulary. Multi-value facets
   * are sent comma-joined (the backend accepts both array and CSV form).
   * Empty / null fields are omitted entirely.
   */
  toQuery(filters: CatalogFilters): Record<string, string | number> {
    const q: Record<string, string | number> = {};
    if (filters.category) q['category'] = filters.category;
    if (filters.vendor) q['vendor'] = filters.vendor;
    if (filters.sizes && filters.sizes.length > 0) q['sizes'] = filters.sizes.join(',');
    if (filters.colors && filters.colors.length > 0) q['colors'] = filters.colors.join(',');
    if (typeof filters.minPrice === 'number') q['min_price'] = filters.minPrice;
    if (typeof filters.maxPrice === 'number') q['max_price'] = filters.maxPrice;
    if (filters.sort && filters.sort !== 'newest') q['sort'] = filters.sort;
    if (filters.q) q['q'] = filters.q;
    return q;
  }
}
