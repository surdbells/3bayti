import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { CheckoutStatusService } from './checkout-status.service';
import type { CheckoutStatusResponse } from './checkout.types';

const V3_BASE = 'https://api-v3.3bayti.ae';

function makeStatus(o: Partial<CheckoutStatusResponse> = {}): CheckoutStatusResponse {
  return {
    order_reference: 'V3-ORDER-001',
    order_id: 100,
    status: 'pending_payment',
    terminal: false,
    paid: false,
    total: '299.00',
    currency: 'AED',
    paid_at: null,
    ...o,
  };
}

function setup(): { service: CheckoutStatusService; controller: HttpTestingController } {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting()],
  });
  return {
    service: TestBed.inject(CheckoutStatusService),
    controller: TestBed.inject(HttpTestingController),
  };
}

/** A controllable fake clock + sleep so poll loops run with zero real
 *  delay. now() advances by exactly the requested sleep amount each
 *  time sleep() is called. */
function fakeTiming(): { sleep: (ms: number) => Promise<void>; now: () => number } {
  let clock = 0;
  return {
    now: () => clock,
    sleep: (ms: number) => {
      clock += ms;
      return Promise.resolve();
    },
  };
}

/** Drain enough microtasks for the poll loop to advance one full
 *  iteration: firstValueFrom resolution → terminal/ceiling check →
 *  sleep resolution → next getStatus dispatch. */
