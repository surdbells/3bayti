import { Injectable } from '@angular/core';
import { Preferences } from '@capacitor/preferences';
import { NavController } from '@ionic/angular/standalone';
import { firstValueFrom } from 'rxjs';

import { MobileNetworkAdapter } from '../http/mobile-network-adapter';
import { PushManager } from './push-manager.service';
import { LocalCartService } from './local-cart.service';

/**
 * AuthSessionService — single source of truth for terminating the
 * authenticated session on this device.
 *
 * Prior to this service the two logout handlers (settings.page.signOut
 * and account.page.user_sign_out) only removed the Preferences('user')
 * + Preferences('keep_session') keys. They NEVER revoked the server-side
 * session, so the RefreshToken row outlived the "logout" and the device
 * FCM token kept receiving pushes. logout() below does the full teardown:
 *
 *   1. Deactivate this device's push token (PushManager.onSignedOutReadingToken)
 *      BEFORE clearing — it reads the still-valid auth token from
 *      Preferences('user'). Wrapped in try/catch (best-effort, no-op on web).
 *   2. POST /v3/auth/logout { refresh_token } with the access token as the
 *      bearer — revokes the RefreshToken row server-side. BEST-EFFORT:
 *      errors are swallowed so logout still works offline / on a dead token.
 *   3. Remove Preferences('user') + Preferences('keep_session').
 *   4. Clear the guest local cart for a clean next session.
 *   5. navigateRoot('/home') so the authed pages are off the back stack.
 */
@Injectable({ providedIn: 'root' })
export class AuthSessionService {
  constructor(
    private networkAdapter: MobileNetworkAdapter,
    private pushManager: PushManager,
    private localCart: LocalCartService,
    private nav: NavController,
  ) {}

  /**
   * Fully terminate the session on this device. Resilient — every remote
   * step is best-effort so the local teardown + redirect always run, even
   * offline or with an already-invalid token.
   */
  async logout(): Promise<void> {
    // (a) Read the user blob — holds both the access token and refresh_token.
    let token = '';
    let refreshToken = '';
    try {
      const ret = await Preferences.get({ key: 'user' });
      if (ret.value) {
        const blob = JSON.parse(ret.value);
        token = blob?.token ?? '';
        refreshToken = blob?.refresh_token ?? '';
      }
    } catch {
      /* malformed blob — proceed with local teardown anyway */
    }

    // (b) Deactivate this device's FCM token while the auth token is still
    //     present. Best-effort: PushManager swallows errors / no-ops on web.
    try {
      await this.pushManager.onSignedOutReadingToken();
    } catch {
      /* push deactivation is best-effort */
    }

    // (c) Revoke the server-side RefreshToken. BEST-EFFORT — swallow errors
    //     so logout works offline or with a dead token.
    if (token && refreshToken) {
      try {
        await firstValueFrom(
          this.networkAdapter.post_v3(
            'POST /auth/logout',
            { refresh_token: refreshToken },
            { authToken: token },
          ),
        );
      } catch {
        /* offline / already-revoked — local teardown still proceeds */
      }
    }

    // (d) Clear the local session keys.
    await Preferences.remove({ key: 'user' });
    await Preferences.remove({ key: 'keep_session' });

    // (e) Wipe the guest local cart for a clean next session.
    try {
      await this.localCart.clear();
    } catch {
      /* best-effort */
    }

    // (f) Redirect to a single public landing, resetting the nav stack so
    //     the authed pages are no longer reachable via the back button.
    await this.nav.navigateRoot(['/home']);
  }
}
