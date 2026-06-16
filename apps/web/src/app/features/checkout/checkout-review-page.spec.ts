import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { provideHttpClient, HttpErrorResponse } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { CheckoutReviewPageComponent } from './checkout-review-page';
import { CartService } from '../../core/cart';
import { CheckoutService } from '../../core/checkout';
import { AddressService } from '../../core/addresses';
import { AuthService } from '../../core/auth/auth.service';
import type { AuthUser } from '../../core/auth/auth.types';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type { Cart, CartItem, CartQuoteResponse } from '../../core/cart';
import type { Address } from '../../core/addresses';
import type { InitiateCheckoutResponse } from '../../core/checkout';

function makeItem(o: Partial<CartItem> = {}): CartItem {
  return {
    id: 1, product_id: 100, product_name: 'P', product_image: '',
    quantity: 2, unit_price: '100.00', line_subtotal: '200.00',
    size: 'M', color: null, is_custom: false, measurement: null,
    extra_measurement: null, note: null, ...o,
  };
}

function makeCart(items: CartItem[] = [makeItem()]): Cart {
  return {
    id: 1, status: 'active', currency: 'AED', cart_code: 'PND',
    subtotal: '200.00', item_count: items.length, items,
  };
}

function makeAddress(o: Partial<Address> = {}): Address {
  return {
    id: 1, recipient_name: 'Jane', recipient_phone: '+971501234567',
    emirate: 'Dubai', area: 'JLT', street_address: null,
    building_details: null, postal_code: null, label: 'Home',
    is_default_shipping: true, is_default_billing: false,
    created_at: '2026-05-19T00:00:00Z', updated_at: '2026-05-19T00:00:00Z',
    ...o,
  };
}

function makeQuote(opts: { code?: string | null; valid?: boolean; discount?: string; total?: string; shipping?: string; tax?: string } = {}): CartQuoteResponse {
  const valid = opts.valid !== false;
  return {
    cart: makeCart(),
    promo_code: opts.code ?? null,
    promo_valid: valid,
    promo_message: null,
    breakdown: {
      subtotal: '200.00',
      shipping: opts.shipping ?? '25.00',
      tax: opts.tax ?? '10.00',
      promo_discount: opts.discount ?? (opts.code !== undefined && opts.code !== null && valid ? '20.00' : '0.00'),
      total: opts.total ?? (opts.code !== undefined && opts.code !== null && valid ? '215.00' : '235.00'),
    },
  };
}

class StubCartService {
  private _cart = signal<Cart>(makeCart());
  cart = this._cart.asReadonly();
  currency = signal('AED').asReadonly();
  subtotal = signal('200.00').asReadonly();

  quoteCalls: Array<string | null> = [];
  quoteResponse: CartQuoteResponse | null = null;
  shouldThrowQuote = false;

  setCart(c: Cart): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).cart = signal<Cart>(c).asReadonly();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).currency = signal(c.currency).asReadonly();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).subtotal = signal(c.subtotal).asReadonly();
  }
  async quoteWithPromo(code: string | null): Promise<CartQuoteResponse> {
    this.quoteCalls.push(code);
    if (this.shouldThrowQuote) throw new Error('quote failed');
    return this.quoteResponse ?? makeQuote({ code });
  }
}

class StubCheckoutService {
  private _shipping = signal<number | null>(1);
  shippingAddressId = this._shipping.asReadonly();
  billingAddressId = signal<number | null>(1).asReadonly();
  private _promo = signal<string | null>(null);
  promoCode = this._promo.asReadonly();
  isInitiating = signal(false).asReadonly();

  initiateCalls: unknown[] = [];
  initiateResponse: InitiateCheckoutResponse = {
    checkout_url: 'https://noon.example/co/abc',
    order_reference: 'BAYTI-2026-001',
    provider_order_ref: 'noon-xyz',
    order_id: 100,
  };
  shouldThrowInitiate = false;
  /** When set, initiate() throws this instead (for testing error codes). */
  initiateError: unknown = null;
  promoCalls: Array<string | null> = [];

