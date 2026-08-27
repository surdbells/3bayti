import { Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { Capacitor } from '@capacitor/core';
import { MobileNetworkAdapter } from '../http/mobile-network-adapter';

/**
 * PushRegistrationService (mobile), registers / deactivates this
 * device's FCM push token against the v3 backend.
 *
 * Route-keys (resolved by MobileNetworkAdapter via @3bayti/api-client):
 *   POST   /me/device-tokens   { token, platform }   register (upsert)
 *   DELETE /me/device-tokens   { token }              deactivate
 *
 * Both are authenticated (Bearer token), so registration only happens
 * once the user is signed in (Q-Z5.1=A). Deactivation is called on
 * sign-out (Q-Z5.2) so a shared device stops receiving the previous
 * user's pushes.
 *
 * Platform: the backend stores 'ios' | 'android' for analytics; FCM
 * relays to APNs for iOS, so both platforms carry FCM tokens. We map
 * Capacitor's platform string to those two values, defaulting unknown
 * platforms to 'android' (the FCM-native case).
 */
@Injectable({ providedIn: 'root' })
export class PushRegistrationService {
  constructor(private readonly adapter: MobileNetworkAdapter) {}

  /**
   * Register (upsert) this device's push token for the signed-in user.
   * Idempotent server-side. Returns true on success.
   */
  async register(authToken: string, deviceToken: string): Promise<boolean> {
    if (authToken === '' || deviceToken === '') {
      return false;
    }
    const res: any = await firstValueFrom(
      this.adapter.post_v3(
        'POST /me/device-tokens',
        { token: deviceToken, platform: this.platform() },
        { authToken },
      ),
    );
    return this.ok(res);
  }

  /**
   * Deactivate this device's push token (sign-out / opt-out).
   * Idempotent server-side; an unknown token still returns 204.
   * Returns true on success.
   */
  async deactivate(authToken: string, deviceToken: string): Promise<boolean> {
    if (authToken === '' || deviceToken === '') {
      return false;
    }
    const res: any = await firstValueFrom(
      this.adapter.delete_v3('DELETE /me/device-tokens', {
        authToken,
        body: { token: deviceToken },
      }),
    );
    return this.ok(res) || res?.response_code === 204;
  }

  /** Map Capacitor's platform to the backend's ios|android enum. */
  private platform(): 'ios' | 'android' {
    return Capacitor.getPlatform() === 'ios' ? 'ios' : 'android';
  }

  private ok(res: any): boolean {
    return res?.response_code >= 200 && res?.response_code < 300 && res?.status === 'success';
  }
}
