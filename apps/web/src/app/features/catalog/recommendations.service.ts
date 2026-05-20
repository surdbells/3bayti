import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type { Product } from './product.model';

/**
 * A single recommendation row: the recommended product plus the
 * metadata the backend used to rank it. `source` distinguishes how the
 * recommendation was derived so the UI can (optionally) label it:
 *   - 'copurchase'       — customers who bought X also bought Y
 *   - 'category'         — same-category fallback
 *   - 'fallback_popular' — popular products when nothing pre-computed
 */
export interface Recommendation {
  product: Product;
  score: string;
  source: 'copurchase' | 'category' | 'fallback_popular' | string;
}

/**
 * RecommendationsService — storefront reads for product recommendations
 * (M3.2.W.1). Consumes the X.12 backend:
 *
 *   GET /v3/products/:slug/recommendations?limit=N  (public)
 *   GET /v3/me/recommendations?limit=N              (authenticated)
 *
 * Both are catalog reads, so they go through RoutedHttpClient (like
 * DesignerService / home-data.service.ts), NOT the Bearer-bound direct
 * client. The personalized endpoint is auth-gated server-side; on the
 * web it is only called for signed-in users, and any 401 degrades to an
 * empty list rather than surfacing an error (recommendations are a
 * progressive enhancement, never a hard dependency of the page).
 *
 * The service is stateless: each page that shows a strip owns its own
 * result (two product pages can be open in different tabs), exactly as
 * the product-detail page owns its product.
 */
@Injectable({ providedIn: 'root' })
export class RecommendationsService {
  private readonly http = inject(RoutedHttpClient);

  /** Default strip size; the backend clamps to [3, 20]. */
  static readonly DEFAULT_LIMIT = 10;

  /**
   * "You may also like" for a given product slug. Public.
   * Returns [] on any failure (recommendations never block the PDP).
   */
  async forProduct(slug: string, limit = RecommendationsService.DEFAULT_LIMIT): Promise<Recommendation[]> {
    if (slug === '') {
      return [];
    }
    try {
      const res = await firstValueFrom(
        this.http.get<Recommendation[]>('GET /products/:slug/recommendations', {
          params: { slug },
          query: { limit },
        }),
      );
      return Array.isArray(res.data) ? res.data : [];
    } catch {
      return [];
    }
  }

  /**
   * Personalized "for you" recommendations for the signed-in user.
   * Caller must only invoke this when authenticated; a 401/any error
   * degrades to [].
   */
  async forMe(limit = RecommendationsService.DEFAULT_LIMIT): Promise<Recommendation[]> {
    try {
      const res = await firstValueFrom(
        this.http.get<Recommendation[]>('GET /me/recommendations', {
          query: { limit },
        }),
      );
      return Array.isArray(res.data) ? res.data : [];
    } catch {
      return [];
    }
  }
}
