import { Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { MobileNetworkAdapter } from '../core/http/mobile-network-adapter';

export type HotlinkTargetType = 'store' | 'style';

/**
 * Store hotlinks & Style-Me codes (P9). Get-or-create the canonical tracked
 * short link for a store/look, so a share carries click + conversion tracking.
 * Auth-only; returns null on failure so callers fall back to the plain URL.
 *
 * Note: the shared link opens the WEB storefront. Opening the app from an
 * https hotlink needs native Universal/App Links (a signed release) — a
 * separate follow-up, not shipped here.
 */
@Injectable({ providedIn: 'root' })
export class HotlinkService {
  constructor(private network: MobileNetworkAdapter) {}

  /** Returns the ready-to-share short_url, or null. */
  async createShortUrl(targetType: HotlinkTargetType, targetSlug: string, authToken: string): Promise<string | null> {
    try {
      const res: any = await firstValueFrom(
        this.network.post_v3('POST /me/hotlinks', { target_type: targetType, target_slug: targetSlug }, { authToken }),
      );
      if (res?.status === 'error') {
        return null;
      }
      const url = res?.data?.short_url;
      return typeof url === 'string' && url !== '' ? url : null;
    } catch {
      return null;
    }
  }
}
