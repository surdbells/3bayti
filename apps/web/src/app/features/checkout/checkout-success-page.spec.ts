import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { CheckoutSuccessPageComponent } from './checkout-success-page';
import { OrderService } from '../../core/orders';
import { CheckoutService } from '../../core/checkout';
import { CartService } from '../../core/cart';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type { Order, OrderItem, OrderAddress } from '../../core/orders';
import type { Cart } from '../../core/cart';

function makeAddr(): OrderAddress {
  return {
    id: 1, recipient_name: 'Jane', recipient_phone: '+971501234567',
    emirate: 'Dubai', area: 'JLT', street_address: 'Beach Rd',
    building_details: 'Tower B', postal_code: '12345', label: 'Home',
  };
}

function makeOrderItem(o: Partial<OrderItem> = {}): OrderItem {
  return {
    id: 1, product_id: 100, vendor_id: 5, product_name: 'Item',
    product_image: 'https://example.com/i.jpg', quantity: 2,
    unit_price: '100.00', subtotal: '200.00', size: 'M', color: null,
    is_custom: false, measurement: null, extra_measurement: null,
    note: null, item_status: 'paid', store: 5, ...o,
  };
}

function makeOrder(o: Partial<Order> = {}): Order {
  return {
    id: 42, order_reference: 'BAYTI-2026-042', status: 'paid',
    date: '2026-05-19T10:00:00Z', subtotal: '200.00', delivery_fee: '25.00',
    discount: '0.00', total: '225.00', currency: 'AED',
    paid_at: '2026-05-19T10:05:00Z', items: [makeOrderItem()],
    applied_promo: null,
    shipping_address: makeAddr(), billing_address: makeAddr(),
    ...o,
  } as Order;
}

class StubOrderService {
  isLoadingDetail = signal(false).asReadonly();
  getByIdCalls: number[] = [];
  orderResponse: Order = makeOrder();
  shouldThrow = false;

  async getById(id: number): Promise<Order> {
    this.getByIdCalls.push(id);
    if (this.shouldThrow) throw new Error('not found');
    return this.orderResponse;
  }
}

class StubCheckoutService {
  shippingAddressId = signal<number | null>(null).asReadonly();
  billingAddressId = signal<number | null>(null).asReadonly();
  promoCode = signal<string | null>(null).asReadonly();
  isInitiating = signal(false).asReadonly();
  clearCalls = 0;
  clear(): void { this.clearCalls++; }
  setShippingAddress(): void { /* no-op */ }
  setBillingAddress(): void { /* no-op */ }
  setPromoCode(): void { /* no-op */ }
}

class StubCartService {
  refreshCalls = 0;
  shouldThrowRefresh = false;
  private _cart = signal<Cart>({
    id: 0, status: 'active', currency: 'AED', cart_code: 'PND',
    subtotal: '0.00', item_count: 0, items: [],
  });
  cart = this._cart.asReadonly();
  currency = signal('AED').asReadonly();
  subtotal = signal('0.00').asReadonly();
  itemCount = signal(0).asReadonly();
  isLoading = signal(false).asReadonly();
  async refresh(): Promise<Cart> {
    this.refreshCalls++;
    if (this.shouldThrowRefresh) throw new Error('refresh failed');
    return this.cart();
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

function setup(opts: {
  routeId?: string | null;
  order?: Order;
  shouldThrow?: boolean;
} = {}): {
  fixture: ComponentFixture<CheckoutSuccessPageComponent>;
  orderService: StubOrderService;
  checkout: StubCheckoutService;
  cart: StubCartService;
  toast: StubToastService;
} {
  const orderService = new StubOrderService();
  if (opts.order !== undefined) orderService.orderResponse = opts.order;
  if (opts.shouldThrow === true) orderService.shouldThrow = true;

  const checkout = new StubCheckoutService();
  const cart = new StubCartService();

  const idParam = opts.routeId !== undefined ? opts.routeId : '42';
  const activatedRouteStub = {
    snapshot: {
      paramMap: new Map([
        ...(idParam !== null ? [['id', idParam]] : []),
      ] as Iterable<[string, string]>),
    },
  };
  /* Wrap Map to ActivatedRoute.snapshot.paramMap shape (get). */
  const paramMap = {
    get(key: string): string | null {
      return idParam !== null && key === 'id' ? idParam : null;
    },
  };
  activatedRouteStub.snapshot.paramMap = paramMap as unknown as Map<string, string>;

  TestBed.configureTestingModule({
    imports: [CheckoutSuccessPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: OrderService, useValue: orderService },
      { provide: CheckoutService, useValue: checkout },
      { provide: CartService, useValue: cart },
      { provide: ToastService, useValue: new StubToastService() },
      { provide: ActivatedRoute, useValue: activatedRouteStub },
    ],
  });

  const toast = TestBed.inject(ToastService) as unknown as StubToastService;
  const fixture = TestBed.createComponent(CheckoutSuccessPageComponent);
  fixture.detectChanges();
  return { fixture, orderService, checkout, cart, toast };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 6; i++) await Promise.resolve();
}

