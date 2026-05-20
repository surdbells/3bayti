import { Injectable, signal, Signal, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { AuthService } from '../../core/auth/auth.service';
import type { AuthUser } from '../../core/auth/auth.types';

/**
 * Fields the customer can edit on their profile via PATCH /me/profile.
 * Mirrors UpdateProfileInput in apps/api exactly:
 *   - first_name, last_name (max 100)
 *   - gender (male | female | other | prefer_not_to_say)
 *   - dob (YYYY-MM-DD)
 *   - locale (en | ar | en-AE | ar-AE)
 *
 * NOT editable here (deliberately omitted to match the API):
 *   - email / phone — changed via dedicated verified-contact flows,
 *     not this endpoint. Shown read-only on the profile page.
 *   - timezone — the API accepts it but the web app derives it; no
 *     UI control for it.
 *
 * RFC 7396 JSON Merge Patch: only the keys present in the body are
 * updated; omitted keys are untouched; an explicit null clears a
 * field. So this is a Partial — callers send only what changed.
 */
export type ProfileGender = 'male' | 'female' | 'other' | 'prefer_not_to_say';

export interface ProfileUpdate {
  first_name?: string | null;
  last_name?: string | null;
  gender?: ProfileGender | null;
  /** ISO 8601 date YYYY-MM-DD, or null to clear. */
  dob?: string | null;
  locale?: string | null;
}

/**
 * ProfileService — read + partial-update of the authenticated user's
 * profile. Public-ish account read, but auth-gated; routed through
 * RoutedHttpClient (which carries the bearer via the auth interceptor)
 * for consistency with the rest of the catalog/account reads.
 *
 * Endpoints:
 *   GET   /v3/me/profile   → { user: AuthUser }
 *   PATCH /v3/me/profile   → { user: AuthUser }   (JSON Merge Patch)
 *
 * On a successful update the fresh user is pushed into AuthService so
 * the header + any other currentUser consumers reflect the change
 * without a separate /me re-fetch.
 */
@Injectable({ providedIn: 'root' })
export class ProfileService {
  private readonly http = inject(RoutedHttpClient);
  private readonly auth = inject(AuthService);

  private readonly _isLoading = signal<boolean>(false);
  private readonly _isSaving = signal<boolean>(false);
  readonly isLoading: Signal<boolean> = this._isLoading.asReadonly();
  readonly isSaving: Signal<boolean> = this._isSaving.asReadonly();

  /** Fetch the current user's profile. */
  async getProfile(): Promise<AuthUser> {
    this._isLoading.set(true);
    try {
      const env = await firstValueFrom(
        this.http.get<{ user: AuthUser }>('GET /me/profile'),
      );
      return env.data.user;
    } finally {
      this._isLoading.set(false);
    }
  }

  /**
   * Partial-update the profile. Sends only the provided keys (Merge
   * Patch). Returns the updated user and syncs it into AuthService.
   */
  async updateProfile(patch: ProfileUpdate): Promise<AuthUser> {
    this._isSaving.set(true);
    try {
      const env = await firstValueFrom(
        this.http.patch<{ user: AuthUser }>('PATCH /me/profile', {
          body: patch,
        }),
      );
      const user = env.data.user;
      this.auth.applyProfile(user);
      return user;
    } finally {
      this._isSaving.set(false);
    }
  }
}
