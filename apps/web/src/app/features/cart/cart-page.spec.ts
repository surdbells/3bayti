import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { CartPageComponent } from './cart-page';
import { CartService } from '../../core/cart';
import { AuthService } from '../../core/auth/auth.service';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type { Cart, CartItem, CartQuoteResponse } from '../../core/cart';

function makeItem(overrides: Partial<CartItem> = {}): CartItem {
  return {
    id: 1,
    product_id: 100,
    product_name: 'Product',
    product_image: 'https://example.com/p.jpg',
    quantity: 2,
    unit_price: '100.00',
    line_subtotal: '200.00',
    size: 'M',
    color: 'Black',
    is_custom: false,
    measurement: null,
    extra_measurement: null,
    note: null,
    ...overrides,
  };
}

function makeCart(items: CartItem[] = []): Cart {
  const item_count = items.reduce((acc, it) => acc + it.quantity, 0);
  const subtotal = items.reduce((acc, it) => acc + parseFloat(it.line_subtotal), 0).toFixed(2);
  return {
    id: 42,
    status: 'active',
    currency: 'AED',
    cart_code: 'PND',
    subtotal,
    item_count,
    items,
  };
}

function makeQuote(opts: { code: string; valid: boolean; discount?: string; total?: string } = { code: 'SAVE10', valid: true }): CartQuoteResponse {
  return {
    cart: makeCart([makeItem()]),
    promo_code: opts.code,
    promo_valid: opts.valid,
    promo_message: null,
    breakdown: {
      subtotal: '200.00',
      shipping: '25.00',
      tax: '10.00',
      promo_discount: opts.discount ?? (opts.valid ? '20.00' : '0.00'),
      total: opts.total ?? (opts.valid ? '215.00' : '235.00'),
    },
  };
}

class StubCartService {
  private _cart = signal<Cart>(makeCart());
  cart = this._cart.asReadonly();
  itemCount = signal(0).asReadonly();
  subtotal = signal('0.00').asReadonly();
  currency = signal('AED').asReadonly();
  isLoading = signal(false).asReadonly();

  refreshCalls = 0;
  updateQtyCalls: Array<{ id: number; qty: number }> = [];
  removeCalls: number[] = [];
  quotePromoCalls: Array<string | null> = [];

  quoteResponse: CartQuoteResponse | null = null;
  shouldThrowQuote = false;

  setCart(c: Cart): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).cart = signal<Cart>(c).asReadonly();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).itemCount = signal(c.item_count).asReadonly();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).subtotal = signal(c.subtotal).asReadonly();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).currency = signal(c.currency).asReadonly();
  }

  async refresh(): Promise<Cart> {
    this.refreshCalls++;
    return this.cart();
  }
  async updateQty(id: number, qty: number): Promise<Cart> {
    this.updateQtyCalls.push({ id, qty });
    return this.cart();
  }
  async removeItem(id: number): Promise<Cart> {
    this.removeCalls.push(id);
    return this.cart();
  }
  async quoteWithPromo(code: string | null): Promise<CartQuoteResponse> {
    this.quotePromoCalls.push(code);
    if (this.shouldThrowQuote) throw new Error('quote failed');
    return this.quoteResponse ?? makeQuote({ code: code ?? '', valid: true });
  }
}

class StubAuthService {
  isAuthenticated = signal(false).asReadonly();
  setAuth(v: boolean): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).isAuthenticated = signal(v).asReadonly();
  }
}

class StubToastService {
  errors: string[] = [];
  error(msg: string): string { this.errors.push(msg); return msg; }
  show(): string { return ''; }
  success(): string { return ''; }
  warning(): string { return ''; }
  info(): string { return ''; }
  dismiss(): void { /* no-op */ }
  clearAll(): void { /* no-op */ }
  toasts = signal<unknown[]>([]).asReadonly();
  hasToasts = signal(false).asReadonly();
}

