import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { ActivatedRoute } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ProductListingPageComponent } from './product-listing-page';
import { CatalogService } from '../categories/catalog.service';
import type { Facets } from '../categories/catalog.service';
import { SeoService } from '../../core/seo/seo.service';
import { provideI18n } from '../../core/i18n';
import type { Product } from '../catalog/product.model';

function makeProduct(o: Partial<Product> = {}): Product {
  return {
    id: 1,
    slug: 'abaya-1',
    name: 'Midnight Abaya',
    price: { amount: 499, currency: 'AED' },
    primary_image: null,
    in_stock: true,
    ...o,
  };
}

class StubCatalogService {
  products = signal<Product[]>([]);
  total = signal(0);
  hasMore = signal(false);
  isLoadingList = signal(false);
  facets = signal<Facets | null>(null);
  isLoadingFacets = signal(false);

  resetCalls = 0;
  loadCalls: Array<{ filters: Record<string, unknown>; page: number; append: boolean }> = [];
  facetCalls: Array<Record<string, unknown>> = [];

  reset(): void {
    this.resetCalls += 1;
    this.products.set([]);
    this.total.set(0);
    this.hasMore.set(false);
  }

  async loadProducts(
    filters: Record<string, unknown>,
    page = 0,
    append = false,
  ): Promise<{ items: Product[]; total: number; hasMore: boolean }> {
    this.loadCalls.push({ filters, page, append });
    return { items: [], total: 0, hasMore: false };
  }

  async loadFacets(filters: Record<string, unknown>): Promise<Facets | null> {
    this.facetCalls.push(filters);
    return null;
  }
}

class StubSeoService {
  setCalls: unknown[] = [];
  structuredCalls: unknown[] = [];
  set(meta: unknown): void { this.setCalls.push(meta); }
  setStructuredData(data: unknown): void { this.structuredCalls.push(data); }
}

const BEST_SELLERS_DATA = {
  sort: 'best_seller',
  i18nKey: 'bestSellers',
  canonicalPath: '/best-sellers',
  seoTitle: 'Best Sellers · 3bayti',
  seoDescription: 'Shop the most-loved pieces.',
};

const NEW_ARRIVALS_DATA = {
  sort: 'newest',
  i18nKey: 'newArrivals',
  canonicalPath: '/new-arrivals',
  seoTitle: 'New Arrivals · 3bayti',
  seoDescription: 'The latest pieces just added.',
};

function setup(routeData: Record<string, unknown> = BEST_SELLERS_DATA): {
  fixture: ComponentFixture<ProductListingPageComponent>;
  catalog: StubCatalogService;
  seo: StubSeoService;
} {
  const catalog = new StubCatalogService();
  const seo = new StubSeoService();

  TestBed.configureTestingModule({
    imports: [ProductListingPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: CatalogService, useValue: catalog },
      { provide: SeoService, useValue: seo },
      { provide: ActivatedRoute, useValue: { snapshot: { data: routeData } } },
    ],
  });

  const fixture = TestBed.createComponent(ProductListingPageComponent);
  fixture.detectChanges();
  return { fixture, catalog, seo };
}

