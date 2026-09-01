import { Injectable, Signal, computed, signal } from '@angular/core';

/**
 * Tracks whether a checkout / payment flow is currently in progress, so other
 * subsystems (notably the OTA updater) never disrupt it.
 *
 * An OTA `reload()` hot-swaps the web view and tears down all in-memory state.
 * If that happened while the customer was on the payment sheet or while the
 * post-payment poller was confirming an order, the payment could be lost or
 * left in limbo. This service is the single global signal those reload paths
 * check before applying an update.
 *
 * Ref-counted: `begin()` and `end()` may nest / overlap (the checkout page and
 * the status poller both mark the window), so `isActive()` stays true until
 * every opened window has closed. `end()` never drops below zero.
 */
@Injectable({ providedIn: 'root' })
export class CheckoutActivityService {
  private readonly _count = signal<number>(0);

  /** True while at least one checkout/payment window is open. */
  readonly isActive: Signal<boolean> = computed(() => this._count() > 0);

  /** Mark the start of a checkout/payment window. Pair with end(). */
  begin(): void {
    this._count.update((n) => n + 1);
  }

  /** Mark the end of a checkout/payment window. Safe to over-call. */
  end(): void {
    this._count.update((n) => (n > 0 ? n - 1 : 0));
  }
}
