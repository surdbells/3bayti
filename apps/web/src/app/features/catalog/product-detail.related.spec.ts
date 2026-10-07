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
import type { Product, ProductDetail } from './product.model';

/**
 * "You may also like" coverage for the PDP. The section is a single,
 * horizontally scrollable row of the shared ui-product-card (scroll-snap +
 * arrow buttons), capped at 8, rendered with @defer (on viewport). These
 * tests drive the product + recommendations through the component's real
 * route → fetch pipeline and assert the rendered row.
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

async function setup(opts: { product?: ProductDetail; recs?: Product[] } = {}): Promise<ComponentFixture<ProductDetailComponent>> {
  const product = opts.product ?? makeProduct();
  const recs = (opts.recs ?? []).map((p) => ({ product: p }));

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
      { provide: RecommendationsService, useValue: { forProduct: vi.fn(() => Promise.resolve(recs)) } },
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

/** Flush the recommendations promise (toSignal ← from(Promise)), re-render,
 *  then bring the deferred row "into view". */
async function settle(fixture: ComponentFixture<ProductDetailComponent>): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
  fixture.detectChanges();
  await revealDeferred(fixture);
}

describe('ProductDetailComponent — "You may also like" row', () => {
  beforeEach(() => {
    StubIntersectionObserver.instances = [];
    vi.stubGlobal('IntersectionObserver', StubIntersectionObserver);
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it('renders engine recommendations as a single scrollable row of cards under the heading', async () => {
    const fixture = await setup({ recs: [makeRelated(1), makeRelated(2), makeRelated(3)] });
    await settle(fixture);
    const root: HTMLElement = fixture.nativeElement;
    const section = root.querySelector('.pdp-related');
    expect(section).not.toBeNull();
    expect(section!.querySelector('.pdp-related__heading')).not.toBeNull();
    const rail = section!.querySelector('ul.pdp-related__rail')!;
    expect(rail).not.toBeNull();
    expect(rail.querySelectorAll(':scope > li[data-rail-item] > ui-product-card').length).toBe(3);
    // The old 2-row grid is gone.
    expect(section!.querySelector('.pdp-related__grid')).toBeNull();
  });

  it('shows a sized placeholder until the row nears the viewport', async () => {
    const fixture = await setup({ recs: [makeRelated(1), makeRelated(2)] });
    await new Promise((resolve) => setTimeout(resolve, 0));
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('.pdp-related .pdp-related__placeholder')).not.toBeNull();
    expect(root.querySelector('.pdp-related ui-product-card')).toBeNull();
    await revealDeferred(fixture);
    expect(root.querySelector('.pdp-related .pdp-related__placeholder')).toBeNull();
    expect(root.querySelectorAll('.pdp-related ui-product-card').length).toBe(2);
  });

  it('caps the row at 8 cards (and asks the engine for 8) even when more are returned', async () => {
    const recs = Array.from({ length: 14 }, (_, i) => makeRelated(i));
    const fixture = await setup({ recs });
    await settle(fixture);
    const cards = fixture.nativeElement.querySelectorAll('.pdp-related__rail ui-product-card');
    expect(cards.length).toBe(8);
    const recsService = TestBed.inject(RecommendationsService) as unknown as { forProduct: ReturnType<typeof vi.fn> };
    expect(recsService.forProduct).toHaveBeenCalledTimes(1);
    expect(recsService.forProduct).toHaveBeenCalledWith('abaya-01', 8);
  });

  it('falls back to the product related_products when the engine is empty', async () => {
    const product = makeProduct({
      related_products: [makeRelated(1), makeRelated(2), makeRelated(3), makeRelated(4)],
    });
    const fixture = await setup({ product, recs: [] });
    await settle(fixture);
    const cards = fixture.nativeElement.querySelectorAll('.pdp-related__rail ui-product-card');
    expect(cards.length).toBe(4);
  });

  it('omits the section (and the old strip) when there is nothing to recommend', async () => {
    const fixture = await setup({ recs: [] });
    await settle(fixture);
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('.pdp-related')).toBeNull();
    expect(root.querySelector('ui-product-strip')).toBeNull();
  });
});