describe('ProductListingPageComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('resets and loads products with the route sort on init', () => {
    const { catalog } = setup();
    expect(catalog.resetCalls).toBe(1);
    expect(catalog.loadCalls).toHaveLength(1);
    expect(catalog.loadCalls[0]).toEqual({
      filters: { sort: 'best_seller' },
      page: 0,
      append: false,
    });
  });

  it('sets SEO meta + structured data from the route data', () => {
    const { seo } = setup();
    expect(seo.setCalls).toHaveLength(1);
    expect((seo.setCalls[0] as { title: string }).title).toBe('Best Sellers · 3bayti');
    expect(seo.structuredCalls).toHaveLength(1);
  });

  it('renders a skeleton grid while first load is in flight', () => {
    const { fixture, catalog } = setup();
    catalog.isLoadingList.set(true);
    catalog.products.set([]);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.listing-skeleton')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid="listing-grid"]')).toBeNull();
  });

  it('renders one card per product once loaded', () => {
    const { fixture, catalog } = setup();
    catalog.isLoadingList.set(false);
    catalog.products.set([makeProduct({ id: 1 }), makeProduct({ id: 2, slug: 'abaya-2' })]);
    fixture.detectChanges();
    const grid = fixture.nativeElement.querySelector('[data-testid="listing-grid"]');
    expect(grid).not.toBeNull();
    expect(grid.querySelectorAll('ui-product-card').length).toBe(2);
  });

  it('shows the empty state when there are no products', () => {
    const { fixture, catalog } = setup();
    catalog.isLoadingList.set(false);
    catalog.products.set([]);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[data-testid="listing-empty"]')).not.toBeNull();
  });

  it('loads the next page (append) when "load more" is clicked', () => {
    const { fixture, catalog } = setup();
    catalog.isLoadingList.set(false);
    catalog.products.set([makeProduct()]);
    catalog.hasMore.set(true);
    fixture.detectChanges();

    const btn = fixture.nativeElement.querySelector('[data-testid="listing-load-more"]') as HTMLButtonElement;
    expect(btn).not.toBeNull();
    btn.click();

    expect(catalog.loadCalls).toHaveLength(2);
    expect(catalog.loadCalls[1]).toEqual({
      filters: { sort: 'best_seller' },
      page: 1,
      append: true,
    });
  });

  it('hides "load more" when there are no further pages', () => {
    const { fixture, catalog } = setup();
    catalog.isLoadingList.set(false);
    catalog.products.set([makeProduct()]);
    catalog.hasMore.set(false);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[data-testid="listing-load-more"]')).toBeNull();
  });

  describe('New Arrivals (same component, different route data)', () => {
    it('loads with sort=newest and sets the New Arrivals SEO title', () => {
      const { catalog, seo } = setup(NEW_ARRIVALS_DATA);
      expect(catalog.loadCalls[0]).toEqual({
        filters: { sort: 'newest' },
        page: 0,
        append: false,
      });
      expect((seo.setCalls[0] as { title: string }).title).toBe('New Arrivals · 3bayti');
    });

    it('appends the next page with sort=newest on "load more"', () => {
      const { fixture, catalog } = setup(NEW_ARRIVALS_DATA);
      catalog.isLoadingList.set(false);
      catalog.products.set([makeProduct()]);
      catalog.hasMore.set(true);
      fixture.detectChanges();

      (fixture.nativeElement.querySelector('[data-testid="listing-load-more"]') as HTMLButtonElement).click();
      expect(catalog.loadCalls[1]).toEqual({
        filters: { sort: 'newest' },
        page: 1,
        append: true,
      });
    });
  });

  describe('Filters (web-uplift #2/#3)', () => {
    function makeFacets(): Facets {
      return {
        size: { values: [{ value: 'M', count: 4 }, { value: 'L', count: 2 }], total_distinct: 2 },
        color: { values: [{ value: 'black', count: 6 }], total_distinct: 1 },
        price: { values: [{ value: '0-100', count: 3, min: 0, max: 100 }] },
        vendor: { values: [], total_distinct: 0 },
        category: { values: [], total_distinct: 0 },
        total_products: 6,
      };
    }

    it('renders the shared filter bar', () => {
      const { fixture } = setup();
      expect(fixture.nativeElement.querySelector('[data-testid="filter-bar"]')).not.toBeNull();
    });

    it('requests facets on init with the route sort', () => {
      const { catalog } = setup();
      expect(catalog.facetCalls).toHaveLength(1);
      expect(catalog.facetCalls[0]).toEqual({ sort: 'best_seller' });
    });

    it('reloads products + facets with the chosen size when a size filter is applied', () => {
      const { fixture, catalog } = setup();
      catalog.facets.set(makeFacets());
      fixture.detectChanges();

      // Open the Size popover, then tick the first size checkbox.
      (fixture.nativeElement.querySelector('[data-testid="chip-size"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      const checkbox = fixture.nativeElement.querySelector(
        '[data-testid="pop-size"] input[type="checkbox"]',
      ) as HTMLInputElement;
      checkbox.dispatchEvent(new Event('change', { bubbles: true }));
      fixture.detectChanges();

      const lastLoad = catalog.loadCalls[catalog.loadCalls.length - 1];
      expect(lastLoad).toEqual({
        filters: { sort: 'best_seller', sizes: ['M'] },
        page: 0,
        append: false,
      });
      expect(catalog.facetCalls[catalog.facetCalls.length - 1]).toEqual({
        sort: 'best_seller',
        sizes: ['M'],
      });
    });
  });
});
