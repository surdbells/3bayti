import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { AccountCustomizationPageComponent } from './account-customization-page';
import { CustomizationService } from './customization.service';
import { CheckoutService } from '../../core/checkout/checkout.service';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type { CustomizationRequest, CustomizationRequestsPage } from './customization.model';

function makeRequest(o: Partial<CustomizationRequest> = {}): CustomizationRequest {
  return {
    id: 1,
    status: 'quoted',
    product: { id: 10, slug: 'silk-abaya', name: 'Silk Abaya', primary_image: null, price: '500.00', sale_price: null },
    vendor: { id: 5, name: 'Atelier Noor', slug: 'atelier-noor' },
    customer_notes: 'Add gold embroidery',
    measurement_snapshot: null,
    quote: { amount: '150.00', currency: 'AED', lead_time_days: 7, vendor_notes: 'Two weeks' },
    payment_order_reference: null,
    timestamps: {
      requested_at: '2026-09-23T10:00:00Z', quoted_at: '2026-09-23T11:00:00Z',
      accepted_at: null, paid_at: null, completed_at: null,
      declined_at: null, rejected_at: null, cancelled_at: null,
    },
    is_terminal: false,
    ...o,
  };
}

class StubCustomizationService {
  private _loading = signal(false);
  isLoading = this._loading.asReadonly();
  isActing = signal(false).asReadonly();
  isSubmitting = signal(false).asReadonly();

  page: CustomizationRequestsPage = { items: [], hasMore: false, total: 0 };
  listThrows = false;
  acceptResult: CustomizationRequest | null = null;
  acceptCalls: number[] = [];

  async list(): Promise<CustomizationRequestsPage> {
    if (this.listThrows) throw new Error('load failed');
    return this.page;
  }
  async accept(id: number): Promise<CustomizationRequest> {
    this.acceptCalls.push(id);
    return this.acceptResult ?? makeRequest({ id, status: 'accepted' });
  }
  async reject(id: number): Promise<CustomizationRequest> { return makeRequest({ id, status: 'rejected' }); }
  async cancel(id: number): Promise<CustomizationRequest> { return makeRequest({ id, status: 'cancelled' }); }
}

class StubCheckout {
  isInitiating = signal(false).asReadonly();
  initiate = vi.fn();
}

class StubToast {
  calls: Array<{ kind: string; msg: string }> = [];
  success(m: string): string { this.calls.push({ kind: 'success', msg: m }); return ''; }
  error(m: string): string { this.calls.push({ kind: 'error', msg: m }); return ''; }
  info(m: string): string { this.calls.push({ kind: 'info', msg: m }); return ''; }
  warning(m: string): string { this.calls.push({ kind: 'warning', msg: m }); return ''; }
}

function setup(page: CustomizationRequestsPage): {
  fixture: ComponentFixture<AccountCustomizationPageComponent>;
  service: StubCustomizationService;
  toast: StubToast;
} {
  const service = new StubCustomizationService();
  service.page = page;
  const toast = new StubToast();

  TestBed.configureTestingModule({
    imports: [AccountCustomizationPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: CustomizationService, useValue: service },
      { provide: CheckoutService, useValue: new StubCheckout() },
      { provide: ToastService, useValue: toast },
    ],
  });
  const fixture = TestBed.createComponent(AccountCustomizationPageComponent);
  fixture.detectChanges();
  return { fixture, service, toast };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

describe('AccountCustomizationPageComponent', () => {
  afterEach(() => {
    // Absorb the TranslateService's /i18n/*.json loader request(s) so they
    // don't surface as unhandled HTTP errors.
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach((req) => { if (!req.cancelled) req.flush({}); });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders the empty state when there are no requests', async () => {
    const { fixture } = setup({ items: [], hasMore: false, total: 0 });
    await flush();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[data-testid="cust-empty"]')).not.toBeNull();
  });

  it('renders a quoted request with accept + reject controls', async () => {
    const { fixture } = setup({ items: [makeRequest()], hasMore: false, total: 1 });
    await flush();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[data-testid="cust-card-1"]')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid="cust-accept-1"]')).not.toBeNull();
    // The quote amount is shown.
    expect(fixture.nativeElement.textContent).toContain('150.00');
  });

  it('accepts a quote and swaps the row to the Pay action', async () => {
    const { fixture, service } = setup({ items: [makeRequest()], hasMore: false, total: 1 });
    await flush();
    fixture.detectChanges();
    (fixture.nativeElement.querySelector('[data-testid="cust-accept-1"]') as HTMLButtonElement).click();
    await flush();
    fixture.detectChanges();
    expect(service.acceptCalls).toEqual([1]);
    // Now accepted → the Pay button is rendered, accept is gone.
    expect(fixture.nativeElement.querySelector('[data-testid="cust-pay-1"]')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid="cust-accept-1"]')).toBeNull();
  });

  it('toasts on load failure', async () => {
    const service = new StubCustomizationService();
    service.listThrows = true;
    const toast = new StubToast();
    TestBed.configureTestingModule({
      imports: [AccountCustomizationPageComponent],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideI18n(),
        { provide: CustomizationService, useValue: service },
        { provide: CheckoutService, useValue: new StubCheckout() },
        { provide: ToastService, useValue: toast },
      ],
    });
    const fixture = TestBed.createComponent(AccountCustomizationPageComponent);
    fixture.detectChanges();
    await flush();
    expect(toast.calls.some((c) => c.kind === 'error')).toBe(true);
  });
});