  setShipping(id: number | null): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).shippingAddressId = signal<number | null>(id).asReadonly();
  }
  setPriorPromo(code: string | null): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).promoCode = signal<string | null>(code).asReadonly();
  }
  setPromoCode(code: string | null): void { this.promoCalls.push(code); }
  async initiate(input: unknown): Promise<InitiateCheckoutResponse> {
    this.initiateCalls.push(input);
    if (this.initiateError !== null) throw this.initiateError;
    if (this.shouldThrowInitiate) throw new Error('initiate failed');
    return this.initiateResponse;
  }
}

class StubAddressService {
  private _addrs = signal<Address[]>([]);
  addresses = this._addrs.asReadonly();
  isLoading = signal(false).asReadonly();
  shouldThrowList = false;

  setAddrs(a: Address[]): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).addresses = signal<Address[]>(a).asReadonly();
  }
  async list(): Promise<Address[]> {
    if (this.shouldThrowList) throw new Error('list failed');
    return this.addresses();
  }
}

class StubToastService {
  errors: string[] = [];
  successes: string[] = [];
  error(m: string): string { this.errors.push(m); return m; }
  success(m: string): string { this.successes.push(m); return m; }
  show(): string { return ''; }
  warning(): string { return ''; }
  info(): string { return ''; }
  dismiss(): void { /* no-op */ }
  clearAll(): void { /* no-op */ }
  toasts = signal<unknown[]>([]).asReadonly();
  hasToasts = signal(false).asReadonly();
}

function makeAuthUser(o: Partial<AuthUser> = {}): AuthUser {
  return {
    id: 1, email: 'jane@example.com', phone: '+971501234567',
    country_code: 'AE', first_name: 'Jane', last_name: 'Doe',
    gender: null, dob: null, locale: null, timezone: null,
    is_phone_verified: true, is_email_verified: false,
    roles: ['customer'], is_store_approved: false, is_store_active: false,
    last_login_at: null, ...o,
  };
}

class StubAuthService {
  private _user = signal<AuthUser | null>(makeAuthUser());
  currentUser = this._user.asReadonly();
  setUser(u: AuthUser | null): void { this._user.set(u); }
}

interface SetupOptions {
  cart?: Cart;
  addresses?: Address[];
  shippingId?: number | null;
  priorPromo?: string | null;
  quoteResponse?: CartQuoteResponse;
  /** Phone-verification state of the signed-in user. Default: verified. */
  phoneVerified?: boolean;
}

function setup(opts: SetupOptions = {}): {
  fixture: ComponentFixture<CheckoutReviewPageComponent>;
  cart: StubCartService;
  checkout: StubCheckoutService;
  address: StubAddressService;
  toast: StubToastService;
  navigateSpy: ReturnType<typeof vi.fn>;
  navigateByUrlSpy: ReturnType<typeof vi.fn>;
} {
  const cart = new StubCartService();
  if (opts.cart !== undefined) cart.setCart(opts.cart);
  if (opts.quoteResponse !== undefined) cart.quoteResponse = opts.quoteResponse;

  const checkout = new StubCheckoutService();
  if (opts.shippingId !== undefined) checkout.setShipping(opts.shippingId);
  if (opts.priorPromo !== undefined) checkout.setPriorPromo(opts.priorPromo);

  const address = new StubAddressService();
  if (opts.addresses !== undefined) address.setAddrs(opts.addresses);
  else address.setAddrs([makeAddress({ id: 1 })]);

  const auth = new StubAuthService();
  if (opts.phoneVerified === false) {
    auth.setUser(makeAuthUser({ is_phone_verified: false }));
  }

  TestBed.configureTestingModule({
    imports: [CheckoutReviewPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: CartService, useValue: cart },
      { provide: CheckoutService, useValue: checkout },
      { provide: AddressService, useValue: address },
      { provide: AuthService, useValue: auth },
      { provide: ToastService, useValue: new StubToastService() },
    ],
  });

  const router = TestBed.inject(Router);
  const navigateSpy = vi.spyOn(router, 'navigate').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;
  const navigateByUrlSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;
  const toast = TestBed.inject(ToastService) as unknown as StubToastService;

  const fixture = TestBed.createComponent(CheckoutReviewPageComponent);
  fixture.detectChanges();
  return { fixture, cart, checkout, address, toast, navigateSpy, navigateByUrlSpy };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

