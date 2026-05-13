import { Injectable } from '@angular/core';

/**
 * Runtime API configuration.
 *
 * Four base URLs because the 10-day rollout uses a strangler-fig
 * migration (Decision 10):
 *
 *   - `v3BaseUrl`     — new Slim 4 backend (api-v3.3bayti.ae). Day 5+
 *                       onwards this serves catalog reads, auth, and
 *                       account. ENDPOINT_ROUTING in @3bayti/api-client
 *                       decides which endpoints use this.
 *
 *   - `legacyBaseUrl` — legacy host with NO version prefix
 *                       (https://api.3bayti.ae). Used by
 *                       RoutedHttpClient as `bases.old`. ENDPOINT_ROUTING
 *                       entries include the version segment in their
 *                       oldPath (e.g. '/v2/featured-vendors',
 *                       '/users/login'), so we don't want
 *                       v2BaseUrl's '/v2' suffix here — that would
 *                       produce a double-prefix URL.
 *
 *   - `v2BaseUrl`     — legacy v2 base WITH '/v2' suffix
 *                       (https://api.3bayti.ae/v2). Still used by the
 *                       deprecated ApiClientService for any consumer
 *                       not yet migrated to RoutedHttpClient. New code
 *                       should NOT use this — use RoutedHttpClient
 *                       instead.
 *
 *   - `v1BaseUrl`     — same host as `legacyBaseUrl` but kept under
 *                       the old name because mobile-app code (Day 6+)
 *                       references it. Will consolidate post-cutover.
 *
 * The base URLs do NOT differ between dev/staging/prod — there's one
 * legacy host and one v3 host. Build-time env injection happens for
 * SITE_URL only (canonical site URL for sitemaps + OG tags), see
 * scripts/inject-environment.mjs.
 */
@Injectable({ providedIn: 'root' })
export class ApiConfigService {
  /** Base URL for the new v3 Slim 4 backend. Catalog reads, auth, account. */
  readonly v3BaseUrl = 'https://api-v3.3bayti.ae';

  /**
   * Legacy host without any version path. This is what
   * RoutedHttpClient passes as `bases.old` to resolveUrl(), because
   * ENDPOINT_ROUTING entries already include the version segment in
   * their oldPath (e.g. `/v2/products`, `/users/login`).
   */
  readonly legacyBaseUrl = 'https://api.3bayti.ae';

  /**
   * @deprecated for new code. Used by the deprecated ApiClientService.
   * RoutedHttpClient consumers should rely on `legacyBaseUrl` instead.
   */
  readonly v2BaseUrl = 'https://api.3bayti.ae/v2';

  /** Base URL for the existing v1 (authenticated) API used by the mobile
   *  app. Web app uses this for cart, checkout, account flows in Phase 3. */
  readonly v1BaseUrl = 'https://api.3bayti.ae';
}
