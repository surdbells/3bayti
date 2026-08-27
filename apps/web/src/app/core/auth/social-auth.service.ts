import { Injectable } from '@angular/core';
import {
  signInWithPopup,
  GoogleAuthProvider,
  OAuthProvider,
  type UserCredential,
} from 'firebase/auth';
import { getFirebaseAuth, isFirebaseConfigured } from '../firebase/firebase.init';

/** Social provider keys understood by the API (/v3/auth/social). */
export type SocialProvider = 'google' | 'apple';

/**
 * Outcome of a social-popup sign-in attempt. The popup happy-path yields an
 * id_token (a Firebase ID token, NOT the provider's raw token) which the BFF
 * exchanges at /v3/auth/social. Friendly typed failures let the UI stay quiet
 * on a user-cancelled popup and surface a toast for everything else.
 */
export type SocialIdTokenResult =
  | { ok: true; idToken: string; firstName: string | null; lastName: string | null }
  | { ok: false; reason: 'cancelled' | 'unavailable' | 'popup-blocked' | 'failed' };

/**
 * SocialAuthService, thin wrapper over Firebase Auth popups.
 *
 * Responsibilities
 * ----------------
 *   - getGoogleIdToken(): signInWithPopup(GoogleAuthProvider) → Firebase ID token.
 *   - getAppleIdToken():  signInWithPopup(OAuthProvider('apple.com')) → ID token.
 *
 * It does NOT talk to our API: the returned ID token is handed to
 * AuthService.loginWithProvider(), which POSTs it to the BFF /auth-proxy/social
 * (the BFF owns the refresh cookie). This service has a single concern: drive
 * the Firebase popup and normalise its many error modes to a typed result.
 *
 * Error mapping
 * -------------
 * Firebase throws `{ code }` errors. We map the user-initiated /
 * non-actionable ones to friendly reasons so the page doesn't toast on a
 * deliberately-closed popup:
 *   auth/popup-closed-by-user, auth/cancelled-popup-request,
 *   auth/user-cancelled                → 'cancelled' (silent)
 *   auth/popup-blocked                 → 'popup-blocked'
 *   (no firebase config / no Auth)     → 'unavailable'
 *   anything else                      → 'failed'
 */
@Injectable({ providedIn: 'root' })
export class SocialAuthService {
  /** True when a Firebase config is present, gate the UI buttons on this. */
  readonly isAvailable = isFirebaseConfigured();

  /** Google popup → Firebase ID token. */
  async getGoogleIdToken(): Promise<SocialIdTokenResult> {
    return this.runPopup(() => {
      const provider = new GoogleAuthProvider();
      /* Always show the account chooser rather than silently reusing the
         last Google session, matches user expectation for "Continue with". */
      provider.setCustomParameters({ prompt: 'select_account' });
      return provider;
    });
  }

  /** Apple popup → Firebase ID token. */
  async getAppleIdToken(): Promise<SocialIdTokenResult> {
    return this.runPopup(() => {
      const provider = new OAuthProvider('apple.com');
      /* Apple only returns name/email on the FIRST authorisation; request the
         scopes so a brand-new account can be created with a display name. */
      provider.addScope('email');
      provider.addScope('name');
      return provider;
    });
  }

  /* ---------- Internals ---------- */

  private async runPopup(
    buildProvider: () => GoogleAuthProvider | OAuthProvider,
  ): Promise<SocialIdTokenResult> {
    const auth = getFirebaseAuth();
    if (auth === null) {
      return { ok: false, reason: 'unavailable' };
    }

    try {
      const credential: UserCredential = await signInWithPopup(auth, buildProvider());
      const idToken = await credential.user.getIdToken();
      const { firstName, lastName } = splitDisplayName(credential.user.displayName);
      return { ok: true, idToken, firstName, lastName };
    } catch (err) {
      return { ok: false, reason: mapPopupError(err) };
    }
  }
}

/** Split a Firebase displayName ("Jane Doe") into first/last for /auth/social. */
function splitDisplayName(displayName: string | null): {
  firstName: string | null;
  lastName: string | null;
} {
  const trimmed = (displayName ?? '').trim();
  if (trimmed === '') return { firstName: null, lastName: null };
  const parts = trimmed.split(/\s+/);
  const firstName = parts[0] ?? null;
  const lastName = parts.length > 1 ? parts.slice(1).join(' ') : null;
  return { firstName, lastName };
}

/** Firebase popup error code → friendly reason. */
function mapPopupError(err: unknown): 'cancelled' | 'unavailable' | 'popup-blocked' | 'failed' {
  const code =
    typeof err === 'object' && err !== null && 'code' in err
      ? String((err as { code: unknown }).code)
      : '';
  switch (code) {
    case 'auth/popup-closed-by-user':
    case 'auth/cancelled-popup-request':
    case 'auth/user-cancelled':
      return 'cancelled';
    case 'auth/popup-blocked':
      return 'popup-blocked';
    case 'auth/operation-not-allowed':
    case 'auth/unauthorized-domain':
      return 'unavailable';
    default:
      return 'failed';
  }
}
