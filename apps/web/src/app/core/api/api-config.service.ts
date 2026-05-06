import { Injectable } from '@angular/core';

/**
 * Runtime API configuration.
 *
 * The base URL differs across environments (dev / staging / prod). For
 * SSR we read from `process.env` server-side; for browser builds we use
 * an Angular env file.
 *
 * Phase 1 keeps it simple — production hardcoded, no env file plumbing.
 * Phase 2 adds proper environment.ts switching for dev/staging/prod.
 */
@Injectable({ providedIn: 'root' })
export class ApiConfigService {
  /** Base URL for the v2 (public read-only) API used by SEO pages. */
  readonly v2BaseUrl = 'https://api.3bayti.ae/v2';

  /** Base URL for the existing v1 (authenticated) API used by the mobile
   *  app. Web app uses this for cart, checkout, account flows in Phase 3. */
  readonly v1BaseUrl = 'https://api.3bayti.ae';
}
