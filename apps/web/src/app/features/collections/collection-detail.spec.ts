import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, Router, convertToParamMap, provideRouter } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { of, throwError } from 'rxjs';

import { CollectionDetailComponent } from './collection-detail';
import type { CollectionDetail } from './collection.model';
import { CatalogService, type CatalogFilters, type Facets } from '../categories/catalog.service';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
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

function makeCollection(o: Partial<CollectionDetail> = {}): CollectionDetail {
  return {
    id: 3,
    slug: 'eid-edit',
    name: 'Eid Edit',
    description: 'Festive pieces for Eid.',
    image_url: null,
    product_count: 2,
    products: [makeProduct({ id: 1 }), makeProduct({ id: 2, slug: 'abaya-2' })],
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
  query?: Record<string, string>;
  detail?: 'ok' | '404' | '500';
  collection?: CollectionDetail;
  catalogItems?: Product[];
  /** Make the catalog listing (GET /products) fail from the first call. */
  failLoads?: boolean;
}

function setup(opts: SetupOpts = {}): {
  fixture: ComponentFixture<CollectionDetailComponent>;
  catalog: StubCatalogService;
  get: ReturnType<typeof vi.fn>;
  seo: { set: ReturnType<typeof vi.fn>; setStructuredData: ReturnType<typeof vi.fn> };
} {
  const catalog = new StubCatalogService();
  catalog.nextItems = opts.catalogItems ?? [];
  catalog.nextTotal = catalog.nextItems.length;
  catalog.failLoads = opts.failLoads ?? false;
  const col = opts.collection ?? makeCollection();
  const get = vi.fn(() => {
    if (opts.detail === '404') return throwError(() => new HttpErrorResponse({ status: 404 }));
    if (opts.detail === '500') return throwError(() => new HttpErrorResponse({ status: 500 }));
    return of({ data: col, meta: { total_products: col.product_count, page_size: 20 } });
  });
  const seo = { set: vi.fn(), setStructuredData: vi.fn() };

  TestBed.configureTestingModule({
    imports: [CollectionDetailComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: CatalogService, useValue: catalog },
      { provide: RoutedHttpClient, useValue: { get } },
      { provide: SeoService, useValue: seo },
      {
        provide: ActivatedRoute,
        useValue: {
          paramMap: of(convertToParamMap({ slug: col.slug })),
          queryParamMap: of(convertToParamMap(opts.query ?? {})),
        },
      },
    ],
  });

  const fixture = TestBed.createComponent(CollectionDetailComponent);
  fixture.detectChanges();
  return { fixture, catalog, get, seo };
}

