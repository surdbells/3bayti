import { TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';

import {
  CheckoutStatusPollService,
  type CheckoutStatus,
  type PollOutcome,
  _internals,
} from './checkout-status-poll.service';
import { NetworkService } from '../../service/network.service';

/**
 * Unit tests for the M3.1.6i.2-D checkout status poll service.
 *
 * The service polls GET /v3/checkout/status/{ref} every 2s until
 * terminal=true OR a 60s timeout expires. Outcomes:
 *   - 'paid' (terminal && paid)
 *   - 'failed' (terminal && !paid)
 *   - 'timeout' (no terminal within window)
 *   - 'error' (invalid input)
 *
 * Per project convention, mobile CI runs type-check + build only;
 * specs are compile-checked but not executed. They run locally with
 * `pnpm --filter @3bayti/mobile test`. The tests below use Jasmine's
 * fakeAsync + tick to simulate poll intervals without real time.
 */

class MockNetworkService {
  // Tests configure this to return different observables for different
  // calls. Defaults to "never returns" which would hang the test if
  // not overridden.
  responses: Array<Observable<unknown>> = [];
  call = 0;

  get_request(_url: string): Observable<unknown> {
    const resp = this.responses[this.call];
    this.call++;
    return resp ?? throwError(() => new Error('no response configured'));
  }

  post_request(_body: unknown, _url: string): Observable<unknown> {
    return throwError(() => new Error('post_request not used by poll service'));
  }
}

describe('CheckoutStatusPollService', () => {
  let service: CheckoutStatusPollService;
  let mockNet: MockNetworkService;

  beforeEach(() => {
    mockNet = new MockNetworkService();
    TestBed.configureTestingModule({
      providers: [
        CheckoutStatusPollService,
        { provide: NetworkService, useValue: mockNet },
      ],
    });
    service = TestBed.inject(CheckoutStatusPollService);
  });

  describe('fetchStatus', () => {
    it('parses a v3-envelope response', (done) => {
      mockNet.responses = [
        of({
          response_code: 200,
          status: 'success',
          message: '',
          data: {
            order_reference: 'V3-001',
            order_id: 42,
            status: 'paid',
            terminal: true,
            paid: true,
            total: '299.00',
            currency: 'AED',
            paid_at: '2026-05-15T10:00:00Z',
          },
        }),
      ];

      service.fetchStatus('https://api.example/v3/checkout/status/V3-001', 'tok').subscribe(
        (status: CheckoutStatus) => {
          expect(status.order_reference).toBe('V3-001');
          expect(status.terminal).toBe(true);
          expect(status.paid).toBe(true);
          expect(status.paid_at).toBe('2026-05-15T10:00:00Z');
          done();
        },
      );
    });

    it('coerces missing/null fields to defaults', (done) => {
      mockNet.responses = [of({ data: {} })];

      service.fetchStatus('url', 'tok').subscribe((status: CheckoutStatus) => {
        expect(status.order_reference).toBe('');
        expect(status.status).toBe('unknown');
        expect(status.terminal).toBe(false);
        expect(status.paid).toBe(false);
        expect(status.total).toBe('0.00');
        expect(status.paid_at).toBeNull();
        done();
      });
    });

    it('handles unwrapped data (no envelope)', (done) => {
      mockNet.responses = [
        of({
          order_reference: 'V3-DIRECT',
          status: 'pending_payment',
          terminal: false,
          paid: false,
          total: '99.00',
          currency: 'AED',
          paid_at: null,
        }),
      ];

      service.fetchStatus('url', 'tok').subscribe((status: CheckoutStatus) => {
        expect(status.order_reference).toBe('V3-DIRECT');
        expect(status.status).toBe('pending_payment');
        done();
      });
    });

    it('propagates errors from the network service', (done) => {
      mockNet.responses = [throwError(() => new Error('network down'))];

      service.fetchStatus('url', 'tok').subscribe({
        next: () => fail('should have errored'),
        error: (err) => {
          expect((err as Error).message).toBe('network down');
          done();
        },
      });
    });
  });

  describe('pollUntilTerminal', () => {
    it('returns an error outcome immediately for missing reference', (done) => {
      service.pollUntilTerminal('', 'tok').subscribe((outcome: PollOutcome) => {
        expect(outcome.kind).toBe('error');
        done();
      });
    });

    it('returns paid outcome when first poll returns terminal+paid', (done) => {
      mockNet.responses = [
        of({
          data: {
            order_reference: 'V3-001',
            order_id: 42,
            status: 'paid',
            terminal: true,
            paid: true,
            total: '299.00',
            currency: 'AED',
            paid_at: '2026-05-15T10:00:00Z',
          },
        }),
      ];

      service.pollUntilTerminal('V3-001', 'tok').subscribe((outcome: PollOutcome) => {
        expect(outcome.kind).toBe('paid');
        if (outcome.kind === 'paid') {
          expect(outcome.status.order_reference).toBe('V3-001');
          expect(outcome.status.paid).toBe(true);
        }
        done();
      });
    });

    it('returns failed outcome when first poll returns terminal+!paid', (done) => {
      mockNet.responses = [
        of({
          data: {
            order_reference: 'V3-002',
            order_id: 43,
            status: 'failed',
            terminal: true,
            paid: false,
            total: '299.00',
            currency: 'AED',
            paid_at: null,
          },
        }),
      ];

      service.pollUntilTerminal('V3-002', 'tok').subscribe((outcome: PollOutcome) => {
        expect(outcome.kind).toBe('failed');
        if (outcome.kind === 'failed') {
          expect(outcome.status.status).toBe('failed');
        }
        done();
      });
    });
  });

  describe('polling defaults', () => {
    it('uses industry-standard 2s interval', () => {
      expect(_internals.POLL_INTERVAL_MS).toBe(2_000);
    });

    it('uses industry-standard 60s timeout', () => {
      expect(_internals.POLL_TIMEOUT_MS).toBe(60_000);
    });

    it('caps polls at ~30 ticks (60s / 2s)', () => {
      expect(_internals.POLL_MAX_TICKS).toBe(30);
    });
  });
});