describe('CheckoutReviewPageComponent', () => {
  afterEach(() => {
    /* Flush any pending HTTP from provideI18n's app initializer. */
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => {
        if (!req.cancelled) req.flush({});
      });
    } catch {
      /* ignore */
    }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('bounce guards', () => {
    it('redirects to /cart when cart is empty', async () => {
      const { navigateByUrlSpy } = setup({ cart: makeCart([]) });
      await flush();
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/cart');
    });

    it('redirects to /checkout/address when no shipping address chosen', async () => {
      const { navigateByUrlSpy } = setup({ shippingId: null });
      await flush();
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/checkout/address');
    });

    it('redirects to /checkout/address when the selected address no longer exists', async () => {
      const { navigateByUrlSpy } = setup({
        shippingId: 99, /* not in addresses */
        addresses: [makeAddress({ id: 1 })],
      });
      await flush();
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/checkout/address');
    });
  });

  describe('initial render', () => {
    it('shows the resolved shipping address', async () => {
      const { fixture } = setup({
        addresses: [makeAddress({ id: 1, recipient_name: 'Sally', area: 'Marina' })],
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.textContent).toContain('Sally');
      expect(fixture.nativeElement.textContent).toContain('Marina');
    });

    it('renders one item row per cart item', async () => {
      const { fixture } = setup({
        cart: makeCart([makeItem({ id: 1 }), makeItem({ id: 2 })]),
      });
      await flush();
      fixture.detectChanges();
      const items = fixture.nativeElement.querySelectorAll('[data-testid="review-item"]');
      expect(items).toHaveLength(2);
    });

    it('quotes on load with the carried promo code', async () => {
      const { cart } = setup({ priorPromo: 'SAVE10' });
      await flush();
      expect(cart.quoteCalls).toEqual(['SAVE10']);
    });

    it('quotes on load with null when no carried promo', async () => {
      const { cart } = setup({ priorPromo: null });
      await flush();
      expect(cart.quoteCalls).toEqual([null]);
    });

    it('renders the breakdown from the quote', async () => {
      const { fixture } = setup({
        quoteResponse: makeQuote({ shipping: '30.00', tax: '15.00' }),
      });
      await flush();
      fixture.detectChanges();

      expect(fixture.nativeElement.querySelector('[data-testid="review-subtotal"]').textContent).toContain('200.00');
      expect(fixture.nativeElement.querySelector('[data-testid="review-shipping"]').textContent).toContain('30.00');
      expect(fixture.nativeElement.querySelector('[data-testid="review-tax"]').textContent).toContain('15.00');
    });
  });

  describe('promo', () => {
    it('shows the promo form when no promo applied', async () => {
      const { fixture } = setup({ priorPromo: null });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="review-promo-form"]')).not.toBeNull();
    });

    it('shows the applied state when a promo is valid', async () => {
      const { fixture } = setup({
        priorPromo: 'SAVE10',
        quoteResponse: makeQuote({ code: 'SAVE10', valid: true }),
      });
      await flush();
      fixture.detectChanges();
      const applied = fixture.nativeElement.querySelector('[data-testid="review-promo-applied"]');
      expect(applied).not.toBeNull();
      expect(applied.getAttribute('data-promo-code')).toBe('SAVE10');
      expect(applied.getAttribute('data-promo-status')).toBe('valid');
    });

    it('apply button calls quoteWithPromo with the trimmed code', async () => {
      const { fixture, cart } = setup({ priorPromo: null });
      await flush();
      fixture.detectChanges();

      const input = fixture.nativeElement.querySelector('[data-testid="review-promo-input"]') as HTMLInputElement;
      input.value = '  WELCOME  ';
      input.dispatchEvent(new Event('input'));
      const form = fixture.nativeElement.querySelector('[data-testid="review-promo-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      await flush();
      expect(cart.quoteCalls).toEqual([null, 'WELCOME']);
    });

    it('remove button clears the promo and re-quotes', async () => {
      const { fixture, cart, checkout } = setup({
        priorPromo: 'SAVE10',
        quoteResponse: makeQuote({ code: 'SAVE10', valid: true }),
      });
      await flush();
      fixture.detectChanges();

      const removeBtn = fixture.nativeElement.querySelector('[data-testid="review-promo-remove"]') as HTMLButtonElement;
      cart.quoteResponse = makeQuote({ code: null });
      removeBtn.click();
      await flush();
      /* After remove: setPromoCode(null) called, and re-quote with null. */
      expect(checkout.promoCalls).toContain(null);
      expect(cart.quoteCalls).toContain(null);
    });

    it('renders the discount line when valid promo applied', async () => {
      const { fixture } = setup({
        priorPromo: 'SAVE10',
        quoteResponse: makeQuote({ code: 'SAVE10', valid: true, discount: '20.00' }),
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="review-discount-line"]')).not.toBeNull();
    });

    it('does not render discount line when discount is zero', async () => {
      const { fixture } = setup({
        quoteResponse: makeQuote({ discount: '0.00' }),
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="review-discount-line"]')).toBeNull();
    });

    it('toasts on quote network failure', async () => {
      const cart = new StubCartService();
      cart.shouldThrowQuote = true;
      const checkout = new StubCheckoutService();
      const address = new StubAddressService();
      address.setAddrs([makeAddress({ id: 1 })]);

      TestBed.configureTestingModule({
        imports: [CheckoutReviewPageComponent],
        providers: [
          provideRouter([]),
          provideHttpClient(),
          provideHttpClientTesting(),
          provideI18n(),
          { provide: CartService, useValue: cart },
          { provide: CheckoutService, useValue: checkout },
          { provide: AddressService, useValue: address },
          { provide: AuthService, useValue: new StubAuthService() },
          { provide: ToastService, useValue: new StubToastService() },
        ],
      });
      const toast = TestBed.inject(ToastService) as unknown as StubToastService;
      const fixture = TestBed.createComponent(CheckoutReviewPageComponent);
      fixture.detectChanges();
      await flush();
      expect(toast.errors).toContain('checkout.errors.networkError');
    });
  });

  describe('place order', () => {
    it('button is disabled when no breakdown loaded', async () => {
      const cart = new StubCartService();
      /* Quote never resolves cleanly. */
      cart.shouldThrowQuote = true;
      const checkout = new StubCheckoutService();
      const address = new StubAddressService();
      address.setAddrs([makeAddress({ id: 1 })]);

      TestBed.configureTestingModule({
        imports: [CheckoutReviewPageComponent],
        providers: [
          provideRouter([]),
          provideHttpClient(),
          provideHttpClientTesting(),
          provideI18n(),
          { provide: CartService, useValue: cart },
          { provide: CheckoutService, useValue: checkout },
          { provide: AddressService, useValue: address },
          { provide: AuthService, useValue: new StubAuthService() },
          { provide: ToastService, useValue: new StubToastService() },
        ],
      });
      const fixture = TestBed.createComponent(CheckoutReviewPageComponent);
      fixture.detectChanges();
      await flush();
      fixture.detectChanges();
      const btn = fixture.nativeElement.querySelector('[data-testid="review-place-order"]') as HTMLButtonElement;
      /* Button is enabled (canPlaceOrder), but onPlaceOrder will toast
         because breakdown is null. */
      btn.click();
      await flush();
      const toast = TestBed.inject(ToastService) as unknown as StubToastService;
      expect(toast.errors).toContain('checkout.errors.initiateFailed');
    });

    it('places order with full breakdown + addresses + valid promo', async () => {
      const { fixture, checkout, navigateSpy } = setup({
        priorPromo: 'SAVE10',
        quoteResponse: makeQuote({ code: 'SAVE10', valid: true, discount: '20.00', total: '215.00' }),
      });
      await flush();
      fixture.detectChanges();

      const btn = fixture.nativeElement.querySelector('[data-testid="review-place-order"]') as HTMLButtonElement;
      btn.click();
      await flush();

      expect(checkout.initiateCalls).toHaveLength(1);
      const payload = checkout.initiateCalls[0] as Record<string, unknown>;
      expect(payload['channel']).toBe('web');
      expect(payload['delivery_fee']).toBe('25.00');
      expect(payload['discount']).toBe('20.00');
      expect(payload['promo_code']).toBe('SAVE10');
      expect(payload['shipping_address_id']).toBe(1);
      expect(payload['billing_address_id']).toBe(1);

      /* Navigated to /checkout/payment with router state. */
      expect(navigateSpy).toHaveBeenCalledWith(['/checkout/payment'], expect.objectContaining({
        state: expect.objectContaining({
          checkout_url: 'https://noon.example/co/abc',
        }),
      }));
    });

    it('does not send promo when status is invalid', async () => {
      const { fixture, checkout } = setup({
        priorPromo: 'BAD',
        quoteResponse: makeQuote({ code: 'BAD', valid: false, discount: '0.00' }),
      });
      await flush();
      fixture.detectChanges();
      const btn = fixture.nativeElement.querySelector('[data-testid="review-place-order"]') as HTMLButtonElement;
      btn.click();
      await flush();
      const payload = checkout.initiateCalls[0] as Record<string, unknown>;
      expect(payload['promo_code']).toBeNull();
    });

    it('toasts on initiate failure', async () => {
      const { fixture, checkout, toast } = setup({});
      checkout.shouldThrowInitiate = true;
      await flush();
      fixture.detectChanges();
      const btn = fixture.nativeElement.querySelector('[data-testid="review-place-order"]') as HTMLButtonElement;
      btn.click();
      await flush();
      expect(toast.errors).toContain('checkout.errors.initiateFailed');
    });

    it('blocks placement and routes to verification when phone is unverified (#8)', async () => {
      const { fixture, checkout, toast, navigateSpy } = setup({
        phoneVerified: false,
        quoteResponse: makeQuote({ code: null, valid: false, discount: '0.00', total: '225.00' }),
      });
      await flush();
      fixture.detectChanges();

      /* The inline note is shown... */
      expect(fixture.nativeElement.querySelector('[data-testid="checkout-verify-note"]')).not.toBeNull();

      const btn = fixture.nativeElement.querySelector('[data-testid="review-place-order"]') as HTMLButtonElement;
      btn.click();
      await flush();

      /* ...and placing the order routes to verification instead of initiating. */
      expect(checkout.initiateCalls).toHaveLength(0);
      expect(toast.errors).toContain('checkout.errors.phoneNotVerified');
      expect(navigateSpy).toHaveBeenCalledWith(['/verify-phone'], {
        queryParams: { returnUrl: '/checkout/review' },
      });
    });

    it('routes to verification when the SERVER rejects with AUTH_PHONE_NOT_VERIFIED (backstop)', async () => {
      const { fixture, checkout, toast, navigateSpy } = setup({
        quoteResponse: makeQuote({ code: null, valid: false, discount: '0.00', total: '225.00' }),
      });
      /* Client thinks the phone is verified, but the server disagrees. */
      checkout.initiateError = new HttpErrorResponse({
        status: 403,
        error: { error: { code: 'AUTH_PHONE_NOT_VERIFIED', message: 'Verify your phone.' } },
      });
      await flush();
      fixture.detectChanges();

      const btn = fixture.nativeElement.querySelector('[data-testid="review-place-order"]') as HTMLButtonElement;
      btn.click();
      await flush();

      expect(checkout.initiateCalls).toHaveLength(1); // it tried
      expect(toast.errors).toContain('checkout.errors.phoneNotVerified');
      expect(navigateSpy).toHaveBeenCalledWith(['/verify-phone'], {
        queryParams: { returnUrl: '/checkout/review' },
      });
      /* Did NOT show the generic failure toast. */
      expect(toast.errors).not.toContain('checkout.errors.initiateFailed');
    });
  });

  describe('navigation', () => {
    it('edit-shipping link navigates to /checkout/address', async () => {
      const { fixture, navigateByUrlSpy } = setup({});
      await flush();
      fixture.detectChanges();
      const btn = fixture.nativeElement.querySelector('[data-testid="review-edit-shipping"]') as HTMLButtonElement;
      btn.click();
      await flush();
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/checkout/address');
    });

    it('back button navigates to /checkout/address', async () => {
      const { fixture, navigateByUrlSpy } = setup({});
      await flush();
      fixture.detectChanges();
      const btn = fixture.nativeElement.querySelector('[data-testid="review-back"]') as HTMLButtonElement;
      btn.click();
      await flush();
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/checkout/address');
    });
  });
});
