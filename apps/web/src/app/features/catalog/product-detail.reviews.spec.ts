import { describe, it, expect, beforeEach, vi } from 'vitest';
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
import type { ProductDetail } from './product.model';

/**
 * Reviews-section coverage for the PDP (H3.A). The Reviews section now
 * renders for EVERY product: the review list when reviews exist, or an
 * inviting empty state (#4) when none do, never a missing section.
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
      { provide: PLATFORM_ID, useValue: 'browser' },
    ],
  });
  const fixture = TestBed.createComponent(ProductDetailComponent);
  fixture.detectChanges();
  return fixture;
}

describe('ProductDetail reviews section', () => {
  beforeEach(() => {
    TestBed.resetTestingModule();
  });

  it('shows the empty-reviews state when the product has no reviews', () => {
    const fixture = setup(makeProduct({ recent_reviews: [] }));
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('#reviews')).not.toBeNull();
    expect(root.querySelector('[data-testid="pdp-reviews-empty"]')).not.toBeNull();
    expect(root.querySelector('.pdp-reviews__list')).toBeNull();
  });

  it('shows the reviews list (and not the empty state) when reviews exist', () => {
    const fixture = setup(
      makeProduct({
        rating: 4.5,
        review_count: 2,
        recent_reviews: [
          { id: 1, rating: 5, body: 'Beautiful fabric.', author: 'Sara', verified: true },
          { id: 2, rating: 4, body: 'Good fit.', author: 'Lina' },
        ],
      }),
    );
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('.pdp-reviews__list')).not.toBeNull();
    expect(root.querySelectorAll('.pdp-review')).toHaveLength(2);
    expect(root.querySelector('[data-testid="pdp-reviews-empty"]')).toBeNull();
  });
});
