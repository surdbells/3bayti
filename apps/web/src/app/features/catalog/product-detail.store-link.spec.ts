import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { PLATFORM_ID, signal } from '@angular/core';
import { of } from 'rxjs';

import { ProductDetailComponent } from './product-detail';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { SeoService } from '../../core/seo/seo.service';
import { RecommendationsService } from './recommendations.service';
import { CartService } from '../../core/cart/cart.service';
import { CartDrawerService } from '../../core/cart/cart-drawer.service';
import { AuthService } from '../../core/auth/auth.service';
import { MeasurementService } from '../account/measurement.service';
import { provideI18n } from '../../core/i18n';
import type { ProductDetail } from './product.model';

/**
 * PDP → store navigation: ONE compact "Sold by <Store> · Visit store" row
 * above the title (the former vendor line and the "Sold by" card, merged)
 * links to the seller's storefront by SLUG (/stores/:slug), and disappears
 * when the API returns no vendor.
 */

/** jsdom has no IntersectionObserver; the PDP's @defer (on viewport) blocks
 *  and sticky-bar observers need one. This inert stub never reports. */
class InertIntersectionObserver {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
  takeRecords(): IntersectionObserverEntry[] { return []; }
}
function makeProduct(overrides: Partial<ProductDetail> = {}): ProductDetail {
  return {
    id: 100,
    slug: 'abaya-01',
    name: 'Test Abaya',
    price: { amount: 530, currency: 'AED' },
    sale_price: null,
    primary_image: null,
    in_stock: true,
    description: '',
    images: [],
    vendor: { slug: 'noor-atelier', name: 'Noor Atelier' },
    ...overrides,
  } as ProductDetail;
}

async function setup(product: ProductDetail): Promise<ComponentFixture<ProductDetailComponent>> {
  TestBed.configureTestingModule({
    imports: [ProductDetailComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: ActivatedRoute, useValue: { paramMap: of(convertToParamMap({ slug: product.slug })) } },
      { provide: RoutedHttpClient, useValue: { get: vi.fn(() => of({ data: product })) } },
      { provide: SeoService, useValue: { set: vi.fn(), setStructuredData: vi.fn() } },
      { provide: RecommendationsService, useValue: { forProduct: vi.fn(() => Promise.resolve([])) } },
      { provide: CartService, useValue: { addItem: vi.fn(() => Promise.resolve({})) } },
      { provide: CartDrawerService, useValue: { open: vi.fn() } },
      { provide: AuthService, useValue: { isAuthenticated: signal(false).asReadonly() } },
      { provide: MeasurementService, useValue: { getDefault: vi.fn(() => Promise.resolve(null)) } },
      { provide: PLATFORM_ID, useValue: 'browser' },
    ],
  });
  await TestBed.compileComponents();
  const fixture = TestBed.createComponent(ProductDetailComponent);
  fixture.detectChanges();
  return fixture;
}

describe('ProductDetail store link', () => {
  beforeEach(() => {
    TestBed.resetTestingModule();
    vi.stubGlobal('IntersectionObserver', InertIntersectionObserver);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('renders a "Sold by" store card linking to the store by slug', async () => {
    const el: HTMLElement = (await setup(makeProduct())).nativeElement;
    const card = el.querySelector<HTMLAnchorElement>('[data-testid="pdp-store-card"]');
    expect(card).not.toBeNull();
    expect(card!.getAttribute('href')).toBe('/stores/noor-atelier');
    expect(card!.textContent).toContain('Noor Atelier');
    expect(card!.querySelector('.pdp-store__avatar')?.textContent?.trim()).toBe('N');
  });

  it('uses the whole first character for the avatar when the store name starts with an emoji', async () => {
    const el: HTMLElement = (await setup(
      makeProduct({ vendor: { slug: 'gem-studio', name: '\u{1F48E} Gem Studio' } }),
    )).nativeElement;
    const avatar = el.querySelector('.pdp-store__avatar')?.textContent?.trim() ?? '';
    // One full code point (a surrogate PAIR in UTF-16), never a lone half.
    expect(avatar).toBe('\u{1F48E}');
    expect(Array.from(avatar)).toHaveLength(1);
    expect(avatar).not.toMatch(/^[\uD800-\uDBFF]$/);
  });

  it('merges the vendor line and the store card into ONE "Sold by · Visit store" link above the title', async () => {
    const el: HTMLElement = (await setup(makeProduct())).nativeElement;
    const links = el.querySelectorAll<HTMLAnchorElement>('a[href="/stores/noor-atelier"]');
    expect(links).toHaveLength(1);
    const link = links[0];
    expect(link.getAttribute('data-testid')).toBe('pdp-store-card');
    // The accessible name is the visible text: "Sold by <store> · Visit store".
    expect(link.querySelector('.pdp-store__label')).not.toBeNull();
    expect(link.querySelector('.pdp-store__name')?.textContent?.trim()).toBe('Noor Atelier');
    expect(link.querySelector('.pdp-store__cta')).not.toBeNull();
    expect(link.hasAttribute('aria-label')).toBe(false);
    // It sits above (before) the product title.
    const h1 = el.querySelector('h1')!;
    expect(link.compareDocumentPosition(h1) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    // The old separate vendor link is gone (no duplicate links to the store).
    expect(el.querySelector('[data-testid="pdp-vendor-link"]')).toBeNull();
  });

  it('hides both store links when the product has no vendor', async () => {
    const el: HTMLElement = (await setup(
      makeProduct({ vendor: null as unknown as ProductDetail['vendor'] }),
    )).nativeElement;
    expect(el.querySelector('[data-testid="pdp-store-card"]')).toBeNull();
    expect(el.querySelector('a[href^="/stores/"]')).toBeNull();
    expect(el.querySelector('.pdp-vendor')).toBeNull();
  });

  it('hides the store card when the vendor has no slug to link to', async () => {
    const el: HTMLElement = (await setup(
      makeProduct({ vendor: { slug: '', name: 'Nameless Store' } }),
    )).nativeElement;
    expect(el.querySelector('[data-testid="pdp-store-card"]')).toBeNull();
    expect(el.querySelector('a[href^="/stores/"]')).toBeNull();
    // The name is still shown (plain "Sold by <store>" text) above the title.
    expect(el.querySelector('.pdp-vendor .pdp-store__label')).not.toBeNull();
    expect(el.querySelector('.pdp-vendor')?.textContent).toContain('Nameless Store');
  });
});
