import { Injectable } from '@angular/core';
import { Observable, Subject, of, throwError, timer } from 'rxjs';
import { catchError, map, switchMap, takeUntil, takeWhile } from 'rxjs/operators';

import { MobileNetworkAdapter } from '../http/mobile-network-adapter';

/**
 * Status returned by GET /v3/checkout/status/{order_reference}.
 *
 * Post-transform shape (see MUTATION_RESPONSE_TRANSFORMS in
 * mutation-response.transforms.ts → transformCheckoutStatusResponse).
 */
export type CheckoutStatus = {
  order_reference: string;
  order_id: number;
  status: string;
  terminal: boolean;
  paid: boolean;
  total: string;
  currency: string;
  paid_at: string | null;
};

/**
 * Outcome of a polling session.
 *
 *   - 'paid'   , terminal=true && paid=true; order is successfully paid
 *   - 'failed' , terminal=true && paid=false; order failed at the
 *                 gateway (declined, cancelled, etc.)
 *   - 'timeout', polled for the full window without reaching terminal;
 *                 the order may still complete via a delayed webhook -
 *                 mobile should navigate to my-orders with a "we'll
 *                 update you shortly" notice rather than show a hard
 *                 failure
 *   - 'error'  , non-recoverable error during polling (4xx/5xx,
 *                 network); shown as a soft error with retry option
 */
export type PollOutcome =
  | { kind: 'paid'; status: CheckoutStatus }
  | { kind: 'failed'; status: CheckoutStatus }
  | { kind: 'timeout'; lastStatus: CheckoutStatus | null }
  | { kind: 'error'; error: unknown };

/**
 * Industry-standard polling defaults for payment status checks.
 *
 * Stripe Checkout, PayPal Smart Buttons, and Adyen Drop-in all use
 * intervals in the 1-3 second range with timeouts in the 30-90s
 * range. 2s / 60s is the median of these.
 *
 * Rationale per interval:
 *   - Too short (<1s): wastes server/network resources without
 *     meaningfully improving UX (humans don't perceive sub-second
 *     refreshes as "responsive" for a payment context)
 *   - Too long (>3s): user starts to wonder if anything is happening;
 *     spinner appears stuck
 *
 * Rationale per timeout:
 *   - Too short (<30s): webhook delivery delays from the payment
 *     provider can legitimately exceed 30s, especially during their
 *     peak hours; failing the user is worse than showing "we'll
 *     update you"
 *   - Too long (>120s): user has waited so long that the spinner
 *     experience is itself a failure mode; better to navigate them
 *     to my-orders with a notice that polls again on view
 */
const POLL_INTERVAL_MS = 2_000;
const POLL_TIMEOUT_MS = 60_000;
const POLL_MAX_TICKS = Math.ceil(POLL_TIMEOUT_MS / POLL_INTERVAL_MS); // 30

/**
 * Service for polling v3 checkout status after Noon webview close.
 *
 * Usage:
 *   poll.pollUntilTerminal('V3-1700000000000-abcd').subscribe(outcome => {
 *     switch (outcome.kind) {
 *       case 'paid':    // navigate to success
 *       case 'failed':  // show failure UI with retry
 *       case 'timeout': // navigate to my-orders with notice
 *       case 'error':   // show transient-error UI
 *     }
 *   });
 *
 * Critical: this poll hits v3's status endpoint, NOT Noon's GET_ORDER
 * directly. v3's status endpoint reads v3's local order state; Noon's
 * webhook receiver is the only thing that calls Noon's GET_ORDER.
 * This is per recon-confirmed rate-limit ban risk if mobile or any
 * client polls Noon directly.
 *
 * Cancellation: pollUntilTerminal returns an Observable; subscribing
 * starts the poll, unsubscribing cancels it (no zombie polls after
 * page navigation away).
 */
@Injectable({ providedIn: 'root' })
export class CheckoutStatusPollService {
  constructor(private readonly networkAdapter: MobileNetworkAdapter) {}

