import { describe, it, expect, afterEach } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { CartIconComponent } from './cart-icon';
import { CartService, CartDrawerService } from '../../core/cart';
import { provideI18n } from '../../core/i18n';
import type { Cart } from '../../core/cart';

class StubCartService {
  private _itemCount = signal(0);
  itemCount = this._itemCount.asReadonly();
  setItemCount(n: number): void { this._itemCount.set(n); }
}

function setup(itemCount = 0): {
  fixture: ComponentFixture<CartIconComponent>;
  drawer: CartDrawerService;
  cart: StubCartService;
} {
  const cart = new StubCartService();
  cart.setItemCount(itemCount);

  TestBed.configureTestingModule({
    imports: [CartIconComponent],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: CartService, useValue: cart },
      CartDrawerService,
    ],
  });

  const drawer = TestBed.inject(CartDrawerService);
  const fixture = TestBed.createComponent(CartIconComponent);
  fixture.detectChanges();
  return { fixture, drawer, cart };
}

describe('CartIconComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
  });

  it('renders the icon button with a test id', () => {
    const { fixture } = setup();
    expect(fixture.nativeElement.querySelector('[data-testid="cart-icon"]')).not.toBeNull();
  });

  it('does NOT render the badge when itemCount is 0', () => {
    const { fixture } = setup(0);
    expect(fixture.nativeElement.querySelector('[data-testid="cart-icon-badge"]')).toBeNull();
  });

  it('renders the badge with the count when itemCount > 0', () => {
    const { fixture } = setup(3);
    const badge = fixture.nativeElement.querySelector('[data-testid="cart-icon-badge"]');
    expect(badge).not.toBeNull();
    expect(badge?.textContent?.trim()).toBe('3');
  });

  it('caps the badge display at 99+ for large counts', () => {
    const { fixture } = setup(125);
    const badge = fixture.nativeElement.querySelector('[data-testid="cart-icon-badge"]');
    expect(badge?.textContent?.trim()).toBe('99+');
  });

  it('shows exactly 99 (not 99+) when count is exactly 99', () => {
    const { fixture } = setup(99);
    const badge = fixture.nativeElement.querySelector('[data-testid="cart-icon-badge"]');
    expect(badge?.textContent?.trim()).toBe('99');
  });

  it('uses the empty aria-label key when count is 0', () => {
    const { fixture } = setup(0);
    const btn = fixture.nativeElement.querySelector('[data-testid="cart-icon"]') as HTMLButtonElement;
    /* The translate pipe needs translations loaded to render text; in tests
       we assert on the i18n key via the data-aria-key attribute instead. */
    expect(btn.getAttribute('data-aria-key')).toBe('header.cart.openEmpty');
  });

  it('uses the count aria-label key when count > 0', () => {
    const { fixture } = setup(3);
    const btn = fixture.nativeElement.querySelector('[data-testid="cart-icon"]') as HTMLButtonElement;
    expect(btn.getAttribute('data-aria-key')).toBe('header.cart.openWithCount');
  });

  it('toggles the drawer on click', () => {
    const { fixture, drawer } = setup();
    const btn = fixture.nativeElement.querySelector('[data-testid="cart-icon"]') as HTMLButtonElement;
    expect(drawer.isOpen()).toBe(false);
    btn.click();
    expect(drawer.isOpen()).toBe(true);
    btn.click();
    expect(drawer.isOpen()).toBe(false);
  });
});
