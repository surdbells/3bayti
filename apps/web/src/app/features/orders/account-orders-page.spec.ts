import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { AccountOrdersPageComponent } from './account-orders-page';
import { OrderService } from '../../core/orders';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type { OrderListItem, OrderItem } from '../../core/orders';

function makeOrderItem(o: Partial<OrderItem> = {}): OrderItem {
  return {
    id: 1, product_id: 100, vendor_id: 5, product_name: 'Item',
    product_image: 'https://example.com/i.jpg', quantity: 1,
    unit_price: '100.00', subtotal: '100.00', size: null, color: null,
    is_custom: false, measurement: null, extra_measurement: null,
    note: null, item_status: 'paid', store: 5, ...o,
  };
}

function makeOrder(o: Partial<OrderListItem> = {}): OrderListItem {
  return {
    id: 1, order_reference: 'BAYTI-2026-001', status: 'paid',
    date: '2026-05-19T10:00:00Z', subtotal: '100.00',
    delivery_fee: '25.00', discount: '0.00', total: '125.00',
    currency: 'AED', paid_at: '2026-05-19T10:05:00Z',
    items: [makeOrderItem()], applied_promo: null, ...o,
  };
}

class StubOrderService {
  private _list = signal<OrderListItem[]>([]);
  private _loading = signal(false);
  private _hasMore = signal(false);
  listItems = this._list.asReadonly();
  isLoadingList = this._loading.asReadonly();
  hasMore = this._hasMore.asReadonly();

  resetCalls = 0;
  loadMoreCalls = 0;
  shouldThrow = false;

  reset(): void { this.resetCalls++; this._list.set([]); }
  async loadMore(): Promise<OrderListItem[]> {
    this.loadMoreCalls++;
    if (this.shouldThrow) throw new Error('load failed');
    return this._list();
  }
  /** Test helper to inject pages. */
  setItems(items: OrderListItem[]): void { this._list.set(items); }
  setLoading(b: boolean): void { this._loading.set(b); }
  setHasMore(b: boolean): void { this._hasMore.set(b); }
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
  initialItems?: OrderListItem[];
  loadingOnInit?: boolean;
  hasMore?: boolean;
  throwOnLoad?: boolean;
} = {}): {
  fixture: ComponentFixture<AccountOrdersPageComponent>;
  orderService: StubOrderService;
  toast: StubToastService;
} {
  const orderService = new StubOrderService();
  if (opts.throwOnLoad === true) orderService.shouldThrow = true;
  if (opts.initialItems !== undefined) {
    /* The component calls reset() then loadMore() on init.
       We need the items to "appear" after loadMore completes.
       Override loadMore to set the items synchronously when called. */
    const items = opts.initialItems;
    const origLoadMore = orderService.loadMore.bind(orderService);
    orderService.loadMore = async () => {
      orderService.loadMoreCalls++;
      orderService.setItems(items);
      return items;
    };
    /* Keep reset() honest. */
    void origLoadMore;
  }
  if (opts.hasMore === true) orderService.setHasMore(true);

  TestBed.configureTestingModule({
    imports: [AccountOrdersPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: OrderService, useValue: orderService },
      { provide: ToastService, useValue: new StubToastService() },
    ],
  });

  const toast = TestBed.inject(ToastService) as unknown as StubToastService;
  const fixture = TestBed.createComponent(AccountOrdersPageComponent);
  fixture.detectChanges();
  return { fixture, orderService, toast };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 6; i++) await Promise.resolve();
}

