import { Injectable, inject } from '@angular/core';
import { catchError, map, Observable, of } from 'rxjs';

import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type { Product } from '../catalog/product.model';
import type { FeaturedVendor } from '../catalog/store-card';
import type { ActiveCampaigns } from '../campaigns/campaign.model';

/**
 * HomeDataService, encapsulates the four data fetches the home page
 * needs (three product strips + the store spotlight).
 *
 * Why a service instead of fetching inline in HomeComponent:
 *   - Each strip + spotlight is independent, keeps HomeComponent slim
 *   - The four fetches share the same shape + graceful-degradation
 *     pattern; abstracting it here avoids repetition
 *   - Easy to mock in tests without monkey-patching components
 *
 * Routing
 * -------
 * All four endpoints go through RoutedHttpClient + ENDPOINT_ROUTING:
 *   - GET /products          -> v3 (api-v3.3bayti.ae/v3/products)
 *   - GET /featured-vendors  -> v3 (api-v3.3bayti.ae/v3/featured-vendors)
 *
 * Failure mode:
 *   Each method returns an Observable that emits an empty array on
 *   error. The home page degrades gracefully, a failed strip silently
 *   omits itself rather than showing a broken section. Errors still
 *   log to console (via RoutedHttpClient's handle()).
 */
@Injectable({ providedIn: 'root' })
export class HomeDataService {
  private routed = inject(RoutedHttpClient);

  /** Number of products per strip, matches the locked Phase 1 W2 spec. */
  private readonly STRIP_LIMIT = 12;

  /** Number of vendors in the Store Spotlight. */
  private readonly SPOTLIGHT_LIMIT = 12;

  /**
   * Featured products, the curated "this week's edit" strip.
   * Backed by /products?sort=featured (v3 computes featured from
   * top-rated vendors + recent products + a small randomness factor).
   */
  featuredProducts$(): Observable<Product[]> {
    return this.withFallback(
      this.routed
        .get<Product[]>('GET /products', { query: { sort: 'featured', limit: this.STRIP_LIMIT } })
        .pipe(map(env => env.data)),
    );
  }

  /**
   * Best sellers, backed by /products?sort=popular (v3 counts
   * cart-add events per product as a popularity proxy).
   */
  bestSellers$(): Observable<Product[]> {
    return this.withFallback(
      this.routed
        .get<Product[]>('GET /products', { query: { sort: 'popular', limit: this.STRIP_LIMIT } })
        .pipe(map(env => env.data)),
    );
  }

  /**
   * New arrivals, backed by /products?sort=newest (product_id DESC,
   * which is roughly chronological since IDs are auto-incremented).
   */
  newArrivals$(): Observable<Product[]> {
    return this.withFallback(
      this.routed
        .get<Product[]>('GET /products', { query: { sort: 'newest', limit: this.STRIP_LIMIT } })
        .pipe(map(env => env.data)),
    );
  }

  /**
   * Trending now, the GUEST fallback for the personalized "For you" strip.
   *
   * Anonymous visitors don't get recommendations (the engine is auth-gated),
   * which previously left the "For you" slot empty for everyone signed-out.
   * To keep the page from going sparse, guests instead see a "Trending now"
   * strip sourced from the editorial `sort=featured` ranking.
   *
   * This source is deliberately DISTINCT from the two nearby strips so the
   * page doesn't repeat itself:
   *   - Top Sellers uses `sort=popular` (cart-add popularity proxy)
   *   - New Arrivals uses `sort=newest` (chronological)
   *   - Trending uses `sort=featured` (curated: top-rated vendors + recency)
   *
   * Note: the hero carousel also draws from `featured`, but it only surfaces
   * a small rotating subset visually, so a fuller trending strip still reads
   * as new content rather than a duplicate of the hero.
   */
  trending$(): Observable<Product[]> {
    return this.withFallback(
      this.routed
        .get<Product[]>('GET /products', { query: { sort: 'featured', limit: this.STRIP_LIMIT } })
        .pipe(map(env => env.data)),
    );
  }

  /**
   * Featured vendors, on /v3/featured-vendors per M3.2.X.2. Each vendor
   * comes with up to 4 embedded product thumbnails for the Store
   * Spotlight section. Backend computes rating aggregate + alphabetical-
   * by-name ordering.
   *
   * Curation: admin flags vendors via PUT /v3/admin/vendors/{id} with
   * { is_featured: true }. Empty curation returns 200 with data: [] -
   * handled by the catchError fallback (the strip hides silently).
   */
  featuredVendors$(): Observable<FeaturedVendor[]> {
    return this.withFallback(
      this.routed
        .get<FeaturedVendor[]>('GET /featured-vendors', { query: { limit: this.SPOTLIGHT_LIMIT } })
        .pipe(map(env => env.data)),
    );
  }

  /**
   * Active campaigns, the live Anniversary Deals + Flash Sale for the
   * homepage. Unlike the strips, the payload is an object (not a list),
   * so it falls back to null (not []) on error/none, each section hides
   * itself when its campaign is null.
   */
  activeCampaigns$(): Observable<ActiveCampaigns | null> {
    return this.routed
      .get<ActiveCampaigns>('GET /campaigns/active')
      .pipe(
        map(env => env.data),
        catchError(() => of(null)),
      );
  }

  /* ----- Internal -----------------------------------------------------------
   * Graceful degradation: any fetch error becomes an empty array so a
   * failed strip silently omits itself rather than showing broken UI.
   * The error is already logged by RoutedHttpClient's handle(). */
  private withFallback<T>(fetch$: Observable<T[]>): Observable<T[]> {
    return fetch$.pipe(catchError(() => of([] as T[])));
  }
}
