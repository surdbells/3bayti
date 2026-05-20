import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { CartDrawerComponent } from './cart-drawer';
import { CartService, CartDrawerService } from '../../core/cart';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type { Cart, CartItem } from '../../core/cart';

/**
 * CartDrawerComponent unit tests.
 *
 * Coverage:
 *   - Renders nothing when closed
 *   - Renders panel + items when open with items
 *   - Renders empty state when open with no items
 *   - Close button + backdrop close the drawer
 *   - Subtotal + currency reflect the cart signal
 *   - Item highlight class applies when openWithHighlight matches
 *   - Remove button calls CartService.removeItem
 *   - Escape closes via document keydown listener
 */

function makeItem(overrides: Partial<CartItem> = {}): CartItem {
  return {
    id: 1,
    product_id: 100,
    product_name: 'Test Product',
    product_image: 'https://example.com/test.jpg',
    quantity: 1,
    unit_price: '129.00',
    line_subtotal: '129.00',
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
  const subtotal = items.reduce(
    (acc, it) => acc + parseFloat(it.line_subtotal),
    0,
  ).toFixed(2);
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

class StubCartService {
  private _cart = signal<Cart>(makeCart());
  cart = this._cart.asReadonly();
  subtotal = signal('0.00').asReadonly();
  currency = signal('AED').asReadonly();
  removeCalls: number[] = [];

  setCart(c: Cart): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).cart = signal<Cart>(c).asReadonly();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).subtotal = signal(c.subtotal).asReadonly();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).currency = signal(c.currency).asReadonly();
  }

  async removeItem(id: number): Promise<Cart> {
    this.removeCalls.push(id);
    return this.cart();
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

function setup(opts: { items?: CartItem[]; openWith?: number } = {}): {
  fixture: ComponentFixture<CartDrawerComponent>;
  cart: StubCartService;
  drawer: CartDrawerService;
  toast: StubToastService;
  navigateByUrlSpy: ReturnType<typeof vi.fn>;
} {
  const cart = new StubCartService();
  if (opts.items !== undefined) cart.setCart(makeCart(opts.items));

  TestBed.configureTestingModule({
    imports: [CartDrawerComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: CartService, useValue: cart },
      { provide: ToastService, useValue: new StubToastService() },
      CartDrawerService,
    ],
  });

  const drawer = TestBed.inject(CartDrawerService);
  const toast = TestBed.inject(ToastService) as unknown as StubToastService;
  const router = TestBed.inject(Router);
  const navigateByUrlSpy = vi.spyOn(router, 'navigateByUrl')
    .mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;

  const fixture = TestBed.createComponent(CartDrawerComponent);
  fixture.detectChanges();

  if (opts.openWith !== undefined) {
    drawer.openWithHighlight(opts.openWith);
    fixture.detectChanges();
  }

  return { fixture, cart, drawer, toast, navigateByUrlSpy };
}

