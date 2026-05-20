import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, provideRouter, Router } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { CheckoutReturnPageComponent } from './checkout-return-page';
import { CheckoutService, CheckoutStatusService } from '../../core/checkout';
import type { CheckoutStatusResponse, PollResult } from '../../core/checkout';
import { provideI18n } from '../../core/i18n';

function makeStatus(o: Partial<CheckoutStatusResponse> = {}): CheckoutStatusResponse {
  return {
    order_reference: 'V3-ORDER-001', order_id: 100, status: 'paid',
    terminal: true, paid: true, total: '299.00', currency: 'AED',
    paid_at: '2026-05-20T00:00:00Z', ...o,
  };
}

class StubStatusService {
  isPolling = signal(false).asReadonly();
  pollResult: PollResult = { status: makeStatus(), timedOut: false };
  pollCalls: string[] = [];
  shouldThrow = false;
  async pollUntilTerminal(ref: string): Promise<PollResult> {
    this.pollCalls.push(ref);
    if (this.shouldThrow) throw new Error('poll failed');
    return this.pollResult;
  }
  async getStatus(): Promise<CheckoutStatusResponse> { return makeStatus(); }
}

class StubCheckoutService {
  shippingAddressId = signal<number | null>(1).asReadonly();
  billingAddressId = signal<number | null>(1).asReadonly();
  promoCode = signal<string | null>(null).asReadonly();
  isInitiating = signal(false).asReadonly();
  clearCalls = 0;
  clear(): void { this.clearCalls++; }
  setShippingAddress(): void { /* no-op */ }
  setBillingAddress(): void { /* no-op */ }
  setPromoCode(): void { /* no-op */ }
}

function setup(opts: {
  ref?: string | null;
  pollResult?: PollResult;
  shouldThrow?: boolean;
} = {}): {
  fixture: ComponentFixture<CheckoutReturnPageComponent>;
  status: StubStatusService;
  checkout: StubCheckoutService;
  navigateByUrlSpy: ReturnType<typeof vi.fn>;
} {
  const status = new StubStatusService();
  if (opts.pollResult !== undefined) status.pollResult = opts.pollResult;
  if (opts.shouldThrow === true) status.shouldThrow = true;
  const checkout = new StubCheckoutService();

  const ref = opts.ref !== undefined ? opts.ref : 'V3-ORDER-001';
  const activatedRouteStub = {
    snapshot: {
      queryParamMap: {
        get: (key: string): string | null =>
          ref !== null && key === 'ref' ? ref : null,
      },
    },
  };

  TestBed.configureTestingModule({
    imports: [CheckoutReturnPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: CheckoutStatusService, useValue: status },
      { provide: CheckoutService, useValue: checkout },
      { provide: ActivatedRoute, useValue: activatedRouteStub },
    ],
  });

  const router = TestBed.inject(Router);
  const navigateByUrlSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;
  const fixture = TestBed.createComponent(CheckoutReturnPageComponent);
  fixture.detectChanges();
  return { fixture, status, checkout, navigateByUrlSpy };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