async function drain(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

describe('CheckoutStatusService', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('getStatus', () => {
    it('GETs /v3/checkout/status/:ref and returns the body', async () => {
      const { service, controller } = setup();
      const promise = service.getStatus('V3-ORDER-001');
      const req = controller.expectOne(`${V3_BASE}/v3/checkout/status/V3-ORDER-001`);
      expect(req.request.method).toBe('GET');
      req.flush(makeStatus({ status: 'paid', terminal: true, paid: true }));
      const result = await promise;
      expect(result.status).toBe('paid');
      expect(result.paid).toBe(true);
    });

    it('url-encodes the reference', async () => {
      const { service, controller } = setup();
      const promise = service.getStatus('V3 x&y');
      const req = controller.expectOne(`${V3_BASE}/v3/checkout/status/V3%20x%26y`);
      req.flush(makeStatus());
      await promise;
    });

    it('propagates HTTP errors (e.g. 404)', async () => {
      const { service, controller } = setup();
      const promise = service.getStatus('UNKNOWN');
      controller.expectOne(`${V3_BASE}/v3/checkout/status/UNKNOWN`)
        .flush({ error_code: 'NOT_FOUND' }, { status: 404, statusText: 'Not Found' });
      await expect(promise).rejects.toBeDefined();
    });
  });

  describe('pollUntilTerminal', () => {
    it('resolves immediately when the first fetch is terminal', async () => {
      const { service, controller } = setup();
      const timing = fakeTiming();
      const promise = service.pollUntilTerminal('V3-ORDER-001', {
        intervalMs: 2000, ceilingMs: 60000, ...timing,
      });
      controller.expectOne(`${V3_BASE}/v3/checkout/status/V3-ORDER-001`)
        .flush(makeStatus({ status: 'paid', terminal: true, paid: true }));
      const result = await promise;
      expect(result.timedOut).toBe(false);
      expect(result.status?.paid).toBe(true);
    });

    it('keeps polling while pending, then resolves on terminal', async () => {
      const { service, controller } = setup();
      const timing = fakeTiming();
      const promise = service.pollUntilTerminal('V3-ORDER-001', {
        intervalMs: 2000, ceilingMs: 60000, ...timing,
      });

      /* Poll #0, pending. */
      controller.expectOne(`${V3_BASE}/v3/checkout/status/V3-ORDER-001`)
        .flush(makeStatus({ status: 'pending_payment', terminal: false }));
      await drain();

      /* Poll #1, pending. */
      controller.expectOne(`${V3_BASE}/v3/checkout/status/V3-ORDER-001`)
        .flush(makeStatus({ status: 'pending_payment', terminal: false }));
      await drain();

      /* Poll #2, now paid. */
      controller.expectOne(`${V3_BASE}/v3/checkout/status/V3-ORDER-001`)
        .flush(makeStatus({ status: 'paid', terminal: true, paid: true }));

      const result = await promise;
      expect(result.timedOut).toBe(false);
      expect(result.status?.status).toBe('paid');
    });

    it('resolves timedOut=true when ceiling reached without terminal', async () => {
      const { service, controller } = setup();
      const timing = fakeTiming();
      /* ceiling 6s, interval 2s → polls at t=0,2,4; next sleep would
         hit t=6 == ceiling, so stops after poll #2 (t=4). */
      const promise = service.pollUntilTerminal('V3-ORDER-001', {
        intervalMs: 2000, ceilingMs: 6000, ...timing,
      });

      for (let i = 0; i < 3; i++) {
        controller.expectOne(`${V3_BASE}/v3/checkout/status/V3-ORDER-001`)
          .flush(makeStatus({ status: 'pending_payment', terminal: false }));
        await drain();
      }

      const result = await promise;
      expect(result.timedOut).toBe(true);
      expect(result.status?.status).toBe('pending_payment');
    });

    it('keeps the last good status across a transient fetch error', async () => {
      const { service, controller } = setup();
      const timing = fakeTiming();
      const promise = service.pollUntilTerminal('V3-ORDER-001', {
        intervalMs: 2000, ceilingMs: 60000, ...timing,
      });

      /* Poll #0, pending (good). */
      controller.expectOne(`${V3_BASE}/v3/checkout/status/V3-ORDER-001`)
        .flush(makeStatus({ status: 'pending_payment', terminal: false }));
      await drain();

      /* Poll #1, transient 500; loop must continue. */
      controller.expectOne(`${V3_BASE}/v3/checkout/status/V3-ORDER-001`)
        .flush({}, { status: 500, statusText: 'Server Error' });
      await drain();

      /* Poll #2, paid. */
      controller.expectOne(`${V3_BASE}/v3/checkout/status/V3-ORDER-001`)
        .flush(makeStatus({ status: 'paid', terminal: true, paid: true }));

      const result = await promise;
      expect(result.timedOut).toBe(false);
      expect(result.status?.paid).toBe(true);
    });

    it('resolves status=null, timedOut=true when every fetch errors to the ceiling', async () => {
      const { service, controller } = setup();
      const timing = fakeTiming();
      const promise = service.pollUntilTerminal('V3-ORDER-001', {
        intervalMs: 2000, ceilingMs: 6000, ...timing,
      });

      for (let i = 0; i < 3; i++) {
        controller.expectOne(`${V3_BASE}/v3/checkout/status/V3-ORDER-001`)
          .flush({}, { status: 500, statusText: 'Server Error' });
        await drain();
      }

      const result = await promise;
      expect(result.timedOut).toBe(true);
      expect(result.status).toBeNull();
    });

    it('toggles isPolling around the run', async () => {
      const { service, controller } = setup();
      const timing = fakeTiming();
      const promise = service.pollUntilTerminal('V3-ORDER-001', {
        intervalMs: 2000, ceilingMs: 60000, ...timing,
      });
      expect(service.isPolling()).toBe(true);
      controller.expectOne(`${V3_BASE}/v3/checkout/status/V3-ORDER-001`)
        .flush(makeStatus({ terminal: true, paid: true, status: 'paid' }));
      await promise;
      expect(service.isPolling()).toBe(false);
    });

    it('treats a terminal-but-unpaid status (failed) as terminal', async () => {
      const { service, controller } = setup();
      const timing = fakeTiming();
      const promise = service.pollUntilTerminal('V3-ORDER-001', {
        intervalMs: 2000, ceilingMs: 60000, ...timing,
      });
      controller.expectOne(`${V3_BASE}/v3/checkout/status/V3-ORDER-001`)
        .flush(makeStatus({ status: 'failed', terminal: true, paid: false }));
      const result = await promise;
      expect(result.timedOut).toBe(false);
      expect(result.status?.paid).toBe(false);
      expect(result.status?.status).toBe('failed');
    });
  });
});
