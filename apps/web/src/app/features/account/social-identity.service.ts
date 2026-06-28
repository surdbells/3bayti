import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type { SocialProvider } from '../../core/auth/social-auth.service';

/**
 * One connected provider on the current account. Mirrors
 * ListSocialIdentitiesController's per-entry shape.
 */
export interface SocialIdentity {
  provider: SocialProvider;
  email: string | null;
  /** ISO 8601 ATOM. */
  created_at: string;
}

/**
 * SocialIdentityService — read + link + unlink the current user's connected
 * Google/Apple accounts. Backs the "Connected accounts" account page.
 *
 * All three are Bearer-auth /me calls routed through RoutedHttpClient (the
 * access-token interceptor attaches Authorization) — NOT the BFF, which is
 * only for the cookie-bound refresh model.
 *
 * Endpoints:
 *   GET    /v3/me/social-identities            → [{ provider, email, created_at }]
 *   POST   /v3/me/social-identities { id_token } → 201 (409 if linked elsewhere)
 *   DELETE /v3/me/social-identities/:provider   → 204 (422 last-method guard)
 */
@Injectable({ providedIn: 'root' })
export class SocialIdentityService {
  private readonly http = inject(RoutedHttpClient);

  /** List the current user's connected accounts. */
  async list(): Promise<SocialIdentity[]> {
    const env = await firstValueFrom(
      this.http.get<SocialIdentity[]>('GET /me/social-identities'),
    );
    return env.data;
  }

  /**
   * Link a fresh provider sign-in (Firebase ID token) to the current user.
   * The caller obtains the id_token via SocialAuthService's popup. A 409
   * (CONFLICT_DUPLICATE — token belongs to another account) propagates as an
   * HttpErrorResponse for the page to map.
   */
  async link(idToken: string): Promise<SocialIdentity> {
    const env = await firstValueFrom(
      this.http.post<SocialIdentity>('POST /me/social-identities', {
        body: { id_token: idToken },
      }),
    );
    return env.data;
  }

  /**
   * Unlink a provider. A 422 (BUSINESS_RULE_VIOLATION — last sign-in method)
   * or 404 (no such link) propagates for the page to map.
   */
  async unlink(provider: SocialProvider): Promise<void> {
    await firstValueFrom(
      this.http.delete<void>('DELETE /me/social-identities/:provider', {
        params: { provider },
      }),
    );
  }
}