function setup(opts: { items?: CartItem[]; authed?: boolean; quote?: CartQuoteResponse } = {}): {
  fixture: ComponentFixture<CartPageComponent>;
  component: CartPageComponent;
  cart: StubCartService;
  auth: StubAuthService;
  toast: StubToastService;
  navigateSpy: ReturnType<typeof vi.fn>;
  navigateByUrlSpy: ReturnType<typeof vi.fn>;
} {
  const cart = new StubCartService();
  if (opts.items !== undefined) cart.setCart(makeCart(opts.items));
  if (opts.quote !== undefined) cart.quoteResponse = opts.quote;

  const auth = new StubAuthService();
  if (opts.authed === true) auth.setAuth(true);

  TestBed.configureTestingModule({
    imports: [CartPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: CartService, useValue: cart },
      { provide: AuthService, useValue: auth },
      { provide: ToastService, useValue: new StubToastService() },
    ],
  });

  const router = TestBed.inject(Router);
  const navigateSpy = vi.spyOn(router, 'navigate').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;
  const navigateByUrlSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;
  const toast = TestBed.inject(ToastService) as unknown as StubToastService;

  const fixture = TestBed.createComponent(CartPageComponent);
  fixture.detectChanges();

  return { fixture, component: fixture.componentInstance, cart, auth, toast, navigateSpy, navigateByUrlSpy };
}

