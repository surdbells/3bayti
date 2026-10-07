import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, Router, convertToParamMap, provideRouter, type ParamMap } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { BehaviorSubject, Subject, of, throwError } from 'rxjs';
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
  /** When set, a load also sets hasMore (e.g. false: it was the last page). */
  nextHasMore: boolean | null = null;
  /** When true, loads stay pending until releaseLoads() (a slow response). */
  holdLoads = false;
  private held: Array<() => void> = [];

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
    if (this.holdLoads) {
      this.isLoadingList.set(true);
      await new Promise<void>((resolve) => this.held.push(resolve));
      this.isLoadingList.set(false);
    }
    if (this.failLoads) {
      throw new HttpErrorResponse({ status: 503 });
    }
    const items = this.nextItems;
    this.products.set(append ? [...this.products(), ...items] : items);
    this.total.set(this.nextTotal);
    if (this.nextHasMore !== null) this.hasMore.set(this.nextHasMore);
    return { items, total: this.nextTotal, hasMore: this.nextHasMore ?? false };
  }

  /** Let every held load settle. */
  releaseLoads(): void {
    const held = this.held;
    this.held = [];
    held.forEach((resolve) => resolve());
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
  /** Keep GET /categories/:slug pending until the test settles header.pending. */
  deferHeader?: boolean;
  /** Keep catalog loads pending until catalog.releaseLoads(). */
  holdLoads?: boolean;
}

/** The GET /categories/:slug envelope for a category. */
function envelope(cat: CategoryDetail) {
  return { data: cat, meta: { total_products: cat.product_count, page_size: 20 } };
}

/** Test-side control of GET /categories/:slug. */
interface HeaderControl {
  /** Fail every request (any slug); change it to let a later Retry succeed. */
  fail: '404' | '500' | undefined;
  /** When set, requests stay pending on this subject until the test settles it. */
  pending: Subject<unknown> | null;
}

