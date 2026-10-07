import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, Router, convertToParamMap, provideRouter } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { Subject, of, throwError } from 'rxjs';
import { TranslateService, provideTranslateService } from '@ngx-translate/core';

import { CollectionDetailComponent } from './collection-detail';
import type { CollectionDetail } from './collection.model';
import { CatalogService, type CatalogFilters, type Facets } from '../categories/catalog.service';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { SeoService } from '../../core/seo/seo.service';
import type { Product } from '../catalog/product.model';
import en from '../../../../public/i18n/en.json';

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
  query?: Record<string, string>;
  detail?: 'ok' | '404' | '500';
  collection?: CollectionDetail;
  catalogItems?: Product[];
  /** Make the catalog listing (GET /products) fail from the first call. */
  failLoads?: boolean;
  /** Keep GET /collections/:slug pending until the test settles header.pending. */
  deferHeader?: boolean;
  /** Keep catalog loads pending until catalog.releaseLoads(). */
  holdLoads?: boolean;
}

/** The GET /collections/:slug envelope for a collection. */
function envelope(col: CollectionDetail) {
  return { data: col, meta: { total_products: col.product_count, page_size: 20 } };
}

/** Test-side control of GET /collections/:slug. */
interface HeaderControl {
  /** Fail every request; change it to let a later Retry succeed. */
  fail: '404' | '500' | undefined;
  /** When set, requests stay pending on this subject until the test settles it. */
  pending: Subject<unknown> | null;
}

