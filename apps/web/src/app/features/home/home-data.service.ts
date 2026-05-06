import {
  Injectable,
  inject,
  PLATFORM_ID,
  TransferState,
  makeStateKey,
} from '@angular/core';
import { isPlatformServer } from '@angular/common';
import { catchError, map, Observable, of, tap } from 'rxjs';

import { ApiClientService } from '../../core/api/api-client.service';
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
 * Failure mode:
 *   Each method returns an Observable that emits an empty array on
 *   error. The home page degrades gracefully — a failed strip silently
 *   omits itself rather than showing a broken section. Errors still
 *   log to console (via ApiClient's handle()).
 */
@Injectable({ providedIn: 'root' })
export class HomeDataService {
  private api = inject(ApiClientService);
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
   * Backed by /v2/products?sort=featured (computed: top-rated vendors,
   * recent products, with a small randomness component).
   */
  featuredProducts$(): Observable<Product[]> {
    return this.cached(this.KEY_FEATURED, () =>
      this.api.getList<Product>('/products', { sort: 'featured', limit: this.STRIP_LIMIT })
        .pipe(map(env => env.data))
    );
  }

  /**
   * Best sellers — backed by /v2/products?sort=popular (which counts
   * cart-add events per product as a popularity proxy).
   */
  bestSellers$(): Observable<Product[]> {
    return this.cached(this.KEY_BEST_SELLERS, () =>
      this.api.getList<Product>('/products', { sort: 'popular', limit: this.STRIP_LIMIT })
        .pipe(map(env => env.data))
    );
  }

  /**
   * New arrivals — backed by /v2/products?sort=newest (product_id DESC,
   * which is roughly chronological since IDs are auto-incremented).
   */
  newArrivals$(): Observable<Product[]> {
    return this.cached(this.KEY_NEW_ARRIVALS, () =>
      this.api.getList<Product>('/products', { sort: 'newest', limit: this.STRIP_LIMIT })
        .pipe(map(env => env.data))
    );
  }

  /**
   * Featured vendors — backed by /v2/featured-vendors. Each vendor
   * comes with up to 4 embedded product thumbnails for the
   * Designer Spotlight section.
   */
  featuredVendors$(): Observable<FeaturedVendor[]> {
    return this.cached(this.KEY_FEATURED_VEND, () =>
      /* /v2/featured-vendors uses a slightly different envelope shape
         from the standard list endpoints — meta has { count, limit,
         per_vendor } rather than the standard pagination meta. We
         use api.get<T>() with manual unwrapping since getList expects
         pagination meta. */
      this.api.getList<FeaturedVendor>('/featured-vendors', { limit: this.SPOTLIGHT_LIMIT })
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
   *      by ApiClient.
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
