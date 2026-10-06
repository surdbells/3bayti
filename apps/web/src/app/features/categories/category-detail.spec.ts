import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, Router, convertToParamMap, provideRouter, type ParamMap } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { BehaviorSubject, of, throwError } from 'rxjs';
import { TranslateService, provideTranslateService } from '@ngx-translate/core';

import { CategoryDetailComponent } from './category-detail';
import type { CategoryDetail } from './category.model';
import { CatalogService, type CatalogFilters, type Facets } from './catalog.service';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { SeoService } from '../../core/seo/seo.service';
import type { Product } from '../catalog/product.model';
import en from '../../../../public/i18n/en.json';
import ar from '../../../../public/i18n/ar.json';

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

function makeCategory(o: Partial<CategoryDetail> = {}): CategoryDetail {
  return {
    id: 4,
    slug: 'abayas',
    name: 'Abayas',
    description: null,
    image: null,
    product_count: 2,
    products: [makeProduct({ id: 1 }), makeProduct({ id: 2, slug: 'abaya-2' })],
    ...o,
  };
}

const ABAYAS = makeCategory();
const KAFTANS = makeCategory({
  id: 5,
  slug: 'kaftans',
  name: 'Kaftans',
  products: [makeProduct({ id: 31, slug: 'kaftan-31' })],
  product_count: 1,
});

class StubCatalogService {
  products = signal<Product[]>([]);
  total = signal(0);
  hasMore = signal(false);
  isLoadingList = signal(false);
  facets = signal<Facets | null>(null);
  isLoadingFacets = signal(false);

  /** What the next loadProducts call "returns" into the accumulator. */
  nextItems: Product[] = [];
  nextTotal = 0;
  /** When true, loadProducts rejects (like the real service's latest call
   *  failing) and leaves the accumulator untouched. */
  failLoads = false;

  loadCalls: Array<{ filters: CatalogFilters; page: number; append: boolean }> = [];
  facetCalls: CatalogFilters[] = [];
  resetCalls = 0;
  resetProductsCalls = 0;

  reset(): void {
    this.resetCalls += 1;
    this.products.set([]);
    this.total.set(0);
    this.hasMore.set(false);
    this.facets.set(null);
  }

  resetProducts(): void {
    this.resetProductsCalls += 1;
    this.products.set([]);
    this.total.set(0);
    this.hasMore.set(false);
  }

  async loadProducts(filters: CatalogFilters, page = 0, append = false) {
    this.loadCalls.push({ filters, page, append });
    if (this.failLoads) {
      throw new HttpErrorResponse({ status: 503 });
    }
    const items = this.nextItems;
    this.products.set(append ? [...this.products(), ...items] : items);
    this.total.set(this.nextTotal);
    return { items, total: this.nextTotal, hasMore: false };
  }

  async loadFacets(filters: CatalogFilters): Promise<Facets | null> {
    this.facetCalls.push(filters);
    return null;
  }
}

interface SetupOpts {
  slug?: string;
  query?: Record<string, string>;
  /** Force the category fetch to fail (any slug). */
  detail?: '404' | '500';
  catalogItems?: Product[];
  /** Make the catalog listing (GET /products) fail from the first call. */
  failLoads?: boolean;
  /** Override the embedded category for the default slug. */
  category?: CategoryDetail;
}

