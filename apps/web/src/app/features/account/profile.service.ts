import { Injectable, signal, Signal, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { AuthService } from '../../core/auth/auth.service';
import type { AuthUser } from '../../core/auth/auth.types';

/** Direct v3 base for endpoints not in the RoutedHttpClient registry. */
const V3_BASE = 'https://api-v3.3bayti.ae';

/**
 * Fields the customer can edit on their profile via PATCH /me/profile.
 * Mirrors UpdateProfileInput in apps/api exactly:
 *   - first_name, last_name (max 100)
 *   - gender (male | female | other | prefer_not_to_say)
 *   - dob (YYYY-MM-DD)
 *   - locale (en | ar | en-AE | ar-AE)
 *
 * NOT editable here (deliberately omitted to match the API):
 *   - email / phone, changed via dedicated verified-contact flows,
 *     not this endpoint. Shown read-only on the profile page.
 *   - timezone, the API accepts it but the web app derives it; no
 *     UI control for it.
 *
 * RFC 7396 JSON Merge Patch: only the keys present in the body are
 * updated; omitted keys are untouched; an explicit null clears a
 * field. So this is a Partial, callers send only what changed.
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
 * Response from PATCH /me/password. The endpoint revokes all sessions
 * and issues a fresh token pair (see ProfileService.changePassword).
 * Mirrors apps/api ChangePasswordController's ok([...]) body.
 */
export interface ChangePasswordResponse {
  access_token: string;
  access_token_expires_at: string;
  refresh_token: string;
  refresh_token_expires_at: string;
  user: AuthUser;
}

/**
 * ProfileService, read + partial-update of the authenticated user's
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
  private readonly directHttp = inject(HttpClient);
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

  /**
   * Change the account password.
   *
   * The endpoint re-authenticates via current_password, revokes ALL of
   * the user's refresh tokens (including this session's), and returns a
   * FRESH token pair. We adopt it via AuthService.applyPasswordChange
   * so the current session survives the revocation, otherwise the
   * very next request would fail with a revoked token.
   */
  async changePassword(currentPassword: string, newPassword: string): Promise<void> {
    this._isSaving.set(true);
    try {
      const env = await firstValueFrom(
        this.http.patch<ChangePasswordResponse>('PATCH /me/password', {
          body: { current_password: currentPassword, new_password: newPassword },
        }),
      );
      this.auth.applyPasswordChange({
        access_token: env.data.access_token,
        access_token_expires_at: env.data.access_token_expires_at,
        user: env.data.user,
      });
    } finally {
      this._isSaving.set(false);
    }
  }

  /**
   * Upload (or replace) the authenticated user's avatar.
   *
   * POST /v3/me/avatar with a base64 data-URL body { image }. The
   * endpoint stores the image on public Flysystem storage and returns
   * the resulting { data: { avatar_url } }. We push the new URL into
   * the cached auth user (via applyProfile) so the header user-menu and
   * any other avatar consumers update immediately, with no /me refetch.
   *
   * Uses the direct Bearer HttpClient (auth attached by interceptor)
   * because this endpoint isn't in the RoutedHttpClient registry -
   * same approach as deleteAccount.
   */
  async uploadAvatar(dataUrl: string): Promise<string> {
    this._isSaving.set(true);
    try {
      const res = await firstValueFrom(
        this.directHttp.post<{ data: { avatar_url: string } }>(
          `${V3_BASE}/v3/me/avatar`,
          { image: dataUrl },
        ),
      );
      const avatarUrl = res.data.avatar_url;
      const current = this.auth.currentUser();
      if (current !== null) {
        this.auth.applyProfile({ ...current, avatar_url: avatarUrl });
      }
      return avatarUrl;
    } finally {
      this._isSaving.set(false);
    }
  }

  /**
   * Delete the authenticated user's account.
   *
   * DELETE /v3/me with a current_password body (re-auth, Q6.2). The
   * endpoint deactivates + soft-deletes the user and revokes all
   * sessions server-side, returning 204. A wrong password is 401
   * AUTH_INVALID_CREDENTIALS.
   *
   * Uses the direct Bearer HttpClient (auth attached by interceptor)
   * because the endpoint isn't in the RoutedHttpClient registry and a
   * DELETE with a request body is needed. The caller is responsible
   * for the local logout + redirect once this resolves.
   */
  async deleteAccount(currentPassword: string): Promise<void> {
    this._isSaving.set(true);
    try {
      await firstValueFrom(
        this.directHttp.delete<void>(`${V3_BASE}/v3/me`, {
          body: { current_password: currentPassword },
        }),
      );
    } finally {
      this._isSaving.set(false);
    }
  }
}
