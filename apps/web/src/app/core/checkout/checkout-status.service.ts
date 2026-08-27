import { Injectable, signal, Signal, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import type { CheckoutStatusResponse } from './checkout.types';

const V3_BASE = 'https://api-v3.3bayti.ae';

/** Default polling cadence (Q3.3): poll every 2s, give up after 60s. */
export const POLL_INTERVAL_MS = 2000;
export const POLL_CEILING_MS = 60000;

/** Result of a pollUntilTerminal run. */
export interface PollResult {
  /** The last status response received (null only if the very first
   *  fetch failed before any response). */
  status: CheckoutStatusResponse | null;
  /** True if we stopped because the ceiling elapsed without reaching
   *  a terminal state. */
  timedOut: boolean;
}

/** Options for pollUntilTerminal, injectable so tests drive timing. */
export interface PollOptions {
  intervalMs?: number;
  ceilingMs?: number;
  /** Test seam: defaults to window.setTimeout-based delay. */
  sleep?: (ms: number) => Promise<void>;
  /** Test seam: defaults to Date.now. */
  now?: () => number;
}

/**
 * CheckoutStatusService, reads the authoritative payment outcome
 * from GET /v3/checkout/status/{ref} and offers a polling primitive
 * for the /checkout/return page.
 *
 * Why poll rather than trust the return redirect
 * ----------------------------------------------
 * Noon's browser redirect just means "the shopper came back", not
 * "payment succeeded". The truth arrives asynchronously via Noon's
 * webhook → our backend → local Order state. So the return page
 * polls this endpoint until the status is terminal.
 *
 * Why this endpoint is cheap to poll
 * ----------------------------------
 * GetCheckoutStatusController reads ONLY local DB state; it never
 * calls Noon (Noon bans aggressive GET_ORDER polling). So a 2s poll
 * for up to 60s is just ~30 cheap DB reads, safe.
 */
@Injectable({ providedIn: 'root' })
export class CheckoutStatusService {
  private readonly http = inject(HttpClient);

  private readonly _isPolling = signal<boolean>(false);
  readonly isPolling: Signal<boolean> = this._isPolling.asReadonly();

  /** Single status fetch. Throws on HTTP error (404 for unknown /
   *  cross-user references). */
  async getStatus(reference: string): Promise<CheckoutStatusResponse> {
    return firstValueFrom(
      this.http.get<CheckoutStatusResponse>(
        `${V3_BASE}/v3/checkout/status/${encodeURIComponent(reference)}`,
      ),
    );
  }

  /**
   * Poll getStatus until the order reaches a terminal state or the
   * ceiling elapses.
   *
   * Behaviour:
   *   - Fetches immediately (poll #0), then every intervalMs.
   *   - Resolves as soon as a response has terminal === true.
   *   - Resolves with timedOut=true once elapsed >= ceilingMs without
   *     a terminal status.
   *   - A transient fetch error (e.g. a blip) does NOT abort the poll;
   *     we keep the last good status and try again next tick. If the
   *     VERY FIRST fetch errors AND it keeps erroring until the
   *     ceiling, we resolve with status=null, timedOut=true so the
   *     caller can show the generic failure state.
   *
   * The ceiling is checked against a monotonic-ish elapsed time so a
   * slow network can't extend the poll indefinitely.
   */
  async pollUntilTerminal(
    reference: string,
    options: PollOptions = {},
  ): Promise<PollResult> {
    const intervalMs = options.intervalMs ?? POLL_INTERVAL_MS;
    const ceilingMs = options.ceilingMs ?? POLL_CEILING_MS;
    const sleep = options.sleep ?? defaultSleep;
    const now = options.now ?? (() => Date.now());

    const startedAt = now();
    let lastStatus: CheckoutStatusResponse | null = null;

    this._isPolling.set(true);
    try {
      // poll loop, fetch, check terminal, check ceiling, sleep, repeat
      for (;;) {
        try {
          lastStatus = await this.getStatus(reference);
          if (lastStatus.terminal) {
            return { status: lastStatus, timedOut: false };
          }
        } catch {
          /* Transient error, keep lastStatus (may be null) and let
             the ceiling check below decide whether to keep trying. */
        }

        const elapsed = now() - startedAt;
        if (elapsed + intervalMs >= ceilingMs) {
          /* Next sleep would cross (or reach) the ceiling, stop now
             rather than overshoot. */
          return { status: lastStatus, timedOut: true };
        }

        await sleep(intervalMs);
      }
    } finally {
      this._isPolling.set(false);
    }
  }
}

function defaultSleep(ms: number): Promise<void> {
  return new Promise<void>(resolve => {
    /* setTimeout is fine here; the return page only runs this in the
       browser (the route is RenderMode.Server but polling starts in
       ngOnInit guarded by isPlatformBrowser). */
    setTimeout(resolve, ms);
  });
}