describe('CheckoutSuccessPageComponent', () => {
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

  describe('on landing with valid order id', () => {
    it('fetches the order by id and renders the hero', async () => {
      const { fixture, orderService } = setup({ routeId: '42' });
      await flush();
      fixture.detectChanges();
      expect(orderService.getByIdCalls).toEqual([42]);
      expect(fixture.nativeElement.querySelector('[data-testid="success-hero"]')).not.toBeNull();
    });

    it('shows the order reference', async () => {
      const { fixture } = setup({
        order: makeOrder({ order_reference: 'BAYTI-2026-007' }),
      });
      await flush();
      fixture.detectChanges();
      const ref = fixture.nativeElement.querySelector('[data-testid="success-order-ref"]');
      expect(ref?.textContent?.trim()).toBe('BAYTI-2026-007');
    });

    it('renders one row per item', async () => {
      const { fixture } = setup({
        order: makeOrder({
          items: [makeOrderItem({ id: 1 }), makeOrderItem({ id: 2 })],
        }),
      });
      await flush();
      fixture.detectChanges();
      const rows = fixture.nativeElement.querySelectorAll('[data-testid="success-item"]');
      expect(rows).toHaveLength(2);
    });

    it('renders the total with currency', async () => {
      const { fixture } = setup({
        order: makeOrder({ total: '225.50', currency: 'AED' }),
      });
      await flush();
      fixture.detectChanges();
      const total = fixture.nativeElement.querySelector('[data-testid="success-total"]');
      expect(total?.textContent).toContain('225.50');
      expect(total?.textContent).toContain('AED');
    });

    it('clears checkout state and refreshes the cart on success', async () => {
      const { checkout, cart } = setup({});
      await flush();
      expect(checkout.clearCalls).toBe(1);
      expect(cart.refreshCalls).toBe(1);
    });

    it('still clears checkout state even if cart refresh fails', async () => {
      const orderService = new StubOrderService();
      const checkout = new StubCheckoutService();
      const cart = new StubCartService();
      cart.shouldThrowRefresh = true;

      TestBed.configureTestingModule({
        imports: [CheckoutSuccessPageComponent],
        providers: [
          provideRouter([]),
          provideHttpClient(),
          provideHttpClientTesting(),
          provideI18n(),
          { provide: OrderService, useValue: orderService },
          { provide: CheckoutService, useValue: checkout },
          { provide: CartService, useValue: cart },
          { provide: ToastService, useValue: new StubToastService() },
          { provide: ActivatedRoute, useValue: { snapshot: { paramMap: { get: () => '42' } } } },
        ],
      });
      const fixture = TestBed.createComponent(CheckoutSuccessPageComponent);
      fixture.detectChanges();
      await flush();
      expect(checkout.clearCalls).toBe(1);
    });
  });

  describe('error paths', () => {
    it('shows the error state when id is missing', async () => {
      const { fixture, orderService } = setup({ routeId: null });
      await flush();
      fixture.detectChanges();
      expect(orderService.getByIdCalls).toEqual([]);
      expect(fixture.nativeElement.querySelector('[data-testid="success-error"]')).not.toBeNull();
    });

    it('shows the error state when id is not a number', async () => {
      const { fixture, orderService } = setup({ routeId: 'not-a-number' });
      await flush();
      fixture.detectChanges();
      expect(orderService.getByIdCalls).toEqual([]);
      expect(fixture.nativeElement.querySelector('[data-testid="success-error"]')).not.toBeNull();
    });

    it('shows the error state when the order fetch throws', async () => {
      const { fixture, toast } = setup({ shouldThrow: true });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="success-error"]')).not.toBeNull();
      expect(toast.errors).toContain('checkout.errors.initiateFailed');
    });

    it('does not clear checkout state when the order fetch fails', async () => {
      const { checkout } = setup({ shouldThrow: true });
      await flush();
      expect(checkout.clearCalls).toBe(0);
    });
  });

  describe('display polish', () => {
    it('renders discount line only when discount > 0', async () => {
      const { fixture: f1 } = setup({ order: makeOrder({ discount: '0.00' }) });
      await flush();
      f1.detectChanges();
      /* When discount is zero, no discount row in totals. The
         summary lines for subtotal+shipping+total still render. */
      const dts = f1.nativeElement.querySelectorAll('.checkout-summary__line dt');
      const labels = Array.from(dts as NodeListOf<HTMLElement>).map(el => el.textContent?.trim() ?? '');
      /* Translation pipe returns the i18n key in tests; we just
         confirm the discount label isn't rendered. */
      expect(labels).not.toContain('checkout.review.discount');
    });
  });
});