describe('CheckoutReturnPageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => {
        if (!req.cancelled) req.flush({});
      });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('paid outcome', () => {
    it('polls with the ref from the query string', async () => {
      const { status } = setup({ ref: 'V3-ABC-9' });
      await flush();
      expect(status.pollCalls).toEqual(['V3-ABC-9']);
    });

    it('clears checkout state and navigates to success on paid', async () => {
      const { checkout, navigateByUrlSpy } = setup({
        pollResult: { status: makeStatus({ order_id: 555, paid: true, terminal: true }), timedOut: false },
      });
      await flush();
      expect(checkout.clearCalls).toBe(1);
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/checkout/success/555');
    });

    it('trims whitespace around the ref', async () => {
      const { status } = setup({ ref: '  V3-TRIM-1  ' });
      await flush();
      expect(status.pollCalls).toEqual(['V3-TRIM-1']);
    });
  });

  describe('failed outcome', () => {
    it('shows the failed state for terminal-but-unpaid status', async () => {
      const { fixture, checkout, navigateByUrlSpy } = setup({
        pollResult: { status: makeStatus({ status: 'failed', terminal: true, paid: false }), timedOut: false },
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="return-failed"]')).not.toBeNull();
      /* Failure keeps checkout state for Try again. */
      expect(checkout.clearCalls).toBe(0);
      expect(navigateByUrlSpy).not.toHaveBeenCalled();
    });

    it('renders Try again and Back to bag CTAs on failure', async () => {
      const { fixture } = setup({
        pollResult: { status: makeStatus({ status: 'cancelled', terminal: true, paid: false }), timedOut: false },
      });
      await flush();
      fixture.detectChanges();
      const again = fixture.nativeElement.querySelector('[data-testid="return-try-again"]') as HTMLAnchorElement;
      const bag = fixture.nativeElement.querySelector('[data-testid="return-back-to-bag"]') as HTMLAnchorElement;
      expect(again.getAttribute('href')).toBe('/checkout/review');
      expect(bag.getAttribute('href')).toBe('/cart');
    });

    it('shows the failed state when ref is missing', async () => {
      const { fixture, status } = setup({ ref: null });
      await flush();
      fixture.detectChanges();
      expect(status.pollCalls).toEqual([]);
      expect(fixture.nativeElement.querySelector('[data-testid="return-failed"]')).not.toBeNull();
    });

    it('shows the failed state when ref is empty/whitespace', async () => {
      const { fixture, status } = setup({ ref: '   ' });
      await flush();
      fixture.detectChanges();
      expect(status.pollCalls).toEqual([]);
      expect(fixture.nativeElement.querySelector('[data-testid="return-failed"]')).not.toBeNull();
    });

    it('shows the failed state if polling throws unexpectedly', async () => {
      const { fixture } = setup({ shouldThrow: true });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="return-failed"]')).not.toBeNull();
    });
  });

  describe('timeout outcome', () => {
    it('shows the processing state when polling times out', async () => {
      const { fixture, checkout, navigateByUrlSpy } = setup({
        pollResult: { status: makeStatus({ status: 'pending_payment', terminal: false, paid: false }), timedOut: true },
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="return-processing"]')).not.toBeNull();
      expect(checkout.clearCalls).toBe(0);
      expect(navigateByUrlSpy).not.toHaveBeenCalled();
    });

    it('shows the processing state when status is null + timed out', async () => {
      const { fixture } = setup({
        pollResult: { status: null, timedOut: true },
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="return-processing"]')).not.toBeNull();
    });

    it('processing state links to my orders', async () => {
      const { fixture } = setup({
        pollResult: { status: null, timedOut: true },
      });
      await flush();
      fixture.detectChanges();
      const link = fixture.nativeElement.querySelector('[data-testid="return-processing"]')
        ? fixture.nativeElement.querySelector('a[routerLink="/account/orders"]')
        : null;
      expect(link).not.toBeNull();
    });
  });

  describe('initial render', () => {
    it('shows the confirming spinner before polling resolves', () => {
      /* Build without flushing so the poll promise is still pending. */
      const status = new StubStatusService();
      let resolvePoll: (r: PollResult) => void = () => { /* set below */ };
      status.pollUntilTerminal = (ref: string) => {
        status.pollCalls.push(ref);
        return new Promise<PollResult>(res => { resolvePoll = res; });
      };
      const checkout = new StubCheckoutService();
      TestBed.configureTestingModule({
        imports: [CheckoutReturnPageComponent],
        providers: [
          provideRouter([]),
          provideHttpClient(),
          provideHttpClientTesting(),
          provideI18n(),
          { provide: CheckoutStatusService, useValue: status },
          { provide: CheckoutService, useValue: checkout },
          {
            provide: ActivatedRoute,
            useValue: { snapshot: { queryParamMap: { get: () => 'V3-PENDING' } } },
          },
        ],
      });
      const fixture = TestBed.createComponent(CheckoutReturnPageComponent);
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="return-confirming"]')).not.toBeNull();
      /* Clean up the dangling promise. */
      resolvePoll({ status: makeStatus(), timedOut: false });
    });
  });
});
