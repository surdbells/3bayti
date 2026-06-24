import { Injectable, signal, computed, Signal, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type {
  Store,
  DirectoryStore,
  StoreListParams,
  StoreReview,
  StoreReviewsPage,
} from './store.model';
import type { Product, StoreSizeChartRow } from './product.model';
import type { FeaturedVendor } from './store-card';

/** Default page size for the directory + per-store product grids.
 *  Matches the API's ListVendorProducts default (24). */
export const DESIGNER_PAGE_SIZE = 24;

/** Page size for the STORES directory grid (load-more). Smaller than the
 *  per-store product grid so the A-Z store list paginates in digestible
 *  batches of 10 rather than dumping every store at once. */
export const STORE_DIRECTORY_PAGE_SIZE = 10;

/** Result of a paginated product fetch — items plus whether more exist. */
export interface StoreProductsPage {
  items: Product[];
  hasMore: boolean;
}

/**
 * StoreService — storefront reads for the store directory and
 * per-store pages (Y.4). All endpoints are public catalog reads, so
 * this goes through RoutedHttpClient (like home-data.service.ts), NOT
 * the Bearer-bound direct client used by cart/orders/checkout.
 *
 * State surface
 * -------------
 * The directory list accumulates across load-more pages and lives in
 * the service (mirrors OrderService) so the /store page can scroll
 * without re-fetching page 0 on every interaction:
 *   - directory()      Signal<Store[]>
 *   - hasMore()        computed boolean (from the last page's meta)
 *   - isLoadingList()
 *
 * Single-store detail and per-store products are NOT held in
 * service signals — the detail page owns those (two store pages
 * could be open in different tabs; per-page ownership avoids
 * cross-contamination), exactly as the order-detail page does.
 */
@Injectable({ providedIn: 'root' })
export class StoreService {
  private readonly http = inject(RoutedHttpClient);

  private readonly _directory = signal<DirectoryStore[]>([]);
  private readonly _isLoadingList = signal<boolean>(false);
  private readonly _lastPageHasMore = signal<boolean>(false);

  readonly directory: Signal<DirectoryStore[]> = this._directory.asReadonly();
  readonly isLoadingList: Signal<boolean> = this._isLoadingList.asReadonly();
  readonly hasMore = computed(() => this._lastPageHasMore());
  readonly loadedCount = computed(() => this._directory().length);

  /** Reset the directory accumulator (call before a fresh load). */
  reset(): void {
    this._directory.set([]);
    this._lastPageHasMore.set(false);
  }

  /**
   * Load the next page of the store directory. Appends to the
   * accumulator (offset > 0) or replaces it (offset === 0).
   *
   * NOTE: the current v3 ListVendors endpoint returns ALL active
   * vendors in a single envelope (limit = count, has_more = false),
   * so in practice this resolves in one page today. The load-more
   * machinery is kept so that when the endpoint gains real pagination
   * the UI needs no change.
   */
  async loadMore(params: StoreListParams = {}): Promise<DirectoryStore[]> {
    const limit = params.limit ?? DESIGNER_PAGE_SIZE;
    const offset = params.offset ?? this.loadedCount();

    this._isLoadingList.set(true);
    try {
      const env = await firstValueFrom(
        this.http.get<DirectoryStore[]>('GET /vendors', {
          query: { limit, offset },
        }),
      );
      const page = Array.isArray(env.data) ? env.data : [];
      if (offset === 0) {
        this._directory.set(page);
      } else {
        this._directory.set([...this._directory(), ...page]);
      }
      this._lastPageHasMore.set(env.meta?.has_more ?? false);
      return page;
    } finally {
      this._isLoadingList.set(false);
    }
  }

  /** Featured stores (Store Spotlight). Stateless return. */
  async getFeatured(limit = 8): Promise<FeaturedVendor[]> {
    const env = await firstValueFrom(
      this.http.get<FeaturedVendor[]>('GET /featured-vendors', {
        query: { limit },
      }),
    );
    return Array.isArray(env.data) ? env.data : [];
  }

  /** Single store by slug. Throws on 404 (unknown / inactive). */
  async getBySlug(slug: string): Promise<Store> {
    const env = await firstValueFrom(
      this.http.get<Store>('GET /vendors/:slug', {
        params: { slug },
      }),
    );
    return env.data;
  }

  /** A page of a store's products. Stateless — the detail page
   *  owns the accumulator. */
  async listProducts(
    slug: string,
    params: StoreListParams = {},
  ): Promise<StoreProductsPage> {
    const limit = params.limit ?? DESIGNER_PAGE_SIZE;
    const offset = params.offset ?? 0;
    const env = await firstValueFrom(
      this.http.get<Product[]>('GET /vendors/:slug/products', {
        params: { slug },
        query: { limit, offset },
      }),
    );
    return {
      items: Array.isArray(env.data) ? env.data : [],
      hasMore: env.meta?.has_more ?? false,
    };
  }

  /**
   * A store's published size guide (public, by slug). Stateless — the PDP's
   * size-guide modal owns the result. Returns the rows as-is; an empty array
   * means the store has no chart (the modal shows its empty state). A 404
   * (unknown slug) propagates so the caller can degrade to the empty state.
   *
   * Mirrors the mobile size-chart sheet, which hits the by-legacy-id variant
   * of the same v3 endpoint.
   */
  async getSizeChart(slug: string): Promise<StoreSizeChartRow[]> {
    const env = await firstValueFrom(
      this.http.get<StoreSizeChartRow[]>('GET /vendors/:slug/size-chart', {
        params: { slug },
      }),
    );
    return Array.isArray(env.data) ? env.data : [];
  }

  /**
   * A page of a store's public (approved) reviews, by slug. Stateless — the
   * store-reviews page owns the accumulator. Mirrors the mobile store-reviews
   * list. `total` (from meta) drives hasMore so the page can load more until
   * everything is shown.
   */
  async listReviews(
    slug: string,
    params: StoreListParams = {},
  ): Promise<StoreReviewsPage> {
    const limit = params.limit ?? DESIGNER_PAGE_SIZE;
    const offset = params.offset ?? 0;
    const env = await firstValueFrom(
      this.http.get<StoreReview[]>('GET /vendors/:slug/reviews', {
        params: { slug },
        query: { limit, offset },
      }),
    );
    const items = Array.isArray(env.data) ? env.data : [];
    const total = env.meta?.total ?? offset + items.length;
    return {
      items,
      total,
      hasMore: offset + items.length < total,
    };
  }
}
