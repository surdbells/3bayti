import { Injectable, NgZone, OnDestroy } from '@angular/core';
import { BehaviorSubject, Observable, fromEvent, merge, of, timer } from 'rxjs';
import { distinctUntilChanged, map, startWith, switchMap } from 'rxjs/operators';
import { Network } from '@capacitor/network';

@Injectable({ providedIn: 'root' })
export class ConnectionService implements OnDestroy {
  private readonly _online$ = new BehaviorSubject<boolean>(navigator.onLine);
  /** Emits `true` when connected (and optionally reachable), else `false` */
  readonly online$: Observable<boolean> = this._online$.asObservable().pipe(distinctUntilChanged());

  private removeCapListener?: () => void;
  private reachabilityUrl = 'https://www.gstatic.com/generate_204'; // lightweight 204
  private verifyReachability = false; // set true to do a tiny HTTP check

  constructor(private zone: NgZone) {
    this.init();
  }

  private async init() {
    // Seed from Capacitor (if available)
    try {
      const status = await Network.getStatus();
      this.safeNext(status.connected);
    } catch {
      // ignore; fall back to browser events
    }

    // Listen via Capacitor (native + web)
    try {
      const listener = await Network.addListener('networkStatusChange', (status) => {
        this.zone.run(() => this.safeNext(status.connected));
      });
      this.removeCapListener = () => listener.remove();
    } catch {
      // ignore if plugin not available on current platform
    }

    // Fallback: browser events
    const online$ = fromEvent(window, 'online').pipe(map(() => true));
    const offline$ = fromEvent(window, 'offline').pipe(map(() => false));

    merge(online$, offline$).subscribe((v) => this.safeNext(v));

    // Optional: periodic reachability verification
    if (this.verifyReachability) {
      timer(0, 15000)
        .pipe(
          switchMap(() => this.checkNow({ verifyReachability: true })),
          distinctUntilChanged()
        )
        .subscribe((v) => this.safeNext(v));
    }
  }

  /** One-shot check. Set verifyReachability to true for a tiny fetch to confirm internet access. */
  async checkNow(opts?: { verifyReachability?: boolean; timeoutMs?: number }): Promise<boolean> {
    const baseOnline = await this.capacitorOnline();
    if (!opts?.verifyReachability && !this.verifyReachability) {
      return baseOnline;
    }
    // If we think we're online, verify with a super-light request
    if (baseOnline) {
      return this.ping(this.reachabilityUrl, opts?.timeoutMs ?? 4000);
    }
    return false;
  }

  /** Expose current value synchronously */
  get isOnline(): boolean {
    return this._online$.value;
  }

  /** Enable/disable extra reachability check at runtime (optional) */
  setReachabilityCheck(enabled: boolean, url?: string) {
    this.verifyReachability = enabled;
    if (url) this.reachabilityUrl = url;
  }

  ngOnDestroy(): void {
    if (this.removeCapListener) this.removeCapListener();
  }

  // --------- helpers ----------
  private async capacitorOnline(): Promise<boolean> {
    try {
      const status = await Network.getStatus();
      return !!status.connected;
    } catch {
      return navigator.onLine;
    }
  }

  private safeNext(value: boolean) {
    // Debounce identical values via distinctUntilChanged on the public stream,
    // but avoid unnecessary .next churn here too
    if (this._online$.value !== value) this._online$.next(value);
  }

  private async ping(url: string, timeoutMs: number): Promise<boolean> {
    try {
      const controller = new AbortController();
      const id = setTimeout(() => controller.abort(), timeoutMs);
      const res = await fetch(url, {
        method: 'GET',
        mode: 'no-cors', // allow opaque 0 status; we just need it to succeed or fail
        cache: 'no-store',
        signal: controller.signal
      });
      clearTimeout(id);
      // With no-cors, fetch resolves if reachable even if status is 0 (opaque).
      return true;
    } catch {
      return false;
    }
  }
}