  /**
   * Start polling. Emits exactly ONE PollOutcome and completes.
   *
   * The poll runs every POLL_INTERVAL_MS (2 seconds). It stops as
   * soon as it sees terminal=true OR POLL_MAX_TICKS expire.
   *
   * Each tick calls GET /v3/checkout/status/{ref} via the network
   * service. The adapter applies the response transform
   * (transformCheckoutStatusResponse) so the data field is already
   * the CheckoutStatus shape.
   *
   * Errors during a tick are absorbed and the poll continues for the
   * next tick (transient network blip shouldn't fail the whole
   * session). Repeated failures across the full window produce a
   * 'timeout' outcome rather than 'error', the order may have
   * completed via webhook; user shouldn't see a hard failure.
   */
  pollUntilTerminal(orderReference: string, authToken: string): Observable<PollOutcome> {
    if (!orderReference || typeof orderReference !== 'string') {
      return of<PollOutcome>({ kind: 'error', error: new Error('Missing order_reference') });
    }

    const stop$ = new Subject<void>();
    let tickCount = 0;
    let lastStatus: CheckoutStatus | null = null;

    return timer(0, POLL_INTERVAL_MS).pipe(
      takeUntil(stop$),
      takeWhile(() => tickCount < POLL_MAX_TICKS, true), // emit one final tick for the timeout case
      switchMap((tick): Observable<PollOutcome | null> => {
        tickCount = tick + 1;
        if (tickCount > POLL_MAX_TICKS) {
          // Final tick after the cap, emit timeout and complete
          stop$.next();
          stop$.complete();
          return of<PollOutcome>({ kind: 'timeout', lastStatus });
        }
        return this.fetchStatus(orderReference, authToken).pipe(
          map((status): PollOutcome | null => {
            lastStatus = status;
            if (status.terminal) {
              stop$.next();
              stop$.complete();
              return status.paid
                ? { kind: 'paid', status }
                : { kind: 'failed', status };
            }
            // Not terminal, keep polling. Returning null filters out
            // this tick from the outer observable.
            return null;
          }),
          catchError((err): Observable<PollOutcome | null> => {
            // Absorb transient errors; rely on tick cap for the
            // hard timeout. Log so dev tools surface it.
            console.warn('[CheckoutStatusPoll] tick error', err);
            return of(null);
          }),
        );
      }),
      // Filter out the null no-op ticks; only emit actual outcomes.
      switchMap((maybeOutcome) =>
        maybeOutcome === null ? of() : of(maybeOutcome),
      ),
    );
  }

  /**
   * Single status fetch, also exposed for unit testability without
   * standing up the full poll machinery.
   */
  fetchStatus(orderReference: string, authToken: string): Observable<CheckoutStatus> {
    // Direct v3 (GET /v3/checkout/status/:order_reference). Uses the v3 host +
    // Bearer auth (the endpoint is user-scoped, a raw GET to the legacy host
    // without auth previously failed every tick and fell back to 'timeout').
    // transformCheckoutStatusResponse applies via get_v3, so response.data is
    // the CheckoutStatus shape.
    return this.networkAdapter.get_v3('GET /checkout/status/:order_reference', {
      authToken,
      pathParams: { order_reference: orderReference },
    }).pipe(
      map((response: any): CheckoutStatus => {
        // Post-transform the v3 shape is at response.data. Dual-shape defence
        // for the off-chance the response comes through unwrapped.
        const raw = response?.data ?? response;
        return {
          order_reference: typeof raw?.order_reference === 'string' ? raw.order_reference : '',
          order_id: typeof raw?.order_id === 'number' ? raw.order_id : 0,
          status: typeof raw?.status === 'string' ? raw.status : 'unknown',
          terminal: raw?.terminal === true,
          paid: raw?.paid === true,
          total: typeof raw?.total === 'string' ? raw.total : '0.00',
          currency: typeof raw?.currency === 'string' ? raw.currency : 'AED',
          paid_at: typeof raw?.paid_at === 'string' ? raw.paid_at : null,
        };
      }),
      catchError((err) => throwError(() => err)),
    );
  }
}

/** Exported for testing only, do not use in production code. */
export const _internals = {
  POLL_INTERVAL_MS,
  POLL_TIMEOUT_MS,
  POLL_MAX_TICKS,
};
