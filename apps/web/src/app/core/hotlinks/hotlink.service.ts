import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../http/routed-http-client';

export type HotlinkTargetType = 'store' | 'style';

export interface Hotlink {
  code: string;
  short_url: string;
  target_type: HotlinkTargetType;
  target_slug: string;
}

export interface HotlinkTarget {
  target_type: HotlinkTargetType;
  target_slug: string;
}

/**
 * Store hotlinks & Style-Me codes (P9). Creates the canonical shareable short
 * link for a store/look (auth-only) and resolves a code to its target.
 *
 * A per-browser session id (localStorage) is threaded into the resolve call so
 * logged-out clicks still contribute to a link's reach, mirroring how the AI
 * analytics uses a session id.
 */
@Injectable({ providedIn: 'root' })
export class HotlinkService {
  private readonly http = inject(RoutedHttpClient);
  private static readonly SID_KEY = 'bayti.hotlinkSid';

  /**
   * Get-or-create the canonical hotlink for a target. Returns null on failure
   * (e.g. not signed in — the caller then falls back to the plain URL).
   */
  async create(targetType: HotlinkTargetType, targetSlug: string): Promise<Hotlink | null> {
    try {
      const env = await firstValueFrom(
        this.http.post<Hotlink>('POST /me/hotlinks', {
          body: { target_type: targetType, target_slug: targetSlug },
        }),
      );
      return env.data ?? null;
    } catch {
      return null;
    }
  }

  /** Resolve a short code to its target (records the click server-side). */
  async resolve(code: string): Promise<HotlinkTarget | null> {
    try {
      const env = await firstValueFrom(
        this.http.get<HotlinkTarget>('GET /hotlinks/:code', {
          params: { code },
          query: { sid: this.sessionId() },
        }),
      );
      return env.data ?? null;
    } catch {
      return null;
    }
  }

  /** A stable per-browser id for logged-out click reach. */
  private sessionId(): string {
    try {
      let sid = localStorage.getItem(HotlinkService.SID_KEY);
      if (sid === null || sid === '') {
        sid = this.randomId();
        localStorage.setItem(HotlinkService.SID_KEY, sid);
      }
      return sid;
    } catch {
      return this.randomId();
    }
  }

  private randomId(): string {
    try {
      return crypto.randomUUID();
    } catch {
      return 'sid-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    }
  }
}