describe('CollectionDetailComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('fetches the collection by slug and renders the banner header', () => {
    const { fixture, get } = setup();
    expect(get).toHaveBeenCalledWith('GET /collections/:slug', { params: { slug: 'eid-edit' } });
    const el: HTMLElement = fixture.nativeElement;
    const hero = el.querySelector('[data-testid="collection-hero"]');
    expect(hero?.querySelector('h1')?.textContent?.trim()).toBe('Eid Edit');
    expect(hero?.textContent).toContain('Festive pieces for Eid.');
    expect(el.querySelector('[data-testid="collection-count"]')?.textContent).toContain('2');
    // No image → brand-gradient banner.
    expect(hero?.classList.contains('collection-hero--no-image')).toBe(true);
  });

  it('scopes the catalog listing + facets to the collection slug, with URL filters', () => {
    const { catalog } = setup({ query: { sizes: 'M,L', sort: 'price_asc', min_price: '100' } });
    expect(catalog.loadCalls).toHaveLength(1);
    expect(catalog.loadCalls[0]).toEqual({
      filters: {
        collection: 'eid-edit',
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
    expect(catalog.facetCalls[0].collection).toBe('eid-edit');
  });

  it('shows the embedded products first, then the catalog results once loaded', async () => {
    const { fixture } = setup({
      catalogItems: [makeProduct({ id: 9, slug: 'live-9' })],
    });
    // Before the catalog promise settles the embedded products fill the grid.
    expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([1, 2]);
    await fixture.whenStable();
    fixture.detectChanges();
    expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([9]);
    const grid = fixture.nativeElement.querySelector('[data-testid="collection-grid"]');
    expect(grid.querySelectorAll('ui-product-card').length).toBe(1);
  });

  it('shows the empty state when the filters match nothing (not the embedded products)', async () => {
    const { fixture } = setup({ query: { colors: 'gold' }, catalogItems: [] });
    await fixture.whenStable();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[data-testid="collection-empty"]')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid="collection-grid"]')).toBeNull();
  });

  it('appends the next page on "load more"', async () => {
    const { fixture, catalog } = setup({ catalogItems: [makeProduct({ id: 9 })] });
    await fixture.whenStable();
    catalog.hasMore.set(true);
    fixture.detectChanges();
    const btn = fixture.nativeElement.querySelector('[data-testid="collection-load-more"]') as HTMLButtonElement;
    expect(btn).not.toBeNull();
    btn.click();
    expect(catalog.loadCalls).toHaveLength(2);
    expect(catalog.loadCalls[1].page).toBe(1);
    expect(catalog.loadCalls[1].append).toBe(true);
    expect(catalog.loadCalls[1].filters.collection).toBe('eid-edit');
  });

  it('writes filter changes to the URL query string', () => {
    const { fixture } = setup();
    const router = TestBed.inject(Router);
    const nav = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    fixture.componentInstance.onFilterChange({
      collection: 'eid-edit', sizes: ['S'], colors: [], sort: 'price_desc', minPrice: null, maxPrice: 300,
    });
    expect(nav).toHaveBeenCalledTimes(1);
    const extras = nav.mock.calls[0][1] as { queryParams: Record<string, string> };
    expect(extras.queryParams).toEqual({ sizes: 'S', sort: 'price_desc', max_price: '300' });
  });

  it('sets the SEO title from the collection', () => {
    const { seo } = setup();
    expect(seo.set).toHaveBeenCalled();
    const meta = seo.set.mock.calls.at(-1)?.[0] as { title: string; url: string };
    expect(meta.title).toContain('Eid Edit');
    expect(meta.url).toMatch(/\/collection\/eid-edit$/);
  });

  it('renders the not-found state for an unknown slug (404)', () => {
    const { fixture } = setup({ detail: '404' });
    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('[data-testid="collection-not-found"]')).not.toBeNull();
    expect(el.querySelector('[data-testid="collection-hero"]')).toBeNull();
  });

  it('renders an error state (not "not found") for other failures', () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const { fixture } = setup({ detail: '500' });
    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('[data-testid="collection-load-error"]')).not.toBeNull();
    expect(el.querySelector('[data-testid="collection-not-found"]')).toBeNull();
  });

  it('does not request the listing or facets when the collection fetch fails (5xx or 404)', () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const failed = setup({ detail: '500' });
    expect(failed.catalog.loadCalls).toHaveLength(0);
    expect(failed.catalog.facetCalls).toHaveLength(0);
    TestBed.resetTestingModule();

    const missing = setup({ detail: '404' });
    expect(missing.catalog.loadCalls).toHaveLength(0);
    expect(missing.catalog.facetCalls).toHaveLength(0);
  });

  it('fully resets the shared catalog (incl. facets) on entering the collection', () => {
    const { catalog } = setup();
    expect(catalog.resetCalls).toBe(1);
    expect(catalog.resetProductsCalls).toBe(0);
  });

  describe('listing failure', () => {
    it('shows a distinct "couldn\'t load" error (not the no-match state) when a filtered listing fails', async () => {
      const { fixture, catalog } = setup({ query: { colors: 'gold' }, failLoads: true });
      await fixture.whenStable();
      fixture.detectChanges();
      const el: HTMLElement = fixture.nativeElement;
      const error = el.querySelector('[data-testid="collection-list-error"]');
      expect(error).not.toBeNull();
      expect(error?.getAttribute('role')).toBe('alert');
      expect(el.querySelector('[data-testid="collection-list-retry"]')).not.toBeNull();
      expect(el.querySelector('[data-testid="collection-empty"]')).toBeNull();
      expect(el.querySelector('[data-testid="collection-grid"]')).toBeNull();

      // Retry re-runs the listing for the same slug + filters.
      catalog.failLoads = false;
      catalog.nextItems = [makeProduct({ id: 9, slug: 'gold-9' })];
      catalog.nextTotal = 1;
      (el.querySelector('[data-testid="collection-list-retry"]') as HTMLButtonElement).click();
      fixture.detectChanges(); // the retry re-runs the catalog effect
      expect(catalog.loadCalls).toHaveLength(2);
      expect(catalog.loadCalls[1]).toMatchObject({ page: 0, append: false });
      expect(catalog.loadCalls[1].filters.colors).toEqual(['gold']);
      expect(catalog.resetProductsCalls).toBe(1); // same collection → facets kept
      await fixture.whenStable();
      fixture.detectChanges();
      expect(el.querySelector('[data-testid="collection-list-error"]')).toBeNull();
      expect(el.querySelectorAll('[data-testid="collection-grid"] ui-product-card').length).toBe(1);
    });

    it('keeps the embedded products for the unfiltered view and offers a retry when more exist', async () => {
      const { fixture } = setup({
        failLoads: true,
        collection: makeCollection({ product_count: 30 }),
      });
      await fixture.whenStable();
      fixture.detectChanges();
      const el: HTMLElement = fixture.nativeElement;
      expect(el.querySelectorAll('[data-testid="collection-grid"] ui-product-card').length).toBe(2);
      expect(el.querySelector('[data-testid="collection-list-error"]')).toBeNull();
      expect(el.querySelector('[data-testid="collection-fallback-retry"]')).not.toBeNull();
    });
  });

  describe('"load more" failure', () => {
    it('rolls the page back, does not reject, shows an inline retry that re-requests the same page', async () => {
      const { fixture, catalog } = setup({ catalogItems: [makeProduct({ id: 9 })] });
      await fixture.whenStable();
      catalog.hasMore.set(true);
      fixture.detectChanges();
      const cmp = fixture.componentInstance;
      const el: HTMLElement = fixture.nativeElement;

      catalog.failLoads = true;
      await expect(cmp.loadMore()).resolves.toBeUndefined();
      expect(catalog.loadCalls.at(-1)).toMatchObject({ page: 1, append: true });
      expect(cmp.page()).toBe(0); // rolled back, not left at 1
      expect(cmp.loadMoreFailed()).toBe(true);
      fixture.detectChanges();

      const error = el.querySelector('[data-testid="collection-load-more-error"]');
      expect(error).not.toBeNull();
      expect(el.querySelector('[data-testid="collection-load-more-retry"]')).not.toBeNull();
      expect(el.querySelector('[data-testid="collection-load-more"]')).toBeNull();

      // Retry asks for page 1 again (no page skipped) and clears the error.
      catalog.failLoads = false;
      catalog.nextItems = [makeProduct({ id: 10, slug: 'abaya-10' })];
      (el.querySelector('[data-testid="collection-load-more-retry"]') as HTMLButtonElement).click();
      await fixture.whenStable();
      fixture.detectChanges();
      expect(catalog.loadCalls.at(-1)).toMatchObject({ page: 1, append: true });
      expect(cmp.page()).toBe(1);
      expect(cmp.loadMoreFailed()).toBe(false);
      expect(el.querySelector('[data-testid="collection-load-more-error"]')).toBeNull();
      expect(cmp.products().map((p) => p.id)).toEqual([9, 10]);
    });
  });

  it('renders an image banner as a blurred backdrop plus the uncropped photo', () => {
    const { fixture } = setup({
      collection: makeCollection({ image_url: 'https://api-v3.3bayti.ae/uploads/products/a.jpg' }),
    });
    const hero = fixture.nativeElement.querySelector('[data-testid="collection-hero"]') as HTMLElement;
    expect(hero.classList.contains('collection-hero--with-image')).toBe(true);
    const backdrop = hero.querySelector('[data-testid="collection-hero-backdrop"]') as HTMLImageElement;
    const photo = hero.querySelector('[data-testid="collection-hero-photo"]') as HTMLImageElement;
    expect(backdrop).not.toBeNull();
    expect(photo).not.toBeNull();
    // Same transformed URL for both, so the browser fetches it once.
    expect(backdrop.getAttribute('src')).toBe(photo.getAttribute('src'));
    expect(backdrop.getAttribute('aria-hidden')).toBe('true');
  });
});
