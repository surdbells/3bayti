import {
  Injectable,
  inject,
  PLATFORM_ID,
  TransferState,
  makeStateKey,
} from '@angular/core';
import { isPlatformServer } from '@angular/common';
import { catchError, map, Observable, of, tap } from 'rxjs';

import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type { Product } from '../catalog/product.model';
import type { FeaturedVendor } from '../catalog/designer-card';

/**
 * HomeDataService — encapsulates the four data fetches the home page
 * needs. Each call uses TransferState so SSR-prerendered data is reused
 * by the browser after hydration (no re-fetch).
 *
 * Why a service instead of fetching inline in HomeComponent:
 *   - Each strip + spotlight is independent — keeps HomeComponent slim
 *   - The four fetches all use the same SSR/cache pattern; abstracting
 *     it here avoids repetition
 *   - Easy to mock in tests (Phase 9) without monkey-patching components
 *
 * Routing
 * -------
 * All four endpoints go through RoutedHttpClient + ENDPOINT_ROUTING:
 *   - GET /products          -> v3 (api-v3.3bayti.ae/v3/products)
 *   - GET /featured-vendors  -> v3 (api-v3.3bayti.ae/v3/featured-vendors)
 *     (was on legacy v2 until M3.2.X.2; flipped to v3 once the
 *     curation endpoint shipped with the embedded-products shape.)
 *
 * Failure mode:
 *   Each method returns an Observable that emits an empty array on
 *   error. The home page degrades gracefully — a failed strip silently
 *   omits itself rather than showing a broken section. Errors still
 *   log to console (via RoutedHttpClient's handle()).
 */
@Injectable({ providedIn: 'root' })
export class HomeDataService {
  private routed = inject(RoutedHttpClient);
  private state = inject(TransferState);
  private platformId = inject(PLATFORM_ID);

  /** Number of products per strip — matches the locked Phase 1 W2 spec. */
  private readonly STRIP_LIMIT = 12;

  /** Number of vendors in the Designer Spotlight. */
  private readonly SPOTLIGHT_LIMIT = 4;

  /* ----- TransferState keys ------------------------------------------------
   * Stable string keys so the SSR-rendered HTML and the browser-side
   * deserialiser agree on what to look up. Each strip gets its own key —
   * a single combined key would conflate failures (one bad strip would
   * blank all of them on cache miss). */

  private readonly KEY_FEATURED      = makeStateKey<Product[]>('home-featured-products');
  private readonly KEY_BEST_SELLERS  = makeStateKey<Product[]>('home-best-sellers');
  private readonly KEY_NEW_ARRIVALS  = makeStateKey<Product[]>('home-new-arrivals');
  private readonly KEY_FEATURED_VEND = makeStateKey<FeaturedVendor[]>('home-featured-vendors');

  /**
   * Featured products — the curated "this week's edit" strip.
   * Backed by /products?sort=featured (v3 computes featured from
   * top-rated vendors + recent products + a small randomness factor).
   */
  featuredProducts$(): Observable<Product[]> {
    return this.cached(this.KEY_FEATURED, () =>
      this.routed
        .get<Product[]>('GET /products', { query: { sort: 'featured', limit: this.STRIP_LIMIT } })
        .pipe(map(env => env.data))
    );
  }

  /**
   * Best sellers — backed by /products?sort=popular (v3 counts
   * cart-add events per product as a popularity proxy).
   */
  bestSellers$(): Observable<Product[]> {
    return this.cached(this.KEY_BEST_SELLERS, () =>
      this.routed
        .get<Product[]>('GET /products', { query: { sort: 'popular', limit: this.STRIP_LIMIT } })
        .pipe(map(env => env.data))
    );
  }

  /**
   * New arrivals — backed by /products?sort=newest (product_id DESC,
   * which is roughly chronological since IDs are auto-incremented).
   */
  newArrivals$(): Observable<Product[]> {
    return this.cached(this.KEY_NEW_ARRIVALS, () =>
      this.routed
        .get<Product[]>('GET /products', { query: { sort: 'newest', limit: this.STRIP_LIMIT } })
        .pipe(map(env => env.data))
    );
  }

  /**
   * Featured vendors — now on /v3/featured-vendors per M3.2.X.2.
   * Each vendor comes with up to 4 embedded product thumbnails for
   * the Designer Spotlight section. Backend computes rating aggregate
   * + alphabetical-by-name ordering.
   *
   * Curation: admin flags vendors via PUT /v3/admin/vendors/{id}
   * with { is_featured: true }. Empty curation returns 200 with
   * `data: []` (apps/web's catchError handles this by hiding the
   * strip silently).
   */
  featuredVendors$(): Observable<FeaturedVendor[]> {
    return this.cached(this.KEY_FEATURED_VEND, () =>
      this.routed
        .get<FeaturedVendor[]>('GET /featured-vendors', { query: { limit: this.SPOTLIGHT_LIMIT } })
        .pipe(map(env => env.data))
    );
  }

  /* ----- Internal: TransferState-cached fetcher --------------------------
   *
   * The pattern, identical for all four methods:
   *   1. Browser side: check if SSR seeded the cache. If yes, use it.
   *      No HTTP call. (After Angular hydrates the page, the browser
   *      runs the same component code; we reuse the data the server
   *      already fetched, so no double-fetch.)
   *   2. Cache miss (or SSR side): call the actual fetcher.
   *   3. SSR-side success: write the result into TransferState. The
   *      Angular SSR renderer emits this into a <script id="ng-state">
   *      tag in the prerendered HTML so the browser can pick it up.
   *   4. Errors: degrade to empty array — strip silently omits itself
   *      rather than showing broken UI. Console error already logged
   *      by RoutedHttpClient.
   */
  private cached<T>(
    key: ReturnType<typeof makeStateKey<T[]>>,
    fetch: () => Observable<T[]>,
  ): Observable<T[]> {
    const cached = this.state.get(key, null);
    if (cached !== null) {
      return of(cached);
    }
    return fetch().pipe(
      tap(value => {
        if (isPlatformServer(this.platformId)) {
          this.state.set(key, value);
        }
      }),
      catchError(() => of([] as T[])),
    );
  }
}