function setup(opts: SetupOpts = {}): {
  fixture: ComponentFixture<CategoryDetailComponent>;
  catalog: StubCatalogService;
  get: ReturnType<typeof vi.fn>;
  paramMap: BehaviorSubject<ParamMap>;
  queryParamMap: BehaviorSubject<ParamMap>;
} {
  const catalog = new StubCatalogService();
  catalog.nextItems = opts.catalogItems ?? [];
  catalog.nextTotal = catalog.nextItems.length;
  catalog.failLoads = opts.failLoads ?? false;
  const categories: Record<string, CategoryDetail> = {
    abayas: opts.category ?? ABAYAS,
    kaftans: KAFTANS,
  };
  const get = vi.fn((_route: string, req: { params: { slug: string } }) => {
    if (opts.detail === '500') return throwError(() => new HttpErrorResponse({ status: 500 }));
    const cat = opts.detail === '404' ? undefined : categories[req.params.slug];
    if (!cat) return throwError(() => new HttpErrorResponse({ status: 404 }));
    return of({ data: cat, meta: { total_products: cat.product_count, page_size: 20 } });
  });
  const paramMap = new BehaviorSubject<ParamMap>(convertToParamMap({ slug: opts.slug ?? 'abayas' }));
  const queryParamMap = new BehaviorSubject<ParamMap>(convertToParamMap(opts.query ?? {}));

  TestBed.configureTestingModule({
    imports: [CategoryDetailComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideTranslateService({ fallbackLang: 'en', lang: 'en' }),
      { provide: CatalogService, useValue: catalog },
      { provide: RoutedHttpClient, useValue: { get } },
      { provide: SeoService, useValue: { set: vi.fn(), setStructuredData: vi.fn() } },
      { provide: ActivatedRoute, useValue: { paramMap, queryParamMap } },
    ],
  });
  // Real English strings, so labels / accessible names can be asserted.
  TestBed.inject(TranslateService).setTranslation('en', en);

  const fixture = TestBed.createComponent(CategoryDetailComponent);
  fixture.detectChanges();
  return { fixture, catalog, get, paramMap, queryParamMap };
}

const q = (fixture: ComponentFixture<unknown>, testId: string): HTMLElement | null =>
  (fixture.nativeElement as HTMLElement).querySelector(`[data-testid="${testId}"]`);

