import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { CheckoutAddressPageComponent } from './checkout-address-page';
import { AddressService } from '../../core/addresses';
import { CheckoutService } from '../../core/checkout';
import { CartService } from '../../core/cart';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type { Address } from '../../core/addresses';
import type { Cart, CartItem } from '../../core/cart';

function makeAddress(overrides: Partial<Address> = {}): Address {
  return {
    id: 1,
    recipient_name: 'Jane',
    recipient_phone: '+971501234567',
    emirate: 'Dubai',
    area: 'JLT',
    street_address: null,
    building_details: null,
    postal_code: null,
    label: 'Home',
    is_default_shipping: false,
    is_default_billing: false,
    created_at: '2026-05-19T00:00:00Z',
    updated_at: '2026-05-19T00:00:00Z',
    ...overrides,
  };
}

function makeItem(overrides: Partial<CartItem> = {}): CartItem {
  return {
    id: 1, product_id: 100, product_name: 'P', product_image: '',
    quantity: 1, unit_price: '100.00', line_subtotal: '100.00',
    size: null, color: null, is_custom: false, measurement: null,
    extra_measurement: null, note: null, ...overrides,
  };
}

function makeCart(items: CartItem[] = [makeItem()]): Cart {
  return {
    id: 1, status: 'active', currency: 'AED', cart_code: 'PND',
    subtotal: '100.00', item_count: items.length, items,
  };
}

class StubAddressService {
  private _addrs = signal<Address[]>([]);
  addresses = this._addrs.asReadonly();
  isLoading = signal(false).asReadonly();
  listCalls = 0;
  shouldThrow = false;

  setAddrs(a: Address[]): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).addresses = signal<Address[]>(a).asReadonly();
  }
  async list(): Promise<Address[]> {
    this.listCalls++;
    if (this.shouldThrow) throw new Error('list failed');
    return this.addresses();
  }
}

class StubCartService {
  private _cart = signal<Cart>(makeCart());
  cart = this._cart.asReadonly();
  setCart(c: Cart): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).cart = signal<Cart>(c).asReadonly();
  }
}

class StubCheckoutService {
  private _shipping = signal<number | null>(null);
  shippingAddressId = this._shipping.asReadonly();
  billingAddressId = signal<number | null>(null).asReadonly();
  promoCode = signal<string | null>(null).asReadonly();
  setShippingCalls: number[] = [];

  setPrior(id: number | null): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).shippingAddressId = signal<number | null>(id).asReadonly();
  }
  setShippingAddress(id: number): void { this.setShippingCalls.push(id); }
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

function setup(opts: {
  addresses?: Address[];
  cart?: Cart;
  prior?: number | null;
} = {}): {
  fixture: ComponentFixture<CheckoutAddressPageComponent>;
  addressService: StubAddressService;
  checkout: StubCheckoutService;
  navigateByUrlSpy: ReturnType<typeof vi.fn>;
  toast: StubToastService;
} {
  const addressService = new StubAddressService();
  if (opts.addresses !== undefined) addressService.setAddrs(opts.addresses);

  const cartSvc = new StubCartService();
  if (opts.cart !== undefined) cartSvc.setCart(opts.cart);

  const checkout = new StubCheckoutService();
  if (opts.prior !== undefined) checkout.setPrior(opts.prior);

  TestBed.configureTestingModule({
    imports: [CheckoutAddressPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: AddressService, useValue: addressService },
      { provide: CartService, useValue: cartSvc },
      { provide: CheckoutService, useValue: checkout },
      { provide: ToastService, useValue: new StubToastService() },
    ],
  });

  const router = TestBed.inject(Router);
  const navigateByUrlSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;
  const toast = TestBed.inject(ToastService) as unknown as StubToastService;

  const fixture = TestBed.createComponent(CheckoutAddressPageComponent);
  fixture.detectChanges();
  return { fixture, addressService, checkout, navigateByUrlSpy, toast };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 6; i++) await Promise.resolve();
}

