import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
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
 * Reviews-section coverage for the PDP (H3.A). The Reviews section renders
 * for EVERY product: the review list when reviews exist, or an inviting
 * empty state (#4) when none do, never a missing section. The section
 * heading (the #reviews anchor) renders eagerly; its body is an
 * @defer (on viewport) block.
 */
/** jsdom has no IntersectionObserver. This stub records what is observed;
 *  `revealAll()` reports every observed element as on-screen, which is how
 *  the PDP's @defer (on viewport) blocks are triggered in these tests. */
class StubIntersectionObserver {
  static instances: StubIntersectionObserver[] = [];
  private readonly targets = new Set<Element>();
  constructor(private readonly callback: IntersectionObserverCallback) {
    StubIntersectionObserver.instances.push(this);
  }
  observe(target: Element): void { this.targets.add(target); }
  unobserve(target: Element): void { this.targets.delete(target); }
  disconnect(): void { this.targets.clear(); }
  takeRecords(): IntersectionObserverEntry[] { return []; }
  static revealAll(): void {
    for (const io of StubIntersectionObserver.instances) {
      const entries = [...io.targets].map((target) => ({
        target,
        isIntersecting: true,
        intersectionRatio: 1,
        boundingClientRect: target.getBoundingClientRect(),
        intersectionRect: target.getBoundingClientRect(),
        rootBounds: null,
        time: 0,
      }) as IntersectionObserverEntry);
      if (entries.length > 0) io.callback(entries, io as unknown as IntersectionObserver);
    }
  }
}

/** Scroll the deferred (on viewport) sections "into view" and let them render. */
async function revealDeferred(fixture: ComponentFixture<ProductDetailComponent>): Promise<void> {
  fixture.detectChanges();
  StubIntersectionObserver.revealAll();
  await fixture.whenStable();
  fixture.detectChanges();
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
      { provide: PLATFORM_ID, useValue: 'browser' },
    ],
  });
  await TestBed.compileComponents();
  const fixture = TestBed.createComponent(ProductDetailComponent);
  fixture.detectChanges();
  return fixture;
}

describe('ProductDetail reviews section', () => {
  beforeEach(() => {
    TestBed.resetTestingModule();
    StubIntersectionObserver.instances = [];
    vi.stubGlobal('IntersectionObserver', StubIntersectionObserver);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('renders the #reviews anchor eagerly and defers the body until it nears the viewport', async () => {
    const fixture = await setup(makeProduct({ recent_reviews: [] }));
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('section#reviews h2')).not.toBeNull();
    expect(root.querySelector('.pdp-reviews__placeholder')).not.toBeNull();
    expect(root.querySelector('[data-testid="pdp-reviews-body"]')).toBeNull();

    await revealDeferred(fixture);
    expect(root.querySelector('[data-testid="pdp-reviews-body"]')).not.toBeNull();
    expect(root.querySelector('.pdp-reviews__placeholder')).toBeNull();
  });

  it('shows the empty-reviews state when the product has no reviews', async () => {
    const fixture = await setup(makeProduct({ recent_reviews: [] }));
    await revealDeferred(fixture);
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('#reviews')).not.toBeNull();
    expect(root.querySelector('[data-testid="pdp-reviews-empty"]')).not.toBeNull();
    expect(root.querySelector('.pdp-reviews__list')).toBeNull();
  });

  it('shows the reviews list (and not the empty state) when reviews exist', async () => {
    const fixture = await setup(
      makeProduct({
        rating: 4.5,
        review_count: 2,
        recent_reviews: [
          { id: 1, rating: 5, body: 'Beautiful fabric.', author: 'Sara', verified: true },
          { id: 2, rating: 4, body: 'Good fit.', author: 'Lina' },
        ],
      }),
    );
    await revealDeferred(fixture);
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('.pdp-reviews__list')).not.toBeNull();
    expect(root.querySelectorAll('.pdp-review')).toHaveLength(2);
    expect(root.querySelector('[data-testid="pdp-reviews-empty"]')).toBeNull();
  });
});