describe('CartDrawerComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('rendering', () => {
    it('renders nothing visible when closed', () => {
      const { fixture } = setup({ items: [makeItem()] });
      expect(fixture.nativeElement.querySelector('[data-testid="cart-drawer"]')).toBeNull();
      expect(fixture.nativeElement.querySelector('[data-testid="cart-drawer-backdrop"]')).toBeNull();
    });

    it('renders the panel + backdrop when open', () => {
      const { fixture, drawer } = setup({ items: [makeItem()] });
      drawer.open();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="cart-drawer"]')).not.toBeNull();
      expect(fixture.nativeElement.querySelector('[data-testid="cart-drawer-backdrop"]')).not.toBeNull();
    });

    it('renders one item row per cart item', () => {
      const { fixture, drawer } = setup({
        items: [
          makeItem({ id: 1, product_name: 'A' }),
          makeItem({ id: 2, product_name: 'B' }),
          makeItem({ id: 3, product_name: 'C' }),
        ],
      });
      drawer.open();
      fixture.detectChanges();
      const items = fixture.nativeElement.querySelectorAll('[data-testid="cart-drawer-item"]');
      expect(items).toHaveLength(3);
    });

    it('renders the empty state when cart has no items', () => {
      const { fixture, drawer } = setup({ items: [] });
      drawer.open();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="cart-drawer-empty"]')).not.toBeNull();
      expect(fixture.nativeElement.querySelector('[data-testid="cart-drawer-checkout"]')).toBeNull();
    });

    it('renders the footer with subtotal when cart has items', () => {
      const { fixture, drawer } = setup({ items: [makeItem({ line_subtotal: '258.00' })] });
      drawer.open();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="cart-drawer-checkout"]')).not.toBeNull();
      const totalsAmount = fixture.nativeElement.querySelector('.cart-drawer__totals-amount');
      expect(totalsAmount?.textContent).toContain('258.00');
      expect(totalsAmount?.textContent).toContain('AED');
    });

    it('applies the highlighted class to the openWithHighlight item id', () => {
      const { fixture } = setup({
        items: [makeItem({ id: 1 }), makeItem({ id: 2 })],
        openWith: 2,
      });
      const items = fixture.nativeElement.querySelectorAll('[data-testid="cart-drawer-item"]');
      expect(items[0].classList.contains('cart-drawer__item--highlighted')).toBe(false);
      expect(items[1].classList.contains('cart-drawer__item--highlighted')).toBe(true);
    });
  });

  describe('close interactions', () => {
    it('close button closes the drawer', () => {
      const { fixture, drawer } = setup({ items: [makeItem()] });
      drawer.open();
      fixture.detectChanges();
      const closeBtn = fixture.nativeElement.querySelector('[data-testid="cart-drawer-close"]') as HTMLButtonElement;
      closeBtn.click();
      fixture.detectChanges();
      expect(drawer.isOpen()).toBe(false);
    });

    it('backdrop click closes the drawer', () => {
      const { fixture, drawer } = setup({ items: [makeItem()] });
      drawer.open();
      fixture.detectChanges();
      const backdrop = fixture.nativeElement.querySelector('[data-testid="cart-drawer-backdrop"]') as HTMLElement;
      backdrop.click();
      fixture.detectChanges();
      expect(drawer.isOpen()).toBe(false);
    });

    it('Escape key closes the drawer (via document listener)', () => {
      const { fixture, drawer } = setup({ items: [makeItem()] });
      drawer.open();
      fixture.detectChanges();
      const event = new KeyboardEvent('keydown', { key: 'Escape' });
      document.dispatchEvent(event);
      fixture.detectChanges();
      expect(drawer.isOpen()).toBe(false);
    });

    it('View cart link closes the drawer (it then navigates via RouterLink)', () => {
      const { fixture, drawer } = setup({ items: [makeItem()] });
      drawer.open();
      fixture.detectChanges();
      const link = fixture.nativeElement.querySelector('[data-testid="cart-drawer-view"]') as HTMLAnchorElement;
      link.click();
      fixture.detectChanges();
      expect(drawer.isOpen()).toBe(false);
    });
  });

  describe('actions', () => {
    it('Remove button calls CartService.removeItem with the line id', async () => {
      const { fixture, drawer, cart } = setup({
        items: [makeItem({ id: 7 })],
      });
      drawer.open();
      fixture.detectChanges();
      const removeBtn = fixture.nativeElement.querySelector('[data-testid="cart-drawer-remove"]') as HTMLButtonElement;
      removeBtn.click();
      await Promise.resolve();
      expect(cart.removeCalls).toEqual([7]);
    });

    it('Checkout button closes the drawer and navigates to /checkout/address', async () => {
      const { fixture, drawer, navigateByUrlSpy } = setup({ items: [makeItem()] });
      drawer.open();
      fixture.detectChanges();
      const checkoutBtn = fixture.nativeElement.querySelector('[data-testid="cart-drawer-checkout"]') as HTMLButtonElement;
      checkoutBtn.click();
      await Promise.resolve();
      expect(drawer.isOpen()).toBe(false);
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/checkout/address');
    });
  });
});
