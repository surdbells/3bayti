import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { AccountOrderDetailPageComponent } from './account-order-detail-page';
import { OrderService } from '../../core/orders';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type {
  Order,
  OrderItem,
  OrderAddress,
  OrderTimelineEvent,
  ReturnSummary,
} from '../../core/orders';

function makeAddr(o: Partial<OrderAddress> = {}): OrderAddress {
  return {
    first_name: 'Jane', last_name: 'Doe', phone: '+971501234567',
    email: 'jane@example.com', street: 'Beach Rd', city: 'Dubai',
    state_province: 'JLT', country_code: 'AE', postal_code: '12345',
    ...o,
  };
}

function makeOrderItem(o: Partial<OrderItem> = {}): OrderItem {
  return {
    id: 1, product_id: 100, vendor_id: 5, product_name: 'Item',
    product_image: 'https://example.com/i.jpg', quantity: 1,
    unit_price: '100.00', subtotal: '100.00', size: 'M', color: null,
    is_custom: false, measurement: null, extra_measurement: null,
    note: null, item_status: 'paid', store: 5, ...o,
  };
}

function makeOrder(o: Partial<Order> = {}): Order {
  return {
    id: 42, order_reference: 'BAYTI-2026-042', status: 'paid',
    date: '2026-05-19T10:00:00Z', subtotal: '100.00', delivery_fee: '25.00',
    discount: '0.00', total: '125.00', currency: 'AED',
    paid_at: '2026-05-19T10:05:00Z',
    items: [makeOrderItem()], applied_promo: null,
    shipping_address: makeAddr(), billing_address: makeAddr(),
    ...o,
  } as Order;
}

class StubOrderService {
  isLoadingDetail = signal(false).asReadonly();

  getByIdCalls: number[] = [];
  cancelCalls: number[] = [];
  timelineCalls: number[] = [];
  orderResponse: Order = makeOrder();
  cancelResponse: Order = makeOrder({ status: 'cancelled' });
  timelineResponse: OrderTimelineEvent[] = [];
  shouldThrowGet = false;
  shouldThrowCancel = false;

  async getById(id: number): Promise<Order> {
    this.getByIdCalls.push(id);
    if (this.shouldThrowGet) throw new Error('get failed');
    return this.orderResponse;
  }
  async getTimeline(id: number): Promise<OrderTimelineEvent[]> {
    this.timelineCalls.push(id);
    return this.timelineResponse;
  }
  async cancel(id: number): Promise<Order> {
    this.cancelCalls.push(id);
    if (this.shouldThrowCancel) throw new Error('cancel failed');
    return this.cancelResponse;
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
  shouldThrowGet?: boolean;
  shouldThrowCancel?: boolean;
  cancelResponse?: Order;
  timeline?: OrderTimelineEvent[];
} = {}): {
  fixture: ComponentFixture<AccountOrderDetailPageComponent>;
  orderService: StubOrderService;
  toast: StubToastService;
} {
  const orderService = new StubOrderService();
  if (opts.order !== undefined) orderService.orderResponse = opts.order;
  if (opts.cancelResponse !== undefined) orderService.cancelResponse = opts.cancelResponse;
  if (opts.timeline !== undefined) orderService.timelineResponse = opts.timeline;
  if (opts.shouldThrowGet === true) orderService.shouldThrowGet = true;
  if (opts.shouldThrowCancel === true) orderService.shouldThrowCancel = true;

  const idParam = opts.routeId !== undefined ? opts.routeId : '42';
  const activatedRouteStub = {
    snapshot: {
      paramMap: {
        get: (key: string): string | null =>
          idParam !== null && key === 'id' ? idParam : null,
      },
    },
  };

  TestBed.configureTestingModule({
    imports: [AccountOrderDetailPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: OrderService, useValue: orderService },
      { provide: ToastService, useValue: new StubToastService() },
      { provide: ActivatedRoute, useValue: activatedRouteStub },
    ],
  });

  const toast = TestBed.inject(ToastService) as unknown as StubToastService;
  const fixture = TestBed.createComponent(AccountOrderDetailPageComponent);
  fixture.detectChanges();
  return { fixture, orderService, toast };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 6; i++) await Promise.resolve();
}