describe('CheckoutAddressPageComponent', () => {
  afterEach(() => {
    /* Flush any pending HTTP requests (typically the i18n GET fired
       by provideI18n's app initializer) before resetting the module,
       so we don't leak unhandled-promise warnings. */
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => {
        if (!req.cancelled) req.flush({});
      });
    } catch {
      /* If the module is already torn down, nothing to flush. */
    }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('empty-cart bounce', () => {
    it('redirects to /cart when cart is empty on init', async () => {
      const { navigateByUrlSpy } = setup({ cart: makeCart([]) });
      await flush();
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/cart');
    });
  });

  describe('address loading', () => {
    it('calls AddressService.list on init', async () => {
      const { addressService } = setup({ addresses: [makeAddress()] });
      await flush();
      expect(addressService.listCalls).toBe(1);
    });

    it('toasts on load failure', async () => {
      const addressService = new StubAddressService();
      addressService.shouldThrow = true;

      TestBed.configureTestingModule({
        imports: [CheckoutAddressPageComponent],
        providers: [
          provideRouter([]),
          provideHttpClient(),
          provideHttpClientTesting(),
          provideI18n(),
          { provide: AddressService, useValue: addressService },
          { provide: CartService, useValue: new StubCartService() },
          { provide: CheckoutService, useValue: new StubCheckoutService() },
          { provide: ToastService, useValue: new StubToastService() },
        ],
      });
      const toast = TestBed.inject(ToastService) as unknown as StubToastService;
      const fixture = TestBed.createComponent(CheckoutAddressPageComponent);
      fixture.detectChanges();
      await flush();
      expect(toast.errors).toContain('addresses.errors.loadFailed');
    });
  });

  describe('no addresses', () => {
    it('shows the add form when user has zero addresses', async () => {
      const { fixture } = setup({ addresses: [] });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="checkout-address-form-section"]')).not.toBeNull();
    });
  });

  describe('selection', () => {
    it('pre-selects default-shipping address when no prior choice', async () => {
      const { fixture } = setup({
        addresses: [
          makeAddress({ id: 1, is_default_shipping: false }),
          makeAddress({ id: 2, is_default_shipping: true }),
          makeAddress({ id: 3, is_default_shipping: false }),
        ],
      });
      await flush();
      fixture.detectChanges();
      const selected = fixture.nativeElement.querySelector('.address-pick--selected');
      expect(selected.getAttribute('data-testid')).toBe('address-pick-2');
    });

    it('falls back to first address when none is default', async () => {
      const { fixture } = setup({
        addresses: [makeAddress({ id: 5 }), makeAddress({ id: 9 })],
      });
      await flush();
      fixture.detectChanges();
      const selected = fixture.nativeElement.querySelector('.address-pick--selected');
      expect(selected.getAttribute('data-testid')).toBe('address-pick-5');
    });

    it('honours prior checkout-state selection', async () => {
      const { fixture } = setup({
        addresses: [makeAddress({ id: 1 }), makeAddress({ id: 2 })],
        prior: 2,
      });
      await flush();
      fixture.detectChanges();
      const selected = fixture.nativeElement.querySelector('.address-pick--selected');
      expect(selected.getAttribute('data-testid')).toBe('address-pick-2');
    });

    it('clicking a card selects it', async () => {
      const { fixture } = setup({
        addresses: [makeAddress({ id: 1 }), makeAddress({ id: 2 })],
      });
      await flush();
      fixture.detectChanges();

      const card2 = fixture.nativeElement.querySelector('[data-testid="address-pick-2"]') as HTMLElement;
      card2.click();
      fixture.detectChanges();
      expect(card2.classList.contains('address-pick--selected')).toBe(true);
    });
  });

  describe('continue', () => {
    it('continue button is disabled when no selection', async () => {
      const { fixture } = setup({ addresses: [] });
      await flush();
      fixture.detectChanges();
      const cta = fixture.nativeElement.querySelector('[data-testid="checkout-continue"]') as HTMLButtonElement;
      /* No addresses → no selection possible. */
      expect(cta.disabled).toBe(true);
    });

    it('continue advances to /checkout/review with shipping set', async () => {
      const { fixture, checkout, navigateByUrlSpy } = setup({
        addresses: [makeAddress({ id: 7, is_default_shipping: true })],
      });
      await flush();
      fixture.detectChanges();

      const cta = fixture.nativeElement.querySelector('[data-testid="checkout-continue"]') as HTMLButtonElement;
      cta.click();
      await flush();
      expect(checkout.setShippingCalls).toEqual([7]);
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/checkout/review');
    });
  });

  describe('back', () => {
    it('back button navigates to /cart', async () => {
      const { fixture, navigateByUrlSpy } = setup({ addresses: [makeAddress()] });
      await flush();
      fixture.detectChanges();

      const back = fixture.nativeElement.querySelector('[data-testid="checkout-back-to-cart"]') as HTMLButtonElement;
      back.click();
      await flush();
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/cart');
    });
  });

  describe('add new address', () => {
    it('clicking add link switches to form mode', async () => {
      const { fixture } = setup({ addresses: [makeAddress()] });
      await flush();
      fixture.detectChanges();

      const addBtn = fixture.nativeElement.querySelector('[data-testid="checkout-add-address"]') as HTMLButtonElement;
      addBtn.click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="checkout-address-form-section"]')).not.toBeNull();
    });
  });
});
