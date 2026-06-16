import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { PLATFORM_ID } from '@angular/core';
import { of } from 'rxjs';

import { ProductDetailComponent } from './product-detail';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { SeoService } from '../../core/seo/seo.service';
import { RecommendationsService } from './recommendations.service';
import { CartService } from '../../core/cart/cart.service';
import { CartDrawerService } from '../../core/cart/cart-drawer.service';
import { provideI18n } from '../../core/i18n';
import type { Product, ProductDetail } from './product.model';

/**
 * "You may also like" coverage for the PDP (Phase C3a, decision #5).
 * The section was a horizontal ui-product-strip scroller; it is now a
 * wrapping grid of ui-product-card, capped at 10 (5×2). These tests
 * drive the product + recommendations through the component's real
 * route → fetch pipeline and assert the rendered grid.
 */
function makeRelated(i: number): Product {
  return {
    id: 1000 + i,
    slug: `rel-${i}`,
    name: `Related product ${i}`,
    price: { amount: 100 + i, currency: 'AED' },
    sale_price: null,
    primary_image: null,
    in_stock: true,
    vendor: { name: `Vendor ${i}` },
    rating: null,
    review_count: 0,
  } as unknown as Product;
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
    sizes: [],
    colors: [],
    ...overrides,
  } as ProductDetail;
}

function setup(opts: { product?: ProductDetail; recs?: Product[] } = {}): ComponentFixture<ProductDetailComponent> {
  const product = opts.product ?? makeProduct();
  const recs = (opts.recs ?? []).map((p) => ({ product: p }));

  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: ActivatedRoute, useValue: { paramMap: of(convertToParamMap({ slug: product.slug })) } },
      { provide: RoutedHttpClient, useValue: { get: vi.fn(() => of({ data: product })) } },
      { provide: SeoService, useValue: { set: vi.fn(), setStructuredData: vi.fn() } },
      { provide: RecommendationsService, useValue: { forProduct: vi.fn(() => Promise.resolve(recs)) } },
      { provide: CartService, useValue: { addItem: vi.fn(() => Promise.resolve({})) } },
      { provide: CartDrawerService, useValue: { open: vi.fn() } },
      { provide: PLATFORM_ID, useValue: 'browser' },
    ],
  });

  const fixture = TestBed.createComponent(ProductDetailComponent);
  fixture.detectChanges();
  return fixture;
}

/** Flush the recommendations promise (toSignal ← from(Promise)) + re-render. */
async function settle(fixture: ComponentFixture<ProductDetailComponent>): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
  fixture.detectChanges();
}

describe('ProductDetailComponent — "You may also like" grid (C3a, #5)', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders engine recommendations as a card grid under the heading', async () => {
    const fixture = setup({ recs: [makeRelated(1), makeRelated(2), makeRelated(3)] });
    await settle(fixture);
    const root: HTMLElement = fixture.nativeElement;
    const section = root.querySelector('.pdp-related');
    expect(section).not.toBeNull();
    expect(section!.querySelector('.pdp-related__heading')?.textContent).toContain('You may also like');
    const grid = section!.querySelector('.pdp-related__grid')!;
    expect(grid.querySelectorAll('ui-product-card').length).toBe(3);
  });

  it('caps the grid at 10 cards even when more are returned', async () => {
    const recs = Array.from({ length: 14 }, (_, i) => makeRelated(i));
    const fixture = setup({ recs });
    await settle(fixture);
    const cards = fixture.nativeElement.querySelectorAll('.pdp-related__grid ui-product-card');
    expect(cards.length).toBe(10);
  });

  it('falls back to the product related_products when the engine is empty', async () => {
    const product = makeProduct({
      related_products: [makeRelated(1), makeRelated(2), makeRelated(3), makeRelated(4)],
    });
    const fixture = setup({ product, recs: [] });
    await settle(fixture);
    const cards = fixture.nativeElement.querySelectorAll('.pdp-related__grid ui-product-card');
    expect(cards.length).toBe(4);
  });

  it('omits the section (and the old strip) when there is nothing to recommend', async () => {
    const fixture = setup({ recs: [] });
    await settle(fixture);
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('.pdp-related')).toBeNull();
    expect(root.querySelector('ui-product-strip')).toBeNull();
  });
});
