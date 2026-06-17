import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type { Product } from '../catalog/product.model';
import type { DirectoryStore } from '../catalog/store.model';

/** Grouped results for the global search overlay. */
export interface SearchResults {
  products: Product[];
  stores: DirectoryStore[];
}

/** Default cap per group in the overlay (keeps the typeahead compact). */
export const SEARCH_GROUP_LIMIT = 6;

/**
 * SearchService — powers the global header search overlay (Stores H2.C).
 *
 * A query fans out to two public catalog reads in PARALLEL:
 *   GET /products?q=…  (ListProductsController name search)
 *   GET /vendors?q=…   (ListVendorsController name search — Stores H2.A;
 *                       returns directoryShape, so each store row has its
 *                       logo + rating + product thumbnails available)
 * and returns both groups. Public reads go through RoutedHttpClient (same
 * as home-data / store services), NOT the Bearer-bound direct client used
 * for cart/orders.
 *
 * This service is a stateless fetch: debouncing, request sequencing, and
 * "ignore stale responses" logic live in the overlay component that owns
 * the input.
 */
@Injectable({ providedIn: 'root' })
export class SearchService {
  private readonly http = inject(RoutedHttpClient);

  /**
   * Search products + stores for `query`. Blank/whitespace-only queries
   * short-circuit to empty groups WITHOUT hitting the API (the overlay
   * calls this on every keystroke). `limit` caps each group.
   */
  async search(query: string, limit = SEARCH_GROUP_LIMIT): Promise<SearchResults> {
    const q = query.trim();
    if (q === '') {
      return { products: [], stores: [] };
    }

    const [products, stores] = await Promise.all([
      firstValueFrom(
        this.http.get<Product[]>('GET /products', { query: { q, limit } }),
      ).then((env) => (Array.isArray(env.data) ? env.data : [])),
      firstValueFrom(
        this.http.get<DirectoryStore[]>('GET /vendors', { query: { q, limit } }),
      ).then((env) => (Array.isArray(env.data) ? env.data : [])),
    ]);

    return { products, stores };
  }
}