describe('CartPageComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  /* -----------------------------------------------------------------
     Rendering
     ----------------------------------------------------------------- */
  describe('rendering', () => {
    it('renders the empty state when cart has no items', () => {
      const { fixture } = setup({ items: [] });
      expect(fixture.nativeElement.querySelector('[data-testid="cart-page-empty"]')).not.toBeNull();
      expect(fixture.nativeElement.querySelector('[data-testid="cart-page-item"]')).toBeNull();
    });

    it('renders item rows when cart has items', () => {
      const { fixture } = setup({
        items: [makeItem({ id: 1 }), makeItem({ id: 2, product_name: 'Other' })],
      });
      const rows = fixture.nativeElement.querySelectorAll('[data-testid="cart-page-item"]');
      expect(rows).toHaveLength(2);
      expect(fixture.nativeElement.querySelector('[data-testid="cart-page-empty"]')).toBeNull();
    });

    it('shows the guest promo nudge when not authenticated', () => {
      const { fixture } = setup({ items: [makeItem()], authed: false });
      expect(fixture.nativeElement.querySelector('[data-testid="cart-guest-promo"]')).not.toBeNull();
      expect(fixture.nativeElement.querySelector('[data-testid="cart-promo-form"]')).toBeNull();
    });

    it('shows the promo form when authenticated', () => {
      const { fixture } = setup({ items: [makeItem()], authed: true });
      expect(fixture.nativeElement.querySelector('[data-testid="cart-promo-form"]')).not.toBeNull();
      expect(fixture.nativeElement.querySelector('[data-testid="cart-guest-promo"]')).toBeNull();
    });

    it('renders subtotal in the summary', () => {
      const { fixture } = setup({ items: [makeItem({ line_subtotal: '450.00' })] });
      const subtotal = fixture.nativeElement.querySelector('[data-testid="cart-summary-subtotal"]');
      expect(subtotal?.textContent).toContain('450.00');
      expect(subtotal?.textContent).toContain('AED');
    });

    it('refreshes cart on init', () => {
      const { cart } = setup({ items: [makeItem()] });
      expect(cart.refreshCalls).toBeGreaterThanOrEqual(1);
    });
  });

  /* -----------------------------------------------------------------
     Quantity controls
     ----------------------------------------------------------------- */
  describe('quantity controls', () => {
    it('increment button calls updateQty with qty+1', async () => {
      const { fixture, cart } = setup({ items: [makeItem({ id: 5, quantity: 2 })] });
      const btn = fixture.nativeElement.querySelector('[data-testid="cart-qty-inc-5"]') as HTMLButtonElement;
      btn.click();
      await Promise.resolve();
      expect(cart.updateQtyCalls).toEqual([{ id: 5, qty: 3 }]);
    });

    it('decrement button calls updateQty with qty-1', async () => {
      const { fixture, cart } = setup({ items: [makeItem({ id: 5, quantity: 4 })] });
      const btn = fixture.nativeElement.querySelector('[data-testid="cart-qty-dec-5"]') as HTMLButtonElement;
      btn.click();
      await Promise.resolve();
      expect(cart.updateQtyCalls).toEqual([{ id: 5, qty: 3 }]);
    });

    it('decrement is disabled at qty=1', () => {
      const { fixture } = setup({ items: [makeItem({ id: 5, quantity: 1 })] });
      const btn = fixture.nativeElement.querySelector('[data-testid="cart-qty-dec-5"]') as HTMLButtonElement;
      expect(btn.disabled).toBe(true);
    });

    it('increment is disabled at qty=99', () => {
      const { fixture } = setup({ items: [makeItem({ id: 5, quantity: 99 })] });
      const btn = fixture.nativeElement.querySelector('[data-testid="cart-qty-inc-5"]') as HTMLButtonElement;
      expect(btn.disabled).toBe(true);
    });
  });

  /* -----------------------------------------------------------------
     Remove
     ----------------------------------------------------------------- */
  describe('remove', () => {
    it('remove button calls CartService.removeItem with the line id', async () => {
      const { fixture, cart } = setup({ items: [makeItem({ id: 7 })] });
      const btn = fixture.nativeElement.querySelector('[data-testid="cart-remove-7"]') as HTMLButtonElement;
      btn.click();
      await Promise.resolve();
      expect(cart.removeCalls).toEqual([7]);
    });
  });

  /* -----------------------------------------------------------------
     Promo code
     ----------------------------------------------------------------- */
  describe('promo code (authenticated)', () => {
    it('apply button submits the form and calls quoteWithPromo', async () => {
      const { fixture, cart, component } = setup({ items: [makeItem()], authed: true });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).promoForm.controls.code.setValue('SAVE10');
      const form = fixture.nativeElement.querySelector('[data-testid="cart-promo-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      await Promise.resolve();
      await Promise.resolve();
      expect(cart.quotePromoCalls).toEqual(['SAVE10']);
    });

    it('trims whitespace from the promo code before submitting', async () => {
      const { fixture, cart, component } = setup({ items: [makeItem()], authed: true });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).promoForm.controls.code.setValue('  SAVE10  ');
      const form = fixture.nativeElement.querySelector('[data-testid="cart-promo-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      await Promise.resolve();
      await Promise.resolve();
      expect(cart.quotePromoCalls).toEqual(['SAVE10']);
    });

    it('renders the discount line when a valid promo applies', async () => {
      const { fixture, component } = setup({
        items: [makeItem()],
        authed: true,
        quote: makeQuote({ code: 'SAVE10', valid: true, discount: '20.00', total: '215.00' }),
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).promoForm.controls.code.setValue('SAVE10');
      const form = fixture.nativeElement.querySelector('[data-testid="cart-promo-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      await Promise.resolve();
      await Promise.resolve();
      fixture.detectChanges();

      const discount = fixture.nativeElement.querySelector('[data-testid="cart-summary-discount"]');
      expect(discount).not.toBeNull();
      expect(discount?.textContent).toContain('20.00');

      const total = fixture.nativeElement.querySelector('[data-testid="cart-summary-total"]');
      expect(total?.textContent).toContain('215.00');
    });

    it('marks promo as invalid when the API returns promo_valid=false', async () => {
      const { fixture, component } = setup({
        items: [makeItem()],
        authed: true,
        quote: makeQuote({ code: 'BAD', valid: false }),
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).promoForm.controls.code.setValue('BAD');
      const form = fixture.nativeElement.querySelector('[data-testid="cart-promo-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      await Promise.resolve();
      await Promise.resolve();
      fixture.detectChanges();

      const status = fixture.nativeElement.querySelector('[data-testid="cart-promo-status"]');
      expect(status).not.toBeNull();
      expect(status?.classList.contains('cart-page__promo-status--invalid')).toBe(true);
    });

    it('toasts on promo network failure', async () => {
      const { fixture, cart, toast, component } = setup({ items: [makeItem()], authed: true });
      cart.shouldThrowQuote = true;
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).promoForm.controls.code.setValue('FAIL');
      const form = fixture.nativeElement.querySelector('[data-testid="cart-promo-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      await Promise.resolve();
      await Promise.resolve();
      expect(toast.errors).toContain('cart.page.errors.promoFailed');
    });
  });

  /* -----------------------------------------------------------------
     Checkout CTA
     ----------------------------------------------------------------- */
  describe('checkout CTA', () => {
    it('routes guests to /login with returnUrl=/checkout/address', async () => {
      const { fixture, navigateSpy } = setup({ items: [makeItem()], authed: false });
      const btn = fixture.nativeElement.querySelector('[data-testid="cart-checkout-cta"]') as HTMLButtonElement;
      btn.click();
      await Promise.resolve();
      expect(navigateSpy).toHaveBeenCalledWith(['/login'], {
        queryParams: { returnUrl: '/checkout/address' },
      });
    });

    it('routes authenticated users directly to /checkout/address', async () => {
      const { fixture, navigateByUrlSpy } = setup({ items: [makeItem()], authed: true });
      const btn = fixture.nativeElement.querySelector('[data-testid="cart-checkout-cta"]') as HTMLButtonElement;
      btn.click();
      await Promise.resolve();
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/checkout/address');
    });
  });
});
