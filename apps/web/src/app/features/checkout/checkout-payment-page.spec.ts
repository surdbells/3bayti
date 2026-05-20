import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { CheckoutPaymentPageComponent } from './checkout-payment-page';
import { CheckoutService } from '../../core/checkout';
import { provideI18n } from '../../core/i18n';

class StubCheckoutService {
  shippingAddressId = signal<number | null>(1).asReadonly();
  billingAddressId = signal<number | null>(1).asReadonly();
  promoCode = signal<string | null>(null).asReadonly();
  isInitiating = signal(false).asReadonly();
  setShippingAddress(): void { /* no-op */ }
  setBillingAddress(): void { /* no-op */ }
  setPromoCode(): void { /* no-op */ }
  clear(): void { /* no-op */ }
}

/** Build a fixture WITHOUT calling detectChanges so the caller can
 *  set router state on window.history before ngOnInit runs. */
function build(): {
  fixture: ComponentFixture<CheckoutPaymentPageComponent>;
  navigateByUrlSpy: ReturnType<typeof vi.fn>;
  redirectSpy: ReturnType<typeof vi.fn>;
} {
  TestBed.configureTestingModule({
    imports: [CheckoutPaymentPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: CheckoutService, useValue: new StubCheckoutService() },
    ],
  });
  const router = TestBed.inject(Router);
  const navigateByUrlSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;
  /* Stub the protected redirectTo via prototype so we don't have to
     mutate the non-configurable window.location.assign. */
  const redirectSpy = vi.spyOn(
    CheckoutPaymentPageComponent.prototype as unknown as { redirectTo: (url: string) => void },
    'redirectTo',
  ).mockImplementation(() => undefined) as unknown as ReturnType<typeof vi.fn>;
  const fixture = TestBed.createComponent(CheckoutPaymentPageComponent);
  return { fixture, navigateByUrlSpy, redirectSpy };
}

/** Push router state onto history before detectChanges so ngOnInit
 *  sees it. */
function withRouterState(state: { checkout_url?: string; order_reference?: string } | null): void {
  if (state === null) {
    history.replaceState({}, '', window.location.href);
  } else {
    history.replaceState(state, '', window.location.href);
  }
}

describe('CheckoutPaymentPageComponent', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    /* Flush pending HTTP from provideI18n initializer. */
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => {
        if (!req.cancelled) req.flush({});
      });
    } catch {
      /* ignore */
    }
    vi.useRealTimers();
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
    /* Reset history state between tests. */
    history.replaceState({}, '', window.location.href);
  });

  describe('with router state', () => {
    it('renders the handoff UI with the checkout URL', () => {
      withRouterState({
        checkout_url: 'https://noon.example/co/abc',
        order_reference: 'BAYTI-2026-001',
      });
      const { fixture } = build();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="payment-status"]')).not.toBeNull();
      expect(fixture.nativeElement.querySelector('[data-testid="payment-missing-state"]')).toBeNull();
    });

    it('exposes the manual fallback link pointing to the checkout URL', () => {
      withRouterState({
        checkout_url: 'https://noon.example/co/abc',
        order_reference: 'BAYTI-2026-001',
      });
      const { fixture } = build();
      fixture.detectChanges();
      const link = fixture.nativeElement.querySelector('[data-testid="payment-manual-link"]') as HTMLAnchorElement;
      expect(link).not.toBeNull();
      expect(link.getAttribute('href')).toBe('https://noon.example/co/abc');
    });

    it('renders the order reference when present', () => {
      withRouterState({
        checkout_url: 'https://noon.example/co/abc',
        order_reference: 'BAYTI-2026-007',
      });
      const { fixture } = build();
      fixture.detectChanges();
      const ref = fixture.nativeElement.querySelector('[data-testid="payment-order-ref"]');
      expect(ref?.textContent?.trim()).toBe('BAYTI-2026-007');
    });

    it('hides the order reference element when not provided', () => {
      withRouterState({ checkout_url: 'https://noon.example/co/abc' });
      const { fixture } = build();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="payment-order-ref"]')).toBeNull();
    });

    it('calls the redirect after the redirect delay', () => {
      withRouterState({
        checkout_url: 'https://noon.example/co/abc',
        order_reference: 'BAYTI-2026-001',
      });
      const { fixture, redirectSpy } = build();
      fixture.detectChanges();

      /* Before the timer fires, no redirect. */
      expect(redirectSpy).not.toHaveBeenCalled();

      vi.advanceTimersByTime(600);
      expect(redirectSpy).toHaveBeenCalledWith('https://noon.example/co/abc');
    });

    it('does not bounce to /checkout/review when state is present', () => {
      withRouterState({
        checkout_url: 'https://noon.example/co/abc',
      });
      const { fixture, navigateByUrlSpy } = build();
      fixture.detectChanges();

      vi.advanceTimersByTime(2000);
      expect(navigateByUrlSpy).not.toHaveBeenCalled();
    });

    it('renders the manual link as the fallback when auto-redirect is blocked', () => {
      withRouterState({ checkout_url: 'https://noon.example/co/abc' });
      const { fixture, redirectSpy } = build();
      /* Simulate a redirect that silently fails (the production
         code catches and ignores). The manual link should still be
         rendered and functional. */
      redirectSpy.mockImplementation(() => {
        /* swallow */
      });
      fixture.detectChanges();
      vi.advanceTimersByTime(600);
      const link = fixture.nativeElement.querySelector('[data-testid="payment-manual-link"]') as HTMLAnchorElement;
      expect(link.getAttribute('href')).toBe('https://noon.example/co/abc');
    });
  });

  describe('without router state (refresh case)', () => {
    it('shows the missing-state message + back button', () => {
      withRouterState(null);
      const { fixture } = build();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="payment-missing-state"]')).not.toBeNull();
      expect(fixture.nativeElement.querySelector('[data-testid="payment-back-to-review"]')).not.toBeNull();
      expect(fixture.nativeElement.querySelector('[data-testid="payment-status"]')).toBeNull();
    });

    it('bounces to /checkout/review after the grace period', () => {
      withRouterState(null);
      const { fixture, navigateByUrlSpy } = build();
      fixture.detectChanges();
      vi.advanceTimersByTime(300);
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/checkout/review');
    });

    it('does NOT trigger the redirect', () => {
      withRouterState(null);
      const { fixture, redirectSpy } = build();
      fixture.detectChanges();
      vi.advanceTimersByTime(2000);
      expect(redirectSpy).not.toHaveBeenCalled();
    });

    it('back button navigates to /checkout/review immediately', async () => {
      withRouterState(null);
      const { fixture, navigateByUrlSpy } = build();
      fixture.detectChanges();
      const btn = fixture.nativeElement.querySelector('[data-testid="payment-back-to-review"]') as HTMLButtonElement;
      btn.click();
      await Promise.resolve();
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/checkout/review');
    });

    it('ignores non-string checkout_url in router state', () => {
      withRouterState({ checkout_url: 123 as unknown as string });
      const { fixture, navigateByUrlSpy } = build();
      fixture.detectChanges();
      vi.advanceTimersByTime(300);
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/checkout/review');
    });
  });
});
