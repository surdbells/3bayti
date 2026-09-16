import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type { Product } from '../catalog/product.model';

/** One personalised rail: a stable key + validated product cards. */
export interface ForYouRail {
  /** Machine key (your_style / stores_you_love / because_you_liked / new_for_you / popular). */
  key: string;
  /** Present only on the "because you liked…" rail — the seed product name. */
  seedName?: string;
  products: Product[];
}

export interface ForYouRails {
  /** False when the popular cold-start fallback was used (no profile yet). */
  profileReady: boolean;
  rails: ForYouRail[];
}

interface ForYouResponse {
  profile_ready?: boolean;
  rails?: Array<{ key?: string; seed_name?: string; products?: Product[] }>;
}

/**
 * Ain Personal Style Profile — the personalised "For You / Your Style" rails
 * (GET /v3/me/ai/for-you, authenticated). Every product is already validated
 * server-side against live inventory. Degrades to an empty set on any error so
 * the home page never breaks.
 */
@Injectable({ providedIn: 'root' })
export class ForYouService {
  private readonly http = inject(RoutedHttpClient);

  async rails(limit = 12): Promise<ForYouRails> {
    try {
      const res = await firstValueFrom(
        this.http.get<ForYouResponse>('GET /me/ai/for-you', { query: { limit } }),
      );
      const data = res.data ?? {};
      const rails: ForYouRail[] = Array.isArray(data.rails)
        ? data.rails
            .map((r) => ({
              key: typeof r.key === 'string' ? r.key : '',
              seedName: typeof r.seed_name === 'string' ? r.seed_name : undefined,
              products: Array.isArray(r.products) ? r.products : [],
            }))
            .filter((r) => r.key !== '' && r.products.length > 0)
        : [];
      return { profileReady: data.profile_ready === true, rails };
    } catch {
      return { profileReady: false, rails: [] };
    }
  }
}