describe('AccountOrderDetailPageComponent', () => {
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

  describe('loading the order', () => {
    it('fetches by :id from the route param', async () => {
      const { orderService } = setup({ routeId: '77' });
      await flush();
      expect(orderService.getByIdCalls).toEqual([77]);
    });

    it('renders the order reference + status', async () => {
      const { fixture } = setup({
        order: makeOrder({ order_reference: 'BAYTI-2026-099', status: 'paid' }),
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.textContent).toContain('BAYTI-2026-099');
      const status = fixture.nativeElement.querySelector('[data-testid="order-detail-status"]');
      expect(status?.getAttribute('data-status')).toBe('paid');
      expect(status?.classList.contains('order-card__status--positive')).toBe(true);
    });

    it('renders one row per item', async () => {
      const { fixture } = setup({
        order: makeOrder({
          items: [makeOrderItem({ id: 1 }), makeOrderItem({ id: 2 }), makeOrderItem({ id: 3 })],
        }),
      });
      await flush();
      fixture.detectChanges();
      const items = fixture.nativeElement.querySelectorAll('[data-testid="order-detail-item"]');
      expect(items).toHaveLength(3);
    });

    it('renders the total with currency', async () => {
      const { fixture } = setup({
        order: makeOrder({ total: '337.50', currency: 'AED' }),
      });
      await flush();
      fixture.detectChanges();
      const total = fixture.nativeElement.querySelector('[data-testid="order-detail-total"]');
      expect(total?.textContent).toContain('337.50');
      expect(total?.textContent).toContain('AED');
    });

    it('renders the discount line when discount > 0', async () => {
      const { fixture } = setup({
        order: makeOrder({ discount: '20.00' }),
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-discount-line"]')).not.toBeNull();
    });

    it('hides the discount line when discount is zero', async () => {
      const { fixture } = setup({
        order: makeOrder({ discount: '0.00' }),
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-discount-line"]')).toBeNull();
    });

    it('renders applied_promo block when present', async () => {
      const { fixture } = setup({
        order: makeOrder({
          applied_promo: {
            code: 'WELCOME10', type: 'percent', value: '10', discount_amount: '20.00',
          },
        }),
      });
      await flush();
      fixture.detectChanges();
      const promo = fixture.nativeElement.querySelector('[data-testid="order-detail-promo"]');
      expect(promo).not.toBeNull();
      expect(promo?.textContent).toContain('WELCOME10');
    });

    it('hides applied_promo block when null', async () => {
      const { fixture } = setup({ order: makeOrder({ applied_promo: null }) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-promo"]')).toBeNull();
    });
  });

  describe('shipping + billing address', () => {
    it('renders the shipping recipient, street and city from the API shape', async () => {
      const { fixture } = setup({
        order: makeOrder({
          shipping_address: makeAddr({
            first_name: 'Aisha', last_name: 'Khan', street: 'Marina Walk',
            city: 'Dubai', state_province: 'Marina',
          }),
        }),
      });
      await flush();
      fixture.detectChanges();
      const section = fixture.nativeElement.querySelector('[data-testid="order-detail-shipping"]');
      expect(section).not.toBeNull();
      const text = section.textContent as string;
      expect(text).toContain('Aisha Khan');
      expect(text).toContain('Marina Walk');
      expect(text).toContain('Dubai');
      /* Regression: must NOT render the empty ` , , ` from the old field mismatch. */
      expect(text).not.toMatch(/,\s*,/);
    });

    it('renders the billing address block', async () => {
      const { fixture } = setup({ order: makeOrder({ billing_address: makeAddr({ first_name: 'Bill', last_name: 'Payer' }) }) });
      await flush();
      fixture.detectChanges();
      const section = fixture.nativeElement.querySelector('[data-testid="order-detail-billing"]');
      expect(section).not.toBeNull();
      expect(section.textContent).toContain('Bill Payer');
    });

    it('omits the shipping section when the address is null', async () => {
      const { fixture } = setup({ order: makeOrder({ shipping_address: null }) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-shipping"]')).toBeNull();
    });
  });

  describe('product name entity decoding', () => {
    it('decodes &amp; in a product name to a literal &', async () => {
      const { fixture } = setup({
        order: makeOrder({ items: [makeOrderItem({ product_name: 'Abaya &amp; Scarf' })] }),
      });
      await flush();
      fixture.detectChanges();
      const name = fixture.nativeElement.querySelector('.review-item__name');
      expect(name.textContent).toContain('Abaya & Scarf');
      expect(name.textContent).not.toContain('&amp;');
    });
  });

  describe('order timeline', () => {
    it('fetches the timeline for the order id', async () => {
      const { orderService } = setup({ order: makeOrder({ id: 55 }), routeId: '55' });
      await flush();
      expect(orderService.timelineCalls).toEqual([55]);
    });

    it('renders the server event feed when present', async () => {
      const { fixture } = setup({
        order: makeOrder({ id: 42 }),
        timeline: [
          { id: 'e1', type: 'order.created', occurred_at: '2026-05-19T10:00:00Z', actor: { type: 'customer' }, summary: 'Order placed' },
          { id: 'e2', type: 'order.paid', occurred_at: '2026-05-19T10:05:00Z', actor: { type: 'system' }, summary: 'Payment received' },
        ],
      });
      await flush();
      fixture.detectChanges();
      const section = fixture.nativeElement.querySelector('[data-testid="order-detail-timeline"]');
      expect(section).not.toBeNull();
      const steps = section.querySelectorAll('.order-timeline__step');
      expect(steps).toHaveLength(2);
      expect(section.textContent).toContain('Payment received');
    });

    it('falls back to the derived stepper when the feed is empty', async () => {
      const { fixture } = setup({ order: makeOrder({ status: 'paid' }), timeline: [] });
      await flush();
      fixture.detectChanges();
      const section = fixture.nativeElement.querySelector('[data-testid="order-detail-timeline"]');
      expect(section).not.toBeNull();
      /* placed + paid + fulfilling + shipped + delivered = 5 derived steps. */
      const steps = section.querySelectorAll('.order-timeline__step');
      expect(steps.length).toBeGreaterThanOrEqual(2);
    });
  });

  describe('returns summary', () => {
    it('renders a return-request summary when the order has returns', async () => {
      const returns: ReturnSummary[] = [{
        id: 1, status: 'pending', reason: 'damaged',
        requested_at: '2026-05-20T00:00:00Z', item_count: 2,
      }];
      const { fixture } = setup({
        order: makeOrder({ returns }),
      });
      await flush();
      fixture.detectChanges();
      const section = fixture.nativeElement.querySelector('[data-testid="order-detail-returns"]');
      expect(section).not.toBeNull();
      const items = fixture.nativeElement.querySelectorAll('[data-testid="order-detail-return-item"]');
      expect(items).toHaveLength(1);
    });

    it('hides the returns section when no returns present', async () => {
      const { fixture } = setup({ order: makeOrder({}) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-returns"]')).toBeNull();
    });
  });

  describe('cancel flow', () => {
    it('cancel button visible only when status is pending_payment', async () => {
      const { fixture } = setup({
        order: makeOrder({ status: 'pending_payment' }),
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-cancel"]')).not.toBeNull();
    });

    it('cancel button hidden when status is not pending_payment', async () => {
      const { fixture } = setup({
        order: makeOrder({ status: 'paid' }),
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-cancel"]')).toBeNull();
    });

    it('cancel button opens the confirm modal; confirming calls OrderService.cancel', async () => {
      const { fixture, orderService } = setup({
        order: makeOrder({ id: 42, status: 'pending_payment' }),
      });
      await flush();
      fixture.detectChanges();
      /* Modal not shown until the cancel button is clicked. */
      expect(fixture.nativeElement.querySelector('[data-testid="confirm-modal"]')).toBeNull();

      (fixture.nativeElement.querySelector('[data-testid="order-detail-cancel"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="confirm-modal"]')).not.toBeNull();

      (fixture.nativeElement.querySelector('[data-testid="confirm-modal-confirm"]') as HTMLButtonElement).click();
      await flush();
      expect(orderService.cancelCalls).toEqual([42]);
    });

    it('skips cancel when the modal is dismissed', async () => {
      const { fixture, orderService } = setup({
        order: makeOrder({ id: 42, status: 'pending_payment' }),
      });
      await flush();
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="order-detail-cancel"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="confirm-modal-cancel"]') as HTMLButtonElement).click();
      await flush();
      fixture.detectChanges();
      expect(orderService.cancelCalls).toEqual([]);
      /* Modal closed after dismissal. */
      expect(fixture.nativeElement.querySelector('[data-testid="confirm-modal"]')).toBeNull();
    });

    it('updates the rendered status after a successful cancel', async () => {
      const { fixture } = setup({
        order: makeOrder({ id: 42, status: 'pending_payment' }),
        cancelResponse: makeOrder({ id: 42, status: 'cancelled' }),
      });
      await flush();
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="order-detail-cancel"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="confirm-modal-confirm"]') as HTMLButtonElement).click();
      await flush();
      fixture.detectChanges();
      const status = fixture.nativeElement.querySelector('[data-testid="order-detail-status"]');
      expect(status?.getAttribute('data-status')).toBe('cancelled');
      /* Cancel button should now be hidden. */
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-cancel"]')).toBeNull();
    });

    it('toasts success after a successful cancel', async () => {
      const { fixture, toast } = setup({
        order: makeOrder({ id: 42, status: 'pending_payment' }),
      });
      await flush();
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="order-detail-cancel"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="confirm-modal-confirm"]') as HTMLButtonElement).click();
      await flush();
      expect(toast.successes).toContain('orders.detail.cancelSuccess');
    });

    it('toasts on cancel failure', async () => {
      const { fixture, toast } = setup({
        order: makeOrder({ id: 42, status: 'pending_payment' }),
        shouldThrowCancel: true,
      });
      await flush();
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="order-detail-cancel"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="confirm-modal-confirm"]') as HTMLButtonElement).click();
      await flush();
      expect(toast.errors).toContain('orders.detail.cancelFailed');
    });
  });

  describe('return CTA', () => {
    it('shows the return CTA for paid orders', async () => {
      const { fixture } = setup({ order: makeOrder({ status: 'paid' }) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-return-cta"]')).not.toBeNull();
    });

    it('shows the return CTA for delivered orders', async () => {
      const { fixture } = setup({ order: makeOrder({ status: 'delivered' }) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-return-cta"]')).not.toBeNull();
    });

    it('hides the return CTA for pending_payment orders', async () => {
      const { fixture } = setup({ order: makeOrder({ status: 'pending_payment' }) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-return-cta"]')).toBeNull();
    });

    it('hides the return CTA for cancelled orders', async () => {
      const { fixture } = setup({ order: makeOrder({ status: 'cancelled' }) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-return-cta"]')).toBeNull();
    });

    it('hides the return CTA for refunded orders', async () => {
      const { fixture } = setup({ order: makeOrder({ status: 'refunded' }) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-return-cta"]')).toBeNull();
    });

    it('shows the return CTA for fulfilling orders', async () => {
      const { fixture } = setup({ order: makeOrder({ status: 'fulfilling' }) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-return-cta"]')).not.toBeNull();
    });

    it('hides the return CTA for failed orders', async () => {
      const { fixture } = setup({ order: makeOrder({ status: 'failed' }) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-return-cta"]')).toBeNull();
    });

    it('return CTA links to /account/orders/:id/return', async () => {
      const { fixture } = setup({ order: makeOrder({ id: 77, status: 'delivered' }) });
      await flush();
      fixture.detectChanges();
      const cta = fixture.nativeElement.querySelector('[data-testid="order-detail-return-cta"]') as HTMLAnchorElement;
      expect(cta.getAttribute('href')).toBe('/account/orders/77/return');
    });
  });

  describe('error paths', () => {
    it('shows error state when :id is missing', async () => {
      const { fixture, orderService } = setup({ routeId: null });
      await flush();
      fixture.detectChanges();
      expect(orderService.getByIdCalls).toEqual([]);
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-error"]')).not.toBeNull();
    });

    it('shows error state when :id is not numeric', async () => {
      const { fixture, orderService } = setup({ routeId: 'abc' });
      await flush();
      fixture.detectChanges();
      expect(orderService.getByIdCalls).toEqual([]);
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-error"]')).not.toBeNull();
    });

    it('shows error state when the fetch fails', async () => {
      const { fixture, toast } = setup({ shouldThrowGet: true });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="order-detail-error"]')).not.toBeNull();
      expect(toast.errors).toContain('orders.detail.loadError');
    });
  });
});
