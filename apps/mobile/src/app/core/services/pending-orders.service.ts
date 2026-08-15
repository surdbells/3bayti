import { Injectable, signal } from '@angular/core';
import { Preferences } from '@capacitor/preferences';

import { MobileNetworkAdapter } from '../http/mobile-network-adapter';

/**
 * Tracks the signed-in customer's orders that are still awaiting payment.
 *
 * A single source of truth for the "you have unpaid orders" reminders:
 *   - the Home banner (count + total)
 *   - the Settings bottom-tab notification dot
 *
 * Self-contained: reads the auth token from Preferences('user') itself, so
 * callers (app.component resume, Home, the tab bar) just call refresh() and
 * read the signals. Refresh is single-flight and never throws.
 */
@Injectable({ providedIn: 'root' })
export class PendingOrdersService {
  /** Number of pending_payment orders. Drives the banner + tab dot. */
  readonly count = signal(0);
  /** Sum of those orders' totals (AED), for the banner subtitle. */
  readonly amount = signal(0);

  private loading = false;

  constructor(private network: MobileNetworkAdapter) {}

  /** Re-query pending-payment orders and update the signals. Best-effort. */
  async refresh(): Promise<void> {
    if (this.loading) return;
    this.loading = true;

    let token = '';
    try {
      const ret = await Preferences.get({ key: 'user' });
      token = ret.value ? (JSON.parse(ret.value)?.token ?? '') : '';
    } catch {
      token = '';
    }
    if (!token) {
      this.clear();
      this.loading = false;
      return;
    }

    this.network
      .get_v3('GET /orders', {
        authToken: token,
        queryParams: { status: 'pending_payment', limit: 50, offset: 0 },
      })
      .subscribe({
        next: (res: any) => {
          this.loading = false;
          if (res?.response_code !== 200 || res?.status !== 'success') return;
          const rows = this.extractOrders(res.data);
          this.count.set(rows.length);
          this.amount.set(rows.reduce((sum: number, o: any) => sum + (Number(o?.total) || 0), 0));
        },
        error: () => {
          // Leave the last-known values on a transient failure.
          this.loading = false;
        },
      });
  }

  /** Reset to zero — call on sign-out. */
  clear(): void {
    this.count.set(0);
    this.amount.set(0);
  }

  private extractOrders(data: any): any[] {
    if (Array.isArray(data)) return data;
    if (data && typeof data === 'object' && Array.isArray(data.orders)) return data.orders;
    return [];
  }
}
