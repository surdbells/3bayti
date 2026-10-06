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

  loadCalls: Array<{ filters: CatalogFilters; page: number; append: boolean }> = [];
  facetCalls: CatalogFilters[] = [];

  reset(): void {
    this.products.set([]);
    this.total.set(0);
    this.hasMore.set(false);
  }

  async loadProducts(filters: CatalogFilters, page = 0, append = false) {
    this.loadCalls.push({ filters, page, append });
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
});