function setup(opts: SetupOpts = {}): {
  fixture: ComponentFixture<CollectionDetailComponent>;
  catalog: StubCatalogService;
  get: ReturnType<typeof vi.fn>;
  seo: { set: ReturnType<typeof vi.fn>; setStructuredData: ReturnType<typeof vi.fn> };
  header: HeaderControl;
} {
  const catalog = new StubCatalogService();
  catalog.nextItems = opts.catalogItems ?? [];
  catalog.nextTotal = catalog.nextItems.length;
  catalog.failLoads = opts.failLoads ?? false;
  catalog.holdLoads = opts.holdLoads ?? false;
  const col = opts.collection ?? makeCollection();
  const header: HeaderControl = {
    fail: opts.detail === 'ok' ? undefined : opts.detail,
    pending: opts.deferHeader ? new Subject<unknown>() : null,
  };
  const get = vi.fn(() => {
    if (header.pending) return header.pending.asObservable();
    if (header.fail === '404') return throwError(() => new HttpErrorResponse({ status: 404 }));
    if (header.fail === '500') return throwError(() => new HttpErrorResponse({ status: 500 }));
    return of(envelope(col));
  });
  const seo = { set: vi.fn(), setStructuredData: vi.fn() };

  TestBed.configureTestingModule({
    imports: [CollectionDetailComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideTranslateService({ fallbackLang: 'en', lang: 'en' }),
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
  // Real English strings, so labels / accessible names can be asserted.
  TestBed.inject(TranslateService).setTranslation('en', en);

  const fixture = TestBed.createComponent(CollectionDetailComponent);
  fixture.detectChanges();
  return { fixture, catalog, get, seo, header };
}

const q = (fixture: ComponentFixture<unknown>, testId: string): HTMLElement | null =>
  (fixture.nativeElement as HTMLElement).querySelector(`[data-testid="${testId}"]`);

/** Let released (held) loads, and the callbacks chained on them, settle. */
const settle = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

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

  it('renders no listing products when the collection fetch fails (5xx or 404), and ignores listing results that land late', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    for (const status of [500, 404] as const) {
      TestBed.resetTestingModule();
      // The listing + facets are requested in parallel with the header, so
      // they can already be in flight when the header fails.
      const { fixture, catalog, header } = setup({
        deferHeader: true,
        holdLoads: true,
        catalogItems: [makeProduct({ id: 9, slug: 'late-9' })],
      });
      // Exactly one listing (page 0) and one facets request, for the route
      // slug, are already in flight while the header is still pending.
      expect(catalog.loadCalls).toHaveLength(1);
      expect(catalog.loadCalls[0]).toMatchObject({ page: 0, append: false, filters: { collection: 'eid-edit' } });
      expect(catalog.facetCalls).toHaveLength(1);
      expect(catalog.facetCalls[0].collection).toBe('eid-edit');

      header.pending!.error(new HttpErrorResponse({ status }));
      fixture.detectChanges();
      expect(q(fixture, status === 404 ? 'collection-not-found' : 'collection-load-error')).not.toBeNull();
      expect(q(fixture, 'collection-grid')).toBeNull();
      expect(fixture.componentInstance.products()).toEqual([]);
      // The failed header issues no further listing / facets requests.
      expect(catalog.loadCalls).toHaveLength(1);
      expect(catalog.facetCalls).toHaveLength(1);

      // The in-flight listing answers after the failure: it is discarded.
      catalog.releaseLoads();
      await settle();
      await fixture.whenStable();
      fixture.detectChanges();
      expect(catalog.loadCalls).toHaveLength(1);
      expect(catalog.facetCalls).toHaveLength(1);
      expect(q(fixture, status === 404 ? 'collection-not-found' : 'collection-load-error')).not.toBeNull();
      expect(q(fixture, 'collection-grid')).toBeNull();
      expect(fixture.nativeElement.querySelectorAll('ui-product-card')).toHaveLength(0);
      expect(fixture.componentInstance.products()).toEqual([]);
    }
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
      const retry = el.querySelector('[data-testid="collection-list-retry"]') as HTMLButtonElement;
      retry.focus();
      retry.click();
      // The Retry button gives way to the shimmer: focus moves to the stable
      // grid region instead of dropping to <body>.
      const region = el.querySelector('[data-testid="collection-grid-region"]');
      expect(region?.getAttribute('tabindex')).toBe('-1');
      expect(document.activeElement).toBe(region);
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

    // Same pattern as the category page: never natively [disabled] (which
    // would drop keyboard focus), one stable "Retry" label, and focus moved
    // to the grid region once the button gives way.
    it('keeps the list Retry enabled with a stable "Retry" label (category pattern)', async () => {
      const { fixture, catalog } = setup({ query: { colors: 'gold' }, failLoads: true });
      await fixture.whenStable();
      fixture.detectChanges();
      const retry = q(fixture, 'collection-list-retry') as HTMLButtonElement;
      expect(retry.textContent?.trim()).toBe('Retry');

      catalog.isLoadingList.set(true);
      fixture.detectChanges();
      expect(q(fixture, 'collection-list-retry')).toBe(retry);
      expect(retry.disabled).toBe(false);
      expect(retry.hasAttribute('disabled')).toBe(false);
      expect(retry.textContent?.trim()).toBe('Retry');
    });

    it('keeps the fallback Retry enabled with a stable label, and moves focus to the grid region on click', async () => {
      const { fixture, catalog } = setup({
        failLoads: true,
        collection: makeCollection({ product_count: 30 }),
      });
      await fixture.whenStable();
      fixture.detectChanges();
      const retry = q(fixture, 'collection-fallback-retry') as HTMLButtonElement;
      expect(retry.textContent?.trim()).toBe('Retry');

      catalog.isLoadingList.set(true);
      fixture.detectChanges();
      expect(q(fixture, 'collection-fallback-retry')).toBe(retry);
      expect(retry.disabled).toBe(false);
      expect(retry.hasAttribute('disabled')).toBe(false);
      expect(retry.textContent?.trim()).toBe('Retry');
      catalog.isLoadingList.set(false);
      fixture.detectChanges();

      catalog.failLoads = false;
      catalog.nextItems = [makeProduct({ id: 9, slug: 'live-9' })];
      catalog.nextTotal = 30;
      retry.focus();
      retry.click();
      expect(document.activeElement).toBe(q(fixture, 'collection-grid-region'));
      fixture.detectChanges();
      expect(catalog.loadCalls).toHaveLength(2);
      expect(catalog.loadCalls[1]).toMatchObject({ page: 0, append: false });
      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'collection-fallback-retry')).toBeNull();
      expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([9]);
      expect(document.activeElement).toBe(q(fixture, 'collection-grid-region'));
    });
  });

  describe('header failure Retry', () => {
    it('re-requests the header + listing for the same slug and filters, keeping focus on the page', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => undefined);
      const { fixture, catalog, get, header } = setup({
        detail: '500',
        query: { colors: 'black' },
        catalogItems: [makeProduct({ id: 9, slug: 'live-9' })],
      });
      expect(q(fixture, 'collection-load-error')?.querySelector('[role="alert"]')?.textContent)
        .toContain(en.collections.loadError.body);
      const retry = q(fixture, 'collection-load-error-retry') as HTMLButtonElement;
      expect(retry.textContent?.trim()).toBe('Retry');
      expect(retry.getAttribute('aria-label')).toBe('Retry loading this collection');
      expect(catalog.loadCalls).toHaveLength(0);

      header.fail = undefined; // the API has recovered
      retry.focus();
      retry.click();
      expect(get).toHaveBeenCalledTimes(2);
      expect(get).toHaveBeenLastCalledWith('GET /collections/:slug', { params: { slug: 'eid-edit' } });
      const body = q(fixture, 'collection-page-body');
      expect(body?.getAttribute('tabindex')).toBe('-1');
      expect(document.activeElement).toBe(body);

      fixture.detectChanges();
      expect(q(fixture, 'collection-load-error')).toBeNull();
      expect(q(fixture, 'collection-hero')?.querySelector('h1')?.textContent?.trim()).toBe('Eid Edit');
      expect(catalog.loadCalls).toHaveLength(1);
      expect(catalog.loadCalls[0].filters).toMatchObject({ collection: 'eid-edit', colors: ['black'] });
      expect(catalog.facetCalls).toHaveLength(1);
      await fixture.whenStable();
      fixture.detectChanges();
      expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([9]);
      expect(document.activeElement).toBe(q(fixture, 'collection-page-body'));
    });

    it('keeps the load-error state (and focus on the page body) when the Retry fails again', () => {
      vi.spyOn(console, 'error').mockImplementation(() => undefined);
      const { fixture, catalog, get } = setup({ detail: '500' });
      const retry = q(fixture, 'collection-load-error-retry') as HTMLButtonElement;
      retry.focus();
      retry.click();
      fixture.detectChanges();
      expect(get).toHaveBeenCalledTimes(2);
      expect(q(fixture, 'collection-load-error-retry')).not.toBeNull();
      expect(document.activeElement).toBe(q(fixture, 'collection-page-body'));
      expect(catalog.loadCalls).toHaveLength(0);
      // The repeated failure is announced (the content under focus did not change).
      expect(q(fixture, 'collection-retry-status')?.textContent?.trim()).toBe(en.collections.loadError.title);
    });

    it('focuses a named region on Retry and announces the restored collection', () => {
      vi.spyOn(console, 'error').mockImplementation(() => undefined);
      const { fixture, header } = setup({ detail: '500' });
      const body = q(fixture, 'collection-page-body')!;
      expect(body.getAttribute('role')).toBe('region');
      expect(body.getAttribute('aria-label')).toBe(en.collections.pageBodyAria);
      const status = q(fixture, 'collection-retry-status')!;
      expect(status.getAttribute('role')).toBe('status');
      expect(status.textContent?.trim()).toBe(''); // nothing to announce before a Retry

      header.fail = undefined;
      header.pending = new Subject<unknown>(); // the retried header is slow
      (q(fixture, 'collection-load-error-retry') as HTMLButtonElement).click();
      fixture.detectChanges();
      expect(document.activeElement).toBe(body);
      expect(body.getAttribute('aria-busy')).toBe('true');
      expect(status.textContent?.trim()).toBe(en.collections.loading);

      header.pending.next(envelope(makeCollection()));
      fixture.detectChanges();
      expect(body.getAttribute('aria-busy')).toBeNull();
      expect(status.textContent?.trim()).toBe('Eid Edit loaded.');
    });

    it('offers no Retry for an unknown collection (404)', () => {
      const { fixture } = setup({ detail: '404' });
      expect(q(fixture, 'collection-not-found')).not.toBeNull();
      expect(q(fixture, 'collection-load-error-retry')).toBeNull();
    });
  });

  describe('listing in parallel with the header', () => {
    it('requests the listing + facets before the header resolves, and renders them once it lands', async () => {
      const { fixture, catalog, header } = setup({
        deferHeader: true,
        query: { colors: 'black' },
        catalogItems: [makeProduct({ id: 9, slug: 'live-9' })],
      });
      expect(q(fixture, 'collection-hero')).toBeNull();
      expect(catalog.loadCalls).toHaveLength(1);
      expect(catalog.loadCalls[0].filters).toMatchObject({ collection: 'eid-edit', colors: ['black'] });
      expect(catalog.facetCalls).toHaveLength(1);
      expect(catalog.facetCalls[0].collection).toBe('eid-edit');

      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'collection-grid')).toBeNull(); // no header yet, no grid

      header.pending!.next(envelope(makeCollection()));
      fixture.detectChanges();
      expect(q(fixture, 'collection-hero')).not.toBeNull();
      expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([9]);
      expect(catalog.loadCalls).toHaveLength(1);
      expect(catalog.facetCalls).toHaveLength(1);
    });

    it('unfiltered: the listing landing first renders it directly (no embedded flash)', async () => {
      const { fixture, header } = setup({
        deferHeader: true,
        catalogItems: [makeProduct({ id: 9, slug: 'live-9' })],
      });
      await settle();
      await fixture.whenStable();
      header.pending!.next(envelope(makeCollection()));
      fixture.detectChanges();
      expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([9]);
      expect(fixture.nativeElement.querySelectorAll('[data-testid="collection-grid"] ui-product-card').length).toBe(1);
    });

    it('unfiltered: the header landing first shows its embedded products until the listing is ready', async () => {
      const { fixture, catalog, header } = setup({
        deferHeader: true,
        holdLoads: true,
        catalogItems: [makeProduct({ id: 9, slug: 'live-9' })],
      });
      header.pending!.next(envelope(makeCollection()));
      fixture.detectChanges();
      expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([1, 2]);
      expect(fixture.nativeElement.querySelector('.grid-loading')).toBeNull();

      catalog.releaseLoads();
      await settle();
      await fixture.whenStable();
      fixture.detectChanges();
      expect(fixture.componentInstance.products().map((p) => p.id)).toEqual([9]);
      expect(catalog.loadCalls).toHaveLength(1);
    });

    it('discards the listing when the header then 404s, and ignores it answering late', async () => {
      const { fixture, catalog, header } = setup({
        deferHeader: true,
        holdLoads: true,
        catalogItems: [makeProduct({ id: 9, slug: 'live-9' })],
      });
      expect(catalog.loadCalls).toHaveLength(1);
      expect(catalog.resetCalls).toBe(1);

      header.pending!.error(new HttpErrorResponse({ status: 404 }));
      fixture.detectChanges();
      expect(q(fixture, 'collection-not-found')).not.toBeNull();
      expect(catalog.resetCalls).toBe(2); // listing + facets dropped

      catalog.releaseLoads();
      await settle();
      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'collection-not-found')).not.toBeNull();
      expect(q(fixture, 'collection-grid')).toBeNull();
      expect(fixture.componentInstance.products()).toEqual([]);
      expect(catalog.loadCalls).toHaveLength(1);
    });
  });

  describe('"load more" focus when the LAST page loads (the button goes away)', () => {
    async function onLastPage() {
      const ctx = setup({ catalogItems: [makeProduct({ id: 9, slug: 'live-9' })] });
      await ctx.fixture.whenStable();
      ctx.catalog.hasMore.set(true);
      ctx.fixture.detectChanges();
      ctx.catalog.nextItems = [
        makeProduct({ id: 10, slug: 'live-10' }),
        makeProduct({ id: 11, slug: 'live-11' }),
      ];
      ctx.catalog.nextHasMore = false;
      return { ...ctx, btn: q(ctx.fixture, 'collection-load-more') as HTMLButtonElement };
    }

    it('moves focus to the first appended product link', async () => {
      const { fixture, btn } = await onLastPage();
      btn.focus();
      btn.click();
      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'collection-load-more')).toBeNull();
      const cards = fixture.nativeElement.querySelectorAll('[data-testid="collection-grid"] ui-product-card');
      expect(cards.length).toBe(3);
      expect(document.activeElement).toBe(cards[1].querySelector('a'));
      expect(document.activeElement?.getAttribute('href')).toContain('live-10');
    });

    it('falls back to the grid region when the last page brought no product link', async () => {
      const { fixture, catalog, btn } = await onLastPage();
      catalog.nextItems = [];
      btn.focus();
      btn.click();
      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'collection-load-more')).toBeNull();
      expect(document.activeElement).toBe(q(fixture, 'collection-grid-region'));
    });

    it('does not move focus when the shopper was not on the button', async () => {
      const { fixture, btn } = await onLastPage();
      const crumb = fixture.nativeElement.querySelector('.breadcrumb-link') as HTMLAnchorElement;
      crumb.focus();
      btn.click();
      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'collection-load-more')).toBeNull();
      expect(document.activeElement).toBe(crumb);
    });

    it('keeps focus on the button while more pages remain', async () => {
      const { fixture, catalog, btn } = await onLastPage();
      catalog.nextHasMore = true;
      btn.focus();
      btn.click();
      await fixture.whenStable();
      fixture.detectChanges();
      expect(q(fixture, 'collection-load-more')).toBe(btn);
      expect(document.activeElement).toBe(btn);
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

    it('keeps keyboard focus on the one load-more button across a failure and its retry', async () => {
      const { fixture, catalog } = setup({ catalogItems: [makeProduct({ id: 9 })] });
      await fixture.whenStable();
      catalog.hasMore.set(true);
      fixture.detectChanges();
      const el: HTMLElement = fixture.nativeElement;
      const btn = el.querySelector('[data-testid="collection-load-more"]') as HTMLButtonElement;
      expect(btn.getAttribute('aria-label')).not.toBeNull();
      btn.focus();

      catalog.failLoads = true;
      btn.click();
      await fixture.whenStable();
      fixture.detectChanges();
      // Same element (only its label / test id changed), still focused.
      expect(el.querySelector('[data-testid="collection-load-more-retry"]')).toBe(btn);
      expect(document.activeElement).toBe(btn);

      // While the retry loads it is aria-disabled, never natively disabled
      // (that would drop focus), and has no aria-label (name = "Loading…").
      catalog.isLoadingList.set(true);
      fixture.detectChanges();
      expect(btn.getAttribute('aria-disabled')).toBe('true');
      expect(btn.disabled).toBe(false);
      expect(btn.hasAttribute('aria-label')).toBe(false);
      catalog.isLoadingList.set(false);
      fixture.detectChanges();

      catalog.failLoads = false;
      catalog.nextItems = [makeProduct({ id: 10, slug: 'abaya-10' })];
      btn.click();
      await fixture.whenStable();
      fixture.detectChanges();
      expect(el.querySelector('[data-testid="collection-load-more"]')).toBe(btn);
      expect(document.activeElement).toBe(btn);
      expect(btn.hasAttribute('aria-disabled')).toBe(false);
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
    expect(backdrop.getAttribute('alt')).toBe('');
    // The backdrop comes first in the DOM and issues that shared request, so
    // it carries the high priority too (else the photo's hint is lost).
    expect(backdrop.getAttribute('fetchpriority')).toBe('high');
    expect(photo.getAttribute('fetchpriority')).toBe('high');
  });
});
