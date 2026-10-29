import { Injectable } from '@angular/core';
import { Preferences } from '@capacitor/preferences';
import { NavController } from '@ionic/angular/standalone';
import { filter, timeout } from 'rxjs';

import { MobileNetworkAdapter } from '../http/mobile-network-adapter';
import { PushManager } from './push-manager.service';
import { LocalCartService } from './local-cart.service';
import { PendingOrdersService } from './pending-orders.service';
import { CartCountService } from './cart-count.service';
import { SessionStore } from './session-store.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { I18nService } from '../../i18n.service';

/** POST /v3/auth/logout is fire-and-forget; give up on it after this long. */
const LOGOUT_TIMEOUT_MS = 10_000;

/**
 * The push deactivation (plus its token renewal) runs before the logout
 * POST; past this the logout is sent anyway so it never waits on a hung
 * request.
 */
const PUSH_CLEANUP_TIMEOUT_MS = 30_000;

/**
 * AuthSessionService, single source of truth for terminating the
 * authenticated session on this device.
 *
 * A customer stays signed in until they tap "Log out" (or delete their
 * account); the only other way a session ends is the server saying so
 * (SessionStore 'rejected'). Every path runs the same local teardown:
 *
 *   1. SessionStore.endSession FIRST, so the device is signed out at once
 *      and a refresh racing with the logout can't resurrect the session
 *      (the store's compare-and-set refuses it).
 *   2. Remove Preferences('keep_session').
 *   3. Fire-and-forget remote clean-up with the tokens captured before (1)
 *      (remoteSignOut): deactivate this device's push token
 *      (PushManager.onSignedOut, renewing an expired access token with the
 *      captured refresh token), THEN POST /v3/auth/logout { refresh_token }
 *      WITHOUT a bearer, which ends only this device's session family
 *      (never other devices). Not awaited and errors are swallowed, so
 *      logout works offline.
 *   4. Clear the unpaid-orders reminder + the guest local cart and refresh
 *      the cart badge, so the next account starts clean.
 *   5. navigateRoot('/home') so the authed pages (and their stale copies of
 *      the user) are off the back stack.
 */
@Injectable({ providedIn: 'root' })
export class AuthSessionService {
  private watching = false;
  private handlingRejection = false;

  constructor(
    private networkAdapter: MobileNetworkAdapter,
    private pushManager: PushManager,
    private localCart: LocalCartService,
    private nav: NavController,
    private pendingOrders: PendingOrdersService,
    private session: SessionStore,
    private cartCount: CartCountService,
    private toast: AxNotificationService,
    private i18n: I18nService,
  ) {}

  /**
   * React to sessions the SERVER ended (refresh answered 401
   * AUTH_REFRESH_TOKEN_INVALID): same local teardown as logout, navigate to
   * the same landing and show ONE toast. Call once from the app shell.
   */
  watchSessionEnd(): void {
    if (this.watching) return;
    this.watching = true;
    this.session.sessionEnded$
      .pipe(filter((reason) => reason === 'rejected'))
      .subscribe(() => void this.onSessionRejected());
  }

  /**
   * Fully terminate the session on this device. Resilient, every remote
   * step is best-effort so the local teardown + redirect always run, even
   * offline or with an already-invalid token.
   */
  async logout(): Promise<void> {
    await this.endSession('logout');
    await this.nav.navigateRoot(['/home']);
  }

  /**
   * Local teardown after DELETE /v3/me succeeded (the caller navigates).
   * Same steps as logout; the server already removed the account.
   */
  async endAfterAccountDeletion(): Promise<void> {
    await this.endSession('deleted');
  }

  private async endSession(reason: 'logout' | 'deleted'): Promise<void> {
    // (a) Capture the tokens, then sign out locally FIRST.
    const blob = await this.session.get();
    const accessToken = typeof blob?.token === 'string' ? blob.token : '';
    const refreshToken = typeof blob?.refresh_token === 'string' ? blob.refresh_token : '';
    await this.session.endSession(reason);
    try {
      await Preferences.remove({ key: 'keep_session' });
    } catch {
      /* best-effort */
    }

    // (b) Remote clean-up, fire-and-forget with the captured tokens.
    void this.remoteSignOut(accessToken, refreshToken);

    // (c) Local state for a clean next session.
    await this.clearLocalState();
  }

  /**
   * Remote clean-up for a session that already ended locally. Uses ONLY
   * the captured tokens (never the store's, which may already hold the
   * next account's session):
   *   1. deactivate this device's push token with the captured access
   *      token; if the backend rejects it (the token lives ~15 minutes),
   *      a store-free POST /auth/refresh with the captured refresh token
   *      (accepted while unexpired, contract C2) provides a fresh one for
   *      a single retry;
   *   2. then POST /auth/logout with the captured refresh token, which
   *      ends the whole family, including a pair minted in step 1 (C3).
   * Step 1 must finish first (bounded by PUSH_CLEANUP_TIMEOUT_MS), else
   * the logout would revoke the token the renewal needs. Never throws.
   */
  private async remoteSignOut(accessToken: string, refreshToken: string): Promise<void> {
    const renew = refreshToken === ''
      ? undefined
      : async (): Promise<string | null> =>
          (await this.networkAdapter.refreshDetached(refreshToken))?.access_token ?? null;
    let timer: ReturnType<typeof setTimeout> | undefined;
    try {
      await Promise.race([
        this.pushManager.onSignedOut(accessToken, renew),
        new Promise<void>((resolve) => {
          timer = setTimeout(resolve, PUSH_CLEANUP_TIMEOUT_MS);
        }),
      ]);
    } catch {
      /* push deactivation is best-effort */
    } finally {
      clearTimeout(timer);
    }
    this.revokeRemoteSession(refreshToken);
  }

  /**
   * POST /v3/auth/logout { refresh_token }. No bearer and no
   * refresh-and-retry (the route takes optional auth; the refresh token
   * identifies the session family). Idempotent server-side.
   */
  private revokeRemoteSession(refreshToken: string): void {
    if (!refreshToken) return;
    this.networkAdapter
      .post_v3('POST /auth/logout', { refresh_token: refreshToken })
      .pipe(timeout(LOGOUT_TIMEOUT_MS))
      .subscribe({
        error: () => {
          /* offline / already-revoked, the device is signed out regardless */
        },
      });
  }

  private async clearLocalState(): Promise<void> {
    // Drop the unpaid-orders reminder (Home banner + Settings dot) so the next
    // account that signs in doesn't briefly see the previous user's count.
    this.pendingOrders.clear();
    try {
      await this.localCart.clear();
    } catch {
      /* best-effort */
    }
    void this.cartCount.refresh();
  }

  /**
   * The store already removed the session. Nothing remote is attempted: the
   * server has ended the session, so there's no valid token to use.
   */
  private async onSessionRejected(): Promise<void> {
    if (this.handlingRejection) return;
    this.handlingRejection = true;
    try {
      try {
        await Preferences.remove({ key: 'keep_session' });
      } catch {
        /* best-effort */
      }
      await this.clearLocalState();
      await this.nav.navigateRoot(['/home']);
      this.toast.error(this.i18n.t('session_ended_sign_in_again'), { position: 'top-center' });
    } catch {
      /* never let the teardown throw into the subscription */
    } finally {
      this.handlingRejection = false;
    }
  }
}
