import { describe, it, expect, beforeEach, vi } from 'vitest';
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
 * PDP → store navigation: the vendor line above the title and the
 * "Sold by" store card in the purchase area both link to the seller's
 * storefront by SLUG (/stores/:slug), and disappear when the API returns
 * no vendor.
 */
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

function setup(product: ProductDetail): ComponentFixture<ProductDetailComponent> {
  TestBed.configureTestingModule({
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
  const fixture = TestBed.createComponent(ProductDetailComponent);
  fixture.detectChanges();
  return fixture;
}

describe('ProductDetail store link', () => {
  beforeEach(() => {
    TestBed.resetTestingModule();
  });

  it('renders a "Sold by" store card linking to the store by slug', () => {
    const el: HTMLElement = setup(makeProduct()).nativeElement;
    const card = el.querySelector<HTMLAnchorElement>('[data-testid="pdp-store-card"]');
    expect(card).not.toBeNull();
    expect(card!.getAttribute('href')).toBe('/stores/noor-atelier');
    expect(card!.textContent).toContain('Noor Atelier');
    expect(card!.querySelector('.pdp-store__avatar')?.textContent?.trim()).toBe('N');
  });

  it('links the vendor line above the title to the same storefront', () => {
    const el: HTMLElement = setup(makeProduct()).nativeElement;
    const link = el.querySelector<HTMLAnchorElement>('[data-testid="pdp-vendor-link"]');
    expect(link).not.toBeNull();
    expect(link!.getAttribute('href')).toBe('/stores/noor-atelier');
    expect(link!.textContent).toContain('Noor Atelier');
  });

  it('hides both store links when the product has no vendor', () => {
    const el: HTMLElement = setup(
      makeProduct({ vendor: null as unknown as ProductDetail['vendor'] }),
    ).nativeElement;
    expect(el.querySelector('[data-testid="pdp-store-card"]')).toBeNull();
    expect(el.querySelector('[data-testid="pdp-vendor-link"]')).toBeNull();
  });

  it('hides the store card when the vendor has no slug to link to', () => {
    const el: HTMLElement = setup(
      makeProduct({ vendor: { slug: '', name: 'Nameless Store' } }),
    ).nativeElement;
    expect(el.querySelector('[data-testid="pdp-store-card"]')).toBeNull();
    expect(el.querySelector('[data-testid="pdp-vendor-link"]')).toBeNull();
    // The name is still shown (plain text) above the title.
    expect(el.querySelector('.pdp-vendor')?.textContent).toContain('Nameless Store');
  });
});