function setup(opts: SetupOpts = {}): {
  fixture: ComponentFixture<CategoryDetailComponent>;
  catalog: StubCatalogService;
  get: ReturnType<typeof vi.fn>;
  paramMap: BehaviorSubject<ParamMap>;
  queryParamMap: BehaviorSubject<ParamMap>;
  header: HeaderControl;
} {
  const catalog = new StubCatalogService();
  catalog.nextItems = opts.catalogItems ?? [];
  catalog.nextTotal = catalog.nextItems.length;
  catalog.failLoads = opts.failLoads ?? false;
  catalog.holdLoads = opts.holdLoads ?? false;
  const categories: Record<string, CategoryDetail> = {
    abayas: opts.category ?? ABAYAS,
    kaftans: KAFTANS,
  };
  const header: HeaderControl = {
    fail: opts.detail,
    pending: opts.deferHeader ? new Subject<unknown>() : null,
  };
  const get = vi.fn((_route: string, req: { params: { slug: string } }) => {
    if (header.pending) return header.pending.asObservable();
    if (header.fail === '500') return throwError(() => new HttpErrorResponse({ status: 500 }));
    const cat = header.fail === '404' ? undefined : categories[req.params.slug];
    if (!cat) return throwError(() => new HttpErrorResponse({ status: 404 }));
    return of(envelope(cat));
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
  return { fixture, catalog, get, paramMap, queryParamMap, header };
}

const q = (fixture: ComponentFixture<unknown>, testId: string): HTMLElement | null =>
  (fixture.nativeElement as HTMLElement).querySelector(`[data-testid="${testId}"]`);

/** Let released (held) loads, and the callbacks chained on them, settle. */
const settle = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

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

    it('offers a Retry on a header load error that re-requests the header + listing, keeping focus on the page', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => undefined);
      const { fixture, catalog, get, header } = setup({
        detail: '500',
        query: { colors: 'black' },
        catalogItems: [makeProduct({ id: 9, slug: 'abaya-9' })],
      });
      expect(q(fixture, 'category-load-error')?.querySelector('[role="alert"]')?.textContent)
        .toContain(en.categories.loadError.body);
      const retry = q(fixture, 'category-load-error-retry') as HTMLButtonElement;
      expect(retry.textContent?.trim()).toBe('Retry');
      // Label in name (WCAG 2.5.3): the accessible name contains "Retry".
      expect(retry.getAttribute('aria-label')).toBe('Retry loading this category');
      expect(catalog.loadCalls).toHaveLength(0);

      header.fail = undefined; // the API has recovered
      retry.focus();
      retry.click();
      expect(get).toHaveBeenCalledTimes(2);
      expect(get).toHaveBeenLastCalledWith('GET /categories/:slug', { params: { slug: 'abayas' } });
      // The Retry button is about to go: focus moves to the stable page body.
      const body = q(fixture, 'category-page-body');
      expect(body?.getAttribute('tabindex')).toBe('-1');
      expect(document.activeElement).toBe(body);

      fixture.detectChanges();
      expect(q(fixture, 'category-load-error')).toBeNull();
      expect(q(fixture, 'category-header')?.textContent).toContain('Abayas');
      // ...and the listing + facets that depend on it run for the same slug + filters.
      expect(catalog.loadCalls).toHaveLength(1);
      expect(catalog.loadCalls[0].filters).toMatchObject({ category: 'abayas', colors: ['black'] });
      expect(catalog.facetCalls).toHaveLength(1);
      await fixture.whenStable();
      fixture.detectChanges();
      expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([9]);
      expect(document.activeElement).toBe(q(fixture, 'category-page-body'));
    });

    it('keeps the load-error state (and focus on the page body) when the header Retry fails again', () => {
      vi.spyOn(console, 'error').mockImplementation(() => undefined);
      const { fixture, catalog, get } = setup({ detail: '500' });
      const retry = q(fixture, 'category-load-error-retry') as HTMLButtonElement;
      retry.focus();
      retry.click();
      fixture.detectChanges();
      expect(get).toHaveBeenCalledTimes(2);
      expect(q(fixture, 'category-load-error')).not.toBeNull();
      expect(q(fixture, 'category-load-error-retry')).not.toBeNull();
      expect(document.activeElement).toBe(q(fixture, 'category-page-body'));
      expect(catalog.loadCalls).toHaveLength(0);
    });

    it('localises the header Retry (en + ar), its accessible name containing the visible label', () => {
      for (const dict of [en, ar]) {
        for (const ns of [dict.categories, dict.collections]) {
          expect(ns.loadError.retryAria).toContain(ns.retry);
          expect(ns.loadError.body).not.toMatch(/refresh|تحديث الصفحة/i);
        }
      }
    });
  });

  describe('listing in parallel with the header', () => {
    it('requests the listing + facets before the header resolves, and renders them once it lands', async () => {
      const { fixture, catalog, header } = setup({
        deferHeader: true,
        query: { colors: 'black' },
        catalogItems: [makeProduct({ id: 9, slug: 'abaya-9' })],
      });
      // GET /categories/:slug is still pending, yet the route-scoped listing
      // and facets are already on their way (one round trip, not two).
      expect(q(fixture, 'category-header')).toBeNull();
      expect(catalog.loadCalls).toHaveLength(1);
      expect(catalog.loadCalls[0]).toMatchObject({ page: 0, append: false });
      expect(catalog.loadCalls[0].filters).toMatchObject({ category: 'abayas', colors: ['black'] });
      expect(catalog.facetCalls).toHaveLength(1);
      expect(catalog.facetCalls[0].category).toBe('abayas');

      // The listing answers first: still no grid without the header.
      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'category-grid')).toBeNull();

      header.pending!.next(envelope(ABAYAS));
      fixture.detectChanges();
      expect(q(fixture, 'category-header')?.textContent).toContain('Abayas');
      expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([9]);
      expect(fixture.nativeElement.querySelectorAll('[data-testid="category-grid"] ui-product-card').length).toBe(1);
      // The header's arrival does not re-request anything.
      expect(catalog.loadCalls).toHaveLength(1);
      expect(catalog.facetCalls).toHaveLength(1);
    });

    it('shows the embedded products for the unfiltered view while the parallel listing is in flight', async () => {
      const { fixture, catalog, header } = setup({
        deferHeader: true,
        holdLoads: true,
        catalogItems: [makeProduct({ id: 9, slug: 'abaya-9' })],
      });
      expect(catalog.loadCalls).toHaveLength(1);
      header.pending!.next(envelope(ABAYAS));
      fixture.detectChanges();
      expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([1, 2]);
      expect(fixture.nativeElement.querySelector('.grid-loading')).toBeNull();

      catalog.releaseLoads();
      await settle();
      await fixture.whenStable();
      fixture.detectChanges();
      expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([9]);
    });

    it('discards the listing when the header then 404s: no grid, catalog reset, nothing re-requested', async () => {
      const { fixture, catalog, header } = setup({
        deferHeader: true,
        catalogItems: [makeProduct({ id: 9, slug: 'abaya-9' })],
      });
      expect(catalog.loadCalls).toHaveLength(1);
      expect(catalog.resetCalls).toBe(1);

      header.pending!.error(new HttpErrorResponse({ status: 404 }));
      fixture.detectChanges();
      expect(q(fixture, 'category-not-found')).not.toBeNull();
      expect(catalog.resetCalls).toBe(2); // listing + facets dropped

      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'category-grid')).toBeNull();
      expect(fixture.componentInstance.products()).toEqual([]);
      expect(catalog.loadCalls).toHaveLength(1);
      expect(catalog.facetCalls).toHaveLength(1);
    });

    it('ignores a listing that answers after the header failed, then lists again on Retry', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => undefined);
      const { fixture, catalog, header } = setup({
        deferHeader: true,
        holdLoads: true,
        catalogItems: [makeProduct({ id: 9, slug: 'abaya-9' })],
      });
      expect(catalog.loadCalls).toHaveLength(1);

      header.pending!.error(new HttpErrorResponse({ status: 503 }));
      fixture.detectChanges();
      expect(q(fixture, 'category-load-error')).not.toBeNull();

      // The stale listing lands late: it must not resurrect a grid.
      catalog.releaseLoads();
      await settle();
      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'category-load-error')).not.toBeNull();
      expect(fixture.componentInstance.products()).toEqual([]);

      // Retry: header + listing again, in parallel.
      catalog.holdLoads = false;
      header.pending = new Subject<unknown>();
      const calls = catalog.loadCalls.length;
      (q(fixture, 'category-load-error-retry') as HTMLButtonElement).click();
      fixture.detectChanges();
      expect(catalog.loadCalls).toHaveLength(calls + 1);
      expect(q(fixture, 'category-header')).toBeNull(); // header still pending
      header.pending.next(envelope(ABAYAS));
      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'category-header')?.textContent).toContain('Abayas');
      expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([9]);
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

    describe('focus when the LAST page loads (the button goes away)', () => {
      async function onLastPage() {
        const ctx = setup({ catalogItems: [makeProduct({ id: 9, slug: 'abaya-9' })] });
        await ctx.fixture.whenStable();
        ctx.catalog.hasMore.set(true);
        ctx.fixture.detectChanges();
        ctx.catalog.nextItems = [
          makeProduct({ id: 10, slug: 'abaya-10' }),
          makeProduct({ id: 11, slug: 'abaya-11' }),
        ];
        ctx.catalog.nextHasMore = false;
        return { ...ctx, btn: q(ctx.fixture, 'category-load-more') as HTMLButtonElement };
      }

      it('moves focus to the first appended product link', async () => {
        const { fixture, btn } = await onLastPage();
        btn.focus();
        btn.click();
        await fixture.whenStable();
        fixture.detectChanges();
        expect(q(fixture, 'category-load-more')).toBeNull();
        const cards = fixture.nativeElement.querySelectorAll('[data-testid="category-grid"] ui-product-card');
        expect(cards.length).toBe(3);
        expect(document.activeElement).toBe(cards[1].querySelector('a'));
        expect(document.activeElement?.getAttribute('href')).toContain('abaya-10');
      });

      it('also does so when the in-place retry fetches the last page', async () => {
        const { fixture, catalog, btn } = await onLastPage();
        btn.focus();
        catalog.failLoads = true;
        btn.click();
        await fixture.whenStable();
        fixture.detectChanges();
        expect(q(fixture, 'category-load-more-retry')).toBe(btn);
        expect(document.activeElement).toBe(btn);

        catalog.failLoads = false;
        btn.click();
        await fixture.whenStable();
        fixture.detectChanges();
        expect(q(fixture, 'category-load-more-retry')).toBeNull();
        const cards = fixture.nativeElement.querySelectorAll('[data-testid="category-grid"] ui-product-card');
        expect(document.activeElement).toBe(cards[1].querySelector('a'));
      });

      it('falls back to the grid region when the appended card has no product link', async () => {
        const { fixture, catalog, btn } = await onLastPage();
        catalog.nextItems = [makeProduct({ id: 10, slug: '' })];
        btn.focus();
        btn.click();
        await fixture.whenStable();
        fixture.detectChanges();
        expect(document.activeElement).toBe(q(fixture, 'category-grid-region'));
      });

      it('does not move focus when the shopper was not on the button', async () => {
        const { fixture, btn } = await onLastPage();
        const crumb = fixture.nativeElement.querySelector('.breadcrumb-link') as HTMLAnchorElement;
        crumb.focus();
        btn.click(); // e.g. a pointer click that never focused the button
        await fixture.whenStable();
        fixture.detectChanges();
        expect(q(fixture, 'category-load-more')).toBeNull();
        expect(document.activeElement).toBe(crumb);
      });

      it('does not steal focus the shopper moved elsewhere while the page loaded', async () => {
        const { fixture, catalog, btn } = await onLastPage();
        catalog.holdLoads = true;
        btn.focus();
        btn.click();
        const crumb = fixture.nativeElement.querySelector('.breadcrumb-link') as HTMLAnchorElement;
        crumb.focus();
        catalog.releaseLoads();
        await settle();
        await fixture.whenStable();
        fixture.detectChanges();
        expect(q(fixture, 'category-load-more')).toBeNull();
        expect(document.activeElement).toBe(crumb);
      });

      it('does not move focus on the initial page load', async () => {
        (document.activeElement as HTMLElement | null)?.blur();
        const { fixture } = setup({ catalogItems: [makeProduct({ id: 9, slug: 'abaya-9' })] });
        await fixture.whenStable();
        fixture.detectChanges();
        expect(document.activeElement).toBe(document.body);
      });
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
