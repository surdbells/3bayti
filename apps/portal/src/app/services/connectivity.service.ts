import { Injectable, NgZone, OnDestroy, inject } from '@angular/core';
import { BehaviorSubject, Observable, Subscription, fromEvent, merge, timer } from 'rxjs';
import { distinctUntilChanged } from 'rxjs/operators';

/**
 * Tracks whether the portal has a working connection to the backend and
 * surfaces it as a distinct `online$` stream for the UI.
 *
 * Why not just `navigator.onLine`?
 * --------------------------------
 * `navigator.onLine` (and the `online`/`offline` window events) only report
 * whether a network interface exists, they return `true` on a captive-portal
 * or internet-less LAN. That's a fast, reliable signal for the *hard* cases
 * (airplane mode, cable pulled, radio off), so we use it as the primary
 * trigger, but we never trust an "online" claim blindly: we CONFIRM it with a
 * lightweight reachability probe against the API's no-DB liveness endpoint.
 *
 * State model
 * -----------
 *  - Initial state trusts `navigator.onLine`, so a healthy load never flashes a
 *    false "offline" banner while a probe is in flight.
 *  - `offline` window event        → offline immediately.
 *  - `online` window event         → probe; only go online if it succeeds.
 *  - While offline                 → poll the probe so recovery is detected
 *                                    even when the `online` event is missed
 *                                    (captive portals, flaky adapters).
 *  - `recheck()`                   → manual probe (the banner's "Retry").
 *
 * The probe is a `no-cors` GET, so it needs no CORS config and never triggers a
 * preflight: any resolved response (even opaque) means the server answered, so
 * we're reachable; a rejection/timeout means we're not.
 */
@Injectable({ providedIn: 'root' })
export class ConnectivityService implements OnDestroy {
  private readonly zone = inject(NgZone);

  /** API liveness endpoint (no DB), the cheapest "is the backend there" ping. */
  private static readonly PROBE_URL = 'https://api-v3.3bayti.ae/v3/health';
  private static readonly PROBE_TIMEOUT_MS = 5_000;
  private static readonly RECHECK_MS = 12_000;

  private readonly _online$ = new BehaviorSubject<boolean>(this.navigatorOnline());

  /** Distinct connection state; safe to bind directly with the async pipe. */
  readonly online$: Observable<boolean> = this._online$.pipe(distinctUntilChanged());

  private events?: Subscription;
  private recheck$?: Subscription;
  private probing = false;

  constructor() {
    if (typeof window === 'undefined') {
      return; // Non-browser (tests/SSR): assume online, attach nothing.
    }

    this.events = merge(
      fromEvent(window, 'online'),
      fromEvent(window, 'offline'),
    ).subscribe(() => {
      if (this.navigatorOnline()) {
        this.verifyReachable(); // "online" is a claim, confirm it.
      } else {
        this.setOnline(false); // "offline" is authoritative.
      }
    });

    // If we boot already offline, start recovery polling right away.
    if (!this.navigatorOnline()) {
      this.manageRecheck(false);
    }
  }

  /** Current synchronous state (e.g. to guard an action before firing it). */
  get isOnline(): boolean {
    return this._online$.value;
  }

  /** Force an immediate reachability probe (the offline banner's Retry). */
  recheck(): void {
    this.verifyReachable();
  }

  private navigatorOnline(): boolean {
    return typeof navigator === 'undefined' ? true : navigator.onLine !== false;
  }

  private verifyReachable(): void {
    if (this.probing) {
      return; // Coalesce concurrent probes (event + poll + retry overlap).
    }
    this.probing = true;
    this.probe()
      .then((reachable) => this.setOnline(reachable))
      .finally(() => {
        this.probing = false;
      });
  }

  private async probe(): Promise<boolean> {
    if (typeof fetch === 'undefined') {
      return this.navigatorOnline();
    }
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), ConnectivityService.PROBE_TIMEOUT_MS);
    try {
      await fetch(`${ConnectivityService.PROBE_URL}?_=${Date.now()}`, {
        method: 'GET',
        mode: 'no-cors',
        cache: 'no-store',
        signal: controller.signal,
      });
      return true; // Any resolution (incl. opaque) means the server answered.
    } catch {
      return false; // Network error or timeout.
    } finally {
      clearTimeout(timeoutId);
    }
  }

  private setOnline(online: boolean): void {
    // Probe continuations can land outside Angular's zone if zone.js isn't
    // patching fetch, re-enter so async-pipe bindings refresh.
    this.zone.run(() => {
      if (this._online$.value !== online) {
        this._online$.next(online);
      }
      this.manageRecheck(online);
    });
  }

  /** Run a recovery poll only while offline; tear it down once back online. */
  private manageRecheck(online: boolean): void {
    if (online) {
      this.recheck$?.unsubscribe();
      this.recheck$ = undefined;
      return;
    }
    if (this.recheck$) {
      return; // Already polling.
    }
    this.recheck$ = timer(ConnectivityService.RECHECK_MS, ConnectivityService.RECHECK_MS)
      .subscribe(() => this.verifyReachable());
  }

  ngOnDestroy(): void {
    this.events?.unsubscribe();
    this.recheck$?.unsubscribe();
  }
}