describe('CategoryDetailComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('scopes the catalog listing + facets to the route slug, with URL filters', () => {
    const { catalog, get } = setup({ query: { sizes: 'M,L', sort: 'price_asc', min_price: '100' } });
    expect(get).toHaveBeenCalledWith('GET /categories/:slug', { params: { slug: 'abayas' } });
    expect(catalog.loadCalls).toHaveLength(1);
    expect(catalog.loadCalls[0]).toEqual({
      filters: {
        category: 'abayas',
        sizes: ['M', 'L'],
        colors: [],
        sort: 'price_asc',
        minPrice: 100,
        maxPrice: null,
        q: null,
      },
      page: 0,
      append: false,
    });
    expect(catalog.facetCalls[0].category).toBe('abayas');
  });

  describe('scope vs filter resets', () => {
    it('fully resets the shared catalog (incl. facets) on entering the page', () => {
      const { catalog } = setup();
      expect(catalog.resetCalls).toBe(1);
      expect(catalog.resetProductsCalls).toBe(0);
    });

    it('resets only the products (keeping the facets) on a filter change in the same category', () => {
      const { fixture, catalog, queryParamMap } = setup();
      // The filter bar navigates; the router then re-emits the query params.
      queryParamMap.next(convertToParamMap({ sizes: 'M' }));
      fixture.detectChanges();
      expect(catalog.resetCalls).toBe(1);
      expect(catalog.resetProductsCalls).toBe(1);
      expect(catalog.loadCalls.at(-1)).toMatchObject({ page: 0, append: false });
      expect(catalog.loadCalls.at(-1)?.filters).toMatchObject({ category: 'abayas', sizes: ['M'] });
    });

    it('writes filter changes to the URL query string (never the category scope)', () => {
      const { fixture } = setup();
      const nav = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      fixture.componentInstance.onFilterChange({
        category: 'abayas', sizes: ['S'], colors: [], sort: 'price_desc', minPrice: null, maxPrice: 300,
      });
      expect(nav).toHaveBeenCalledTimes(1);
      const extras = nav.mock.calls[0][1] as { queryParams: Record<string, string> };
      expect(extras.queryParams).toEqual({ sizes: 'S', sort: 'price_desc', max_price: '300' });
    });
  });

  describe('navigating between categories (same component instance)', () => {
    it('reloads header, listing and facets for /category/b after /category/a, with identical query params', async () => {
      const { fixture, catalog, get, paramMap } = setup({
        query: { colors: 'black' },
        catalogItems: [makeProduct({ id: 9, slug: 'abaya-9' })],
      });
      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'category-header')?.textContent).toContain('Abayas');

      // In-app hop: only the route param changes, the query params do not
      // re-emit (Angular reuses the component).
      catalog.nextItems = [makeProduct({ id: 31, slug: 'kaftan-31' })];
      catalog.nextTotal = 1;
      paramMap.next(convertToParamMap({ slug: 'kaftans' }));
      fixture.detectChanges();

      expect(get).toHaveBeenLastCalledWith('GET /categories/:slug', { params: { slug: 'kaftans' } });
      const last = catalog.loadCalls.at(-1);
      expect(last).toMatchObject({ page: 0, append: false });
      expect(last?.filters).toMatchObject({ category: 'kaftans', colors: ['black'] });
      expect(catalog.facetCalls.at(-1)?.category).toBe('kaftans');
      // A new category is a new scope: a full reset (facets too), not just products.
      expect(catalog.resetCalls).toBe(2);
      expect(fixture.componentInstance.currentFilters().category).toBe('kaftans');

      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'category-header')?.textContent).toContain('Kaftans');
      expect(q(fixture, 'category-header')?.textContent).not.toContain('Abayas');
      expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([31]);
    });

    it('clears a previous not-found state when moving on to a real category', () => {
      const { fixture, paramMap } = setup({ slug: 'nope' });
      expect(q(fixture, 'category-not-found')).not.toBeNull();
      paramMap.next(convertToParamMap({ slug: 'kaftans' }));
      fixture.detectChanges();
      expect(q(fixture, 'category-not-found')).toBeNull();
      expect(q(fixture, 'category-header')?.textContent).toContain('Kaftans');
    });
  });

  describe('category header failures', () => {
    it('renders the not-found state (not an endless "loading") for an unknown slug, without listing calls', () => {
      const { fixture, catalog } = setup({ detail: '404' });
      expect(q(fixture, 'category-not-found')).not.toBeNull();
      expect(q(fixture, 'category-header')).toBeNull();
      expect(catalog.loadCalls).toHaveLength(0);
      expect(catalog.facetCalls).toHaveLength(0);
    });

    it('renders a load-error state (not "not found") for other failures', () => {
      vi.spyOn(console, 'error').mockImplementation(() => undefined);
      const { fixture, catalog } = setup({ detail: '500' });
      expect(q(fixture, 'category-load-error')).not.toBeNull();
      expect(q(fixture, 'category-not-found')).toBeNull();
      expect(catalog.loadCalls).toHaveLength(0);
    });
  });

  describe('listing failure', () => {
    it('shows category-list-error (not category-empty) when a filtered listing fails; Retry focuses the grid region', async () => {
      const { fixture, catalog } = setup({ query: { colors: 'gold' }, failLoads: true });
      await fixture.whenStable();
      fixture.detectChanges();

      const error = q(fixture, 'category-list-error');
      expect(error).not.toBeNull();
      expect(error?.getAttribute('role')).toBe('alert');
      expect(q(fixture, 'category-empty')).toBeNull();
      expect(q(fixture, 'category-grid')).toBeNull();

      // Retry re-runs the listing for the same slug + filters, and moves
      // focus to the stable grid region (the Retry button is about to go).
      catalog.failLoads = false;
      catalog.nextItems = [makeProduct({ id: 9, slug: 'gold-9' })];
      catalog.nextTotal = 1;
      const retry = q(fixture, 'category-list-retry') as HTMLButtonElement;
      retry.focus();
      retry.click();
      expect(document.activeElement).toBe(q(fixture, 'category-grid-region'));
      fixture.detectChanges();
      expect(catalog.loadCalls).toHaveLength(2);
      expect(catalog.loadCalls[1]).toMatchObject({ page: 0, append: false });
      expect(catalog.loadCalls[1].filters).toMatchObject({ category: 'abayas', colors: ['gold'] });
      expect(catalog.resetProductsCalls).toBe(1); // same category → facets kept
      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'category-list-error')).toBeNull();
      expect(fixture.nativeElement.querySelectorAll('[data-testid="category-grid"] ui-product-card').length).toBe(1);
      expect(document.activeElement).toBe(q(fixture, 'category-grid-region'));
    });

    it('keeps the embedded products for the unfiltered view and offers a fallback retry when more exist', async () => {
      const { fixture } = setup({ failLoads: true, category: makeCategory({ product_count: 30 }) });
      await fixture.whenStable();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelectorAll('[data-testid="category-grid"] ui-product-card').length).toBe(2);
      expect(q(fixture, 'category-list-error')).toBeNull();
      expect(q(fixture, 'category-fallback-retry')).not.toBeNull();
    });

    it('shows the empty state only for a listing that loaded with no matches', async () => {
      const { fixture } = setup({ query: { colors: 'gold' }, catalogItems: [] });
      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'category-empty')).not.toBeNull();
      expect(q(fixture, 'category-list-error')).toBeNull();
      expect(q(fixture, 'category-grid')).toBeNull();
    });
  });

  describe('"load more"', () => {
    it('keeps the grid and the (aria-disabled) button on screen while the next page loads', async () => {
      const { fixture, catalog } = setup({ catalogItems: [makeProduct({ id: 9 })] });
      await fixture.whenStable();
      catalog.hasMore.set(true);
      fixture.detectChanges();
      const btn = q(fixture, 'category-load-more') as HTMLButtonElement;
      expect(btn).not.toBeNull();

      catalog.isLoadingList.set(true);
      fixture.detectChanges();
      expect(q(fixture, 'category-grid')).not.toBeNull();
      expect(fixture.nativeElement.querySelector('.grid-loading')).toBeNull();
      expect(q(fixture, 'category-load-more')).toBe(btn);
      expect(btn.getAttribute('aria-disabled')).toBe('true');
      expect(btn.disabled).toBe(false); // stays focusable
      expect(btn.textContent?.trim()).toBe(en.categories.loading);
      expect(btn.hasAttribute('aria-label')).toBe(false); // name = visible "Loading…"

      // A click while loading is ignored.
      btn.click();
      expect(catalog.loadCalls).toHaveLength(1);
    });

    it('rolls the page back on failure and turns the SAME button into a retry for the same page', async () => {
      const { fixture, catalog } = setup({ catalogItems: [makeProduct({ id: 9 })] });
      await fixture.whenStable();
      catalog.hasMore.set(true);
      fixture.detectChanges();
      const cmp = fixture.componentInstance;
      const btn = q(fixture, 'category-load-more') as HTMLButtonElement;
      expect(btn.textContent?.trim()).toBe('Load more');
      expect(btn.getAttribute('aria-label')).toBe('Load more products');
      btn.focus();

      catalog.failLoads = true;
      btn.click();
      await fixture.whenStable();
      fixture.detectChanges();
      expect(catalog.loadCalls.at(-1)).toMatchObject({ page: 1, append: true });
      expect(cmp.page()).toBe(0); // rolled back, not left at 1
      expect(cmp.loadMoreFailed()).toBe(true);

      const error = q(fixture, 'category-load-more-error');
      expect(error).not.toBeNull();
      expect(error?.getAttribute('role')).toBe('alert');
      // Same element, new label: keyboard focus is not dropped to <body>.
      expect(q(fixture, 'category-load-more-retry')).toBe(btn);
      expect(q(fixture, 'category-load-more')).toBeNull();
      expect(document.activeElement).toBe(btn);
      // Label in name (WCAG 2.5.3): the accessible name contains the visible "Retry".
      expect(btn.textContent?.trim()).toBe('Retry');
      expect(btn.getAttribute('aria-label')).toBe('Retry loading more products');

      // Retry asks for page 1 again (no page skipped) and clears the error.
      catalog.failLoads = false;
      catalog.nextItems = [makeProduct({ id: 10, slug: 'abaya-10' })];
      btn.click();
      await fixture.whenStable();
      fixture.detectChanges();
      expect(catalog.loadCalls.at(-1)).toMatchObject({ page: 1, append: true });
      expect(cmp.page()).toBe(1);
      expect(cmp.loadMoreFailed()).toBe(false);
      expect(q(fixture, 'category-load-more-error')).toBeNull();
      expect(q(fixture, 'category-load-more')).toBe(btn);
      expect(document.activeElement).toBe(btn);
      expect(cmp.products().map((p) => p.id)).toEqual([9, 10]);
    });
  });

  it('gives "load more" / its retry accessible names that contain the visible label (WCAG 2.5.3), en + ar', () => {
    for (const dict of [en, ar]) {
      for (const ns of [dict.categories, dict.collections]) {
        expect(ns.loadMoreAria).toContain(ns.loadMore);
        expect(ns.loadMoreRetryAria).toContain(ns.retry);
      }
    }
  });
});
