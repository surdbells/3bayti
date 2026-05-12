import { Injectable } from '@angular/core';

/**
 * Runtime API configuration.
 *
 * Three base URLs because the 10-day rollout uses a strangler-fig
 * migration (Decision 10):
 *
 *   - `v3BaseUrl`  — new Slim 4 backend (api-v3.3bayti.ae). Day 5+
 *                    onwards this serves catalog reads, auth, and
 *                    account. ENDPOINT_ROUTING in @3bayti/api-client
 *                    decides which endpoints use this.
 *   - `v2BaseUrl`  — legacy v2 public read API. Still used as fallback
 *                    if an endpoint flag is `'old'` for any reason
 *                    (none currently for the catalog area).
 *   - `v1BaseUrl`  — legacy v1 authenticated API. Used for cart,
 *                    checkout, wishlist until M3.
 *
 * The base URLs do NOT differ between dev/staging/prod — there's one
 * legacy host and one v3 host. Build-time env injection happens for
 * SITE_URL only (canonical site URL for sitemaps + OG tags), see
 * scripts/inject-environment.mjs.
 *
 * Note: this service is now consulted ONLY for legacy direct-v2 reads
 * (HomeDataService etc., until Phase 5.D refactors them away). New
 * code should go through RoutedHttpClient which picks the right base
 * URL itself based on ENDPOINT_ROUTING.
 */
@Injectable({ providedIn: 'root' })
export class ApiConfigService {
  /** Base URL for the new v3 Slim 4 backend. Catalog reads, auth, account. */
  readonly v3BaseUrl = 'https://api-v3.3bayti.ae';

  /** Base URL for the v2 (public read-only) API used by SEO pages. */
  readonly v2BaseUrl = 'https://api.3bayti.ae/v2';

  /** Base URL for the existing v1 (authenticated) API used by the mobile
   *  app. Web app uses this for cart, checkout, account flows in Phase 3. */
  readonly v1BaseUrl = 'https://api.3bayti.ae';
}
