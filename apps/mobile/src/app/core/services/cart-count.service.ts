import { Injectable, Signal, signal, inject } from '@angular/core';
import { BehaviorSubject } from 'rxjs';
import { Preferences } from '@capacitor/preferences';
import { firstValueFrom } from 'rxjs';
import { LocalCartService } from './local-cart.service';
import { MobileNetworkAdapter } from '../http/mobile-network-adapter';

/**
 * CartCountService, single reactive source of truth for the header
 * cart-badge item count, mirroring web's CartService.itemCount.
 *
 * Why this exists
 * ===============
 * Before this service there was NO shared reactive cart-count source on
 * mobile. Each page kept its own per-page `bill.count`, populated only by
 * the authed GET /cart, and `Preferences('count')` was written on every
 * add/remove but never read back. The result: the header badge was blank
 * for guests (no server cart) and went stale after add/remove until a full
 * page reload re-ran GET /cart.
 *
 * Design
 * ======
 * Two backing stores, chosen by auth state (Preferences 'user'):
 *   - Guest (user == null): count comes from LocalCartService.count()
 *     (sum of quantities across IndexedDB lines).
 *   - Authed: count comes from GET /v3/cart's bill.count.
 *
 * The count is exposed BOTH as an Angular signal (`count`) and a
 * BehaviorSubject (`count$`) so template bindings can consume whichever
 * fits, `app-cart-icon` takes a plain number @Input, so pages read the
 * signal in the template.
 *
 * Update surface
 * ==============
 *   refresh()   , recompute from the authoritative store (call on page
 *                  enter). Async; safe to fire-and-forget.
 *   setCount(n) , set the count directly (e.g. authed add returns the new
 *                  total in its response).
 *   bump(delta) , adjust the count immediately by a delta (default +1)
 *                  for instant feedback after a mutation, ahead of the
 *                  authoritative refresh().
 *
 * This replaces the write-only Preferences('count') usage; callers should
 * route through this service so every cart-badge consumer stays in lock-step.
 */
@Injectable({ providedIn: 'root' })
export class CartCountService {
  private readonly localCart = inject(LocalCartService);
  private readonly networkAdapter = inject(MobileNetworkAdapter);

  private readonly _count = signal<number>(0);

  /** Reactive item count for the header badge (read-only signal). */
  readonly count: Signal<number> = this._count.asReadonly();

  /** Same value as an Observable for RxJS-style consumers. */
  readonly count$ = new BehaviorSubject<number>(0);

  /**
   * Recompute the count from the authoritative store. Guest → local
   * IndexedDB cart; authed → GET /v3/cart bill.count. Errors degrade to
   * leaving the current value untouched (best-effort badge).
   */
  async refresh(): Promise<void> {
    try {
      const ret = await Preferences.get({ key: 'user' });
      if (ret.value == null) {
        // Guest, sum quantities from the device-local cart.
        const count = await this.localCart.count();
        this.setCount(count);
        return;
      }

      // Authed, read the server cart's count.
      const user = JSON.parse(ret.value) as { token?: string };
      const response: any = await firstValueFrom(
        this.networkAdapter.get_v3('GET /cart', { authToken: user.token }),
      );
      if (response?.response_code === 200) {
        // Dual-shape (strangler-fig): v3 puts {items, bill} in `data`;
        // legacy put the bill in `message`. Read count from whichever
        // carries it.
        const data = response.data;
        let count = 0;
        if (data && typeof data === 'object' && data.bill && typeof data.bill.count === 'number') {
          count = data.bill.count;
        } else if (response.message && typeof response.message.count === 'number') {
          count = response.message.count;
        }
        this.setCount(count);
      }
    } catch {
      /* best-effort: keep the current count on failure */
    }
  }

  /** Set the count directly and publish to both the signal and subject. */
  setCount(n: number): void {
    const next = Number.isFinite(n) && n > 0 ? Math.trunc(n) : 0;
    this._count.set(next);
    this.count$.next(next);
  }

  /**
   * Adjust the count immediately by `delta` (default +1) for instant
   * feedback after an add/remove, ahead of the authoritative refresh().
   */
  bump(delta = 1): void {
    this.setCount(this._count() + delta);
  }
}