describe('AccountOrdersPageComponent', () => {
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

  describe('init', () => {
    it('resets the accumulator and loads the first page on init', async () => {
      const { orderService } = setup({});
      await flush();
      expect(orderService.resetCalls).toBe(1);
      expect(orderService.loadMoreCalls).toBe(1);
    });

    it('shows the loading state while initial load is in flight', async () => {
      const { fixture, orderService } = setup({});
      orderService.setLoading(true);
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="orders-loading"]')).not.toBeNull();
    });
  });

  describe('with orders', () => {
    it('renders one card per order', async () => {
      const { fixture } = setup({
        initialItems: [
          makeOrder({ id: 1 }),
          makeOrder({ id: 2, order_reference: 'BAYTI-2026-002' }),
        ],
      });
      await flush();
      fixture.detectChanges();
      const items = fixture.nativeElement.querySelectorAll('[data-testid="orders-list-item"]');
      expect(items).toHaveLength(2);
    });

    it('renders the order reference on each card', async () => {
      const { fixture } = setup({
        initialItems: [makeOrder({ order_reference: 'BAYTI-2026-077' })],
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.textContent).toContain('BAYTI-2026-077');
    });

    it('applies the right status class for each status', async () => {
      const { fixture } = setup({
        initialItems: [
          makeOrder({ id: 1, status: 'paid' }),
          makeOrder({ id: 2, status: 'pending_payment' }),
          makeOrder({ id: 3, status: 'cancelled' }),
          makeOrder({ id: 4, status: 'shipped' }),
        ],
      });
      await flush();
      fixture.detectChanges();
      const pills = Array.from(fixture.nativeElement.querySelectorAll('.order-card__status')) as HTMLElement[];
      expect(pills[0].classList.contains('order-card__status--positive')).toBe(true);
      expect(pills[1].classList.contains('order-card__status--warning')).toBe(true);
      expect(pills[2].classList.contains('order-card__status--negative')).toBe(true);
      expect(pills[3].classList.contains('order-card__status--neutral')).toBe(true);
    });

    it('renders up to 3 item thumbnails per order', async () => {
      const { fixture } = setup({
        initialItems: [makeOrder({
          items: [
            makeOrderItem({ id: 1 }), makeOrderItem({ id: 2 }),
            makeOrderItem({ id: 3 }), makeOrderItem({ id: 4 }),
            makeOrderItem({ id: 5 }),
          ],
        })],
      });
      await flush();
      fixture.detectChanges();
      const thumbs = fixture.nativeElement.querySelectorAll('.order-card__thumb');
      expect(thumbs).toHaveLength(3);
      const more = fixture.nativeElement.querySelector('.order-card__more');
      expect(more.textContent.trim()).toBe('+2');
    });

    it('omits the +N indicator when 3 or fewer items', async () => {
      const { fixture } = setup({
        initialItems: [makeOrder({
          items: [makeOrderItem({ id: 1 }), makeOrderItem({ id: 2 })],
        })],
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('.order-card__more')).toBeNull();
    });

    it('sums item quantities for the total count', async () => {
      const { fixture } = setup({
        initialItems: [makeOrder({
          items: [
            makeOrderItem({ id: 1, quantity: 2 }),
            makeOrderItem({ id: 2, quantity: 3 }),
          ],
        })],
      });
      await flush();
      fixture.detectChanges();
      const count = fixture.nativeElement.querySelector('.order-card__count');
      expect(count.textContent.trim().startsWith('5')).toBe(true);
    });

    it('renders the total with currency', async () => {
      const { fixture } = setup({
        initialItems: [makeOrder({ total: '225.50', currency: 'AED' })],
      });
      await flush();
      fixture.detectChanges();
      const total = fixture.nativeElement.querySelector('.order-card__total');
      expect(total.textContent).toContain('225.50');
      expect(total.textContent).toContain('AED');
    });

    it('order card links to /account/orders/:id', async () => {
      const { fixture } = setup({ initialItems: [makeOrder({ id: 42 })] });
      await flush();
      fixture.detectChanges();
      const link = fixture.nativeElement.querySelector('.order-card') as HTMLAnchorElement;
      /* Angular routerLink writes href attribute at runtime when not in a router-outlet. */
      expect(link.getAttribute('href')).toBe('/account/orders/42');
    });
  });

  describe('empty state', () => {
    it('renders the empty state when no orders loaded', async () => {
      const { fixture } = setup({});
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="orders-empty"]')).not.toBeNull();
    });
  });

  describe('load more', () => {
    it('button is hidden when hasMore is false', async () => {
      const { fixture } = setup({
        initialItems: [makeOrder()],
        hasMore: false,
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="orders-load-more"]')).toBeNull();
    });

    it('button is shown when hasMore is true', async () => {
      const { fixture } = setup({
        initialItems: [makeOrder()],
        hasMore: true,
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="orders-load-more"]')).not.toBeNull();
    });

    it('clicking load more calls OrderService.loadMore again', async () => {
      const { fixture, orderService } = setup({
        initialItems: [makeOrder()],
        hasMore: true,
      });
      await flush();
      fixture.detectChanges();
      const before = orderService.loadMoreCalls;
      const btn = fixture.nativeElement.querySelector('[data-testid="orders-load-more"]') as HTMLButtonElement;
      btn.click();
      await flush();
      expect(orderService.loadMoreCalls).toBe(before + 1);
    });
  });

  describe('errors', () => {
    it('toasts on load failure', async () => {
      const { toast } = setup({ throwOnLoad: true });
      await flush();
      expect(toast.errors).toContain('orders.list.loadError');
    });
  });
});
