import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { StoreDetailPageComponent } from './store-detail-page';
import { StoreService } from '../catalog/store.service';
import type { StoreProductsPage } from '../catalog/store.service';
import { provideI18n } from '../../core/i18n';
import type { Store, VendorLabel } from '../catalog/store.model';
import type { Product } from '../catalog/product.model';

function makeStore(o: Partial<Store> = {}): Store {
  return {
    id: 1, slug: 'acme', name: 'Acme Couture', description: null,
    logo_url: 'https://img/logo.png', cover_image_url: 'https://img/cover.png',
    is_verified: false, ...o,
  };
}

function makeProduct(o: Partial<Product> = {}): Product {
  return {
    id: 1, slug: 'p1', name: 'Abaya',
    price: { amount: '299.00', currency: 'AED' },
    sale_price: null, primary_image: null, ...o,
  } as Product;
}

function makeLabel(o: Partial<VendorLabel> = {}): VendorLabel {
  return { id: 1, slug: 'eid', name: 'Eid Collection', display_order: 1, count: 3, ...o };
}

class StubStoreService {
  isLoadingList = signal(false).asReadonly();
  directory = signal<Store[]>([]).asReadonly();
  hasMore = signal(false).asReadonly();

  getBySlugCalls: string[] = [];
  listProductsCalls: Array<{ slug: string; offset: number }> = [];
  /** Label slug passed to each listProducts call (undefined = All). */
  productLabelCalls: Array<string | undefined> = [];
  listLabelsCalls: string[] = [];
  storeResult: Store = makeStore();
  productPages: StoreProductsPage[] = [{ items: [], hasMore: false }];
  labelResult: VendorLabel[] = [];
  private pageIdx = 0;
  getThrows = false;
  listThrows = false;

  async getBySlug(slug: string): Promise<Store> {
    this.getBySlugCalls.push(slug);
    if (this.getThrows) throw new Error('not found');
    return this.storeResult;
  }
  async listProducts(
    slug: string,
    params: { limit?: number; offset?: number; label?: string } = {},
  ): Promise<StoreProductsPage> {
    this.listProductsCalls.push({ slug, offset: params.offset ?? 0 });
    this.productLabelCalls.push(params.label);
    if (this.listThrows) throw new Error('products failed');
    const page = this.productPages[Math.min(this.pageIdx, this.productPages.length - 1)];
    this.pageIdx++;
    return page;
  }
  async listLabels(slug: string): Promise<VendorLabel[]> {
    this.listLabelsCalls.push(slug);
    return this.labelResult;
  }
}

function setup(opts: {
  slug?: string | null;
  store?: Store;
  productPages?: StoreProductsPage[];
  labels?: VendorLabel[];
  getThrows?: boolean;
  listThrows?: boolean;
} = {}): {
  fixture: ComponentFixture<StoreDetailPageComponent>;
  service: StubStoreService;
} {
  const service = new StubStoreService();
  if (opts.store !== undefined) service.storeResult = opts.store;
  if (opts.productPages !== undefined) service.productPages = opts.productPages;
  if (opts.labels !== undefined) service.labelResult = opts.labels;
  if (opts.getThrows === true) service.getThrows = true;
  if (opts.listThrows === true) service.listThrows = true;

  const slug = opts.slug !== undefined ? opts.slug : 'acme';
  const activatedRouteStub = {
    snapshot: {
      paramMap: {
        get: (key: string): string | null =>
          slug !== null && key === 'slug' ? slug : null,
      },
    },
  };

  TestBed.configureTestingModule({
    imports: [StoreDetailPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: StoreService, useValue: service },
      { provide: ActivatedRoute, useValue: activatedRouteStub },
    ],
  });
  const fixture = TestBed.createComponent(StoreDetailPageComponent);
  fixture.detectChanges();
  return { fixture, service };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

describe('StoreDetailPageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => {
        if (!req.cancelled) req.flush({});
      });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('loading the store', () => {
    it('fetches the store by slug from the route', async () => {
      const { service } = setup({ slug: 'acme-couture' });
      await flush();
      expect(service.getBySlugCalls).toEqual(['acme-couture']);
    });

    it('renders the store name + verified badge', async () => {
      const { fixture } = setup({
        store: makeStore({ name: 'Maison Noor', is_verified: true }),
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.textContent).toContain('Maison Noor');
      expect(fixture.nativeElement.querySelector('.store-detail__verified')).not.toBeNull();
    });

    it('omits the verified badge when not verified', async () => {
      const { fixture } = setup({ store: makeStore({ is_verified: false }) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('.store-detail__verified')).toBeNull();
    });

    it('renders the description via innerHTML', async () => {
      const { fixture } = setup({
        store: makeStore({ description: 'Fine <em>kaftans</em> since 1998' }),
      });
      await flush();
      fixture.detectChanges();
      const desc = fixture.nativeElement.querySelector('[data-testid="store-description"]');
      expect(desc?.querySelector('em')).not.toBeNull();
      expect(desc?.textContent).toContain('kaftans');
    });

    it('fetches the first product page after the store loads', async () => {
      const { service } = setup({
        productPages: [{ items: [makeProduct()], hasMore: false }],
      });
      await flush();
      expect(service.listProductsCalls).toEqual([{ slug: 'acme', offset: 0 }]);
    });
  });

  describe('product grid', () => {
    it('renders one product card per product', async () => {
      const { fixture } = setup({
        productPages: [{ items: [makeProduct({ id: 1 }), makeProduct({ id: 2, slug: 'p2' })], hasMore: false }],
      });
      await flush();
      fixture.detectChanges();
      const grid = fixture.nativeElement.querySelector('[data-testid="store-product-grid"]');
      expect(grid).not.toBeNull();
      expect(grid.querySelectorAll('ui-product-card')).toHaveLength(2);
    });

    it('shows the empty-products state when the store has no products', async () => {
      const { fixture } = setup({ productPages: [{ items: [], hasMore: false }] });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="store-products-empty"]')).not.toBeNull();
    });
  });

  describe('product load-more', () => {
    it('hides the button when hasMore is false', async () => {
      const { fixture } = setup({
        productPages: [{ items: [makeProduct()], hasMore: false }],
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="store-products-load-more"]')).toBeNull();
    });

    it('shows the button when hasMore and appends the next page on click', async () => {
      const { fixture, service } = setup({
        productPages: [
          { items: [makeProduct({ id: 1 })], hasMore: true },
          { items: [makeProduct({ id: 2, slug: 'p2' })], hasMore: false },
        ],
      });
      await flush();
      fixture.detectChanges();
      const btn = fixture.nativeElement.querySelector('[data-testid="store-products-load-more"]') as HTMLButtonElement;
      expect(btn).not.toBeNull();
      btn.click();
      await flush();
      fixture.detectChanges();
      /* Second call used offset = first page length (1). */
      expect(service.listProductsCalls).toEqual([
        { slug: 'acme', offset: 0 },
        { slug: 'acme', offset: 1 },
      ]);
      expect(fixture.nativeElement.querySelectorAll('ui-product-card')).toHaveLength(2);
      /* Button gone now that hasMore is false. */
      expect(fixture.nativeElement.querySelector('[data-testid="store-products-load-more"]')).toBeNull();
    });
  });

  describe('collection labels (chips)', () => {
    it('omits the chip row when the store has no labels', async () => {
      const { fixture } = setup({ productPages: [{ items: [makeProduct()], hasMore: false }] });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="store-labels"]')).toBeNull();
    });

    it('renders an "All" chip plus one chip per label with its count', async () => {
      const { fixture } = setup({
        productPages: [{ items: [makeProduct()], hasMore: false }],
        labels: [makeLabel({ id: 1, slug: 'eid', name: 'Eid', count: 4 }), makeLabel({ id: 2, slug: 'new-in', name: 'New In', count: 7 })],
      });
      await flush();
      fixture.detectChanges();
      const chips = fixture.nativeElement.querySelectorAll('.store-detail__label-chip');
      /* All + 2 labels. */
      expect(chips).toHaveLength(3);
      expect(fixture.nativeElement.textContent).toContain('Eid');
      expect(fixture.nativeElement.textContent).toContain('New In');
      /* Count badges rendered. */
      const badges = fixture.nativeElement.querySelectorAll('.store-detail__label-count');
      expect(badges).toHaveLength(2);
      expect(badges[0].textContent).toContain('4');
    });

    it('selecting a label refetches the grid filtered by that label slug', async () => {
      const { fixture, service } = setup({
        productPages: [{ items: [makeProduct()], hasMore: false }],
        labels: [makeLabel({ id: 1, slug: 'eid', name: 'Eid', count: 4 })],
      });
      await flush();
      fixture.detectChanges();
      /* Initial load was the unfiltered ("All") page. */
      expect(service.productLabelCalls).toEqual([undefined]);

      /* Click the label chip (index 1; index 0 is "All"). */
      const chips = fixture.nativeElement.querySelectorAll('.store-detail__label-chip') as NodeListOf<HTMLButtonElement>;
      chips[1].click();
      await flush();
      fixture.detectChanges();

      /* Refetched from offset 0 with the label filter. */
      expect(service.productLabelCalls).toEqual([undefined, 'eid']);
      expect(service.listProductsCalls[1]).toEqual({ slug: 'acme', offset: 0 });
      /* The clicked chip is now the active one. */
      const active = fixture.nativeElement.querySelector('.store-detail__label-chip--active');
      expect(active?.textContent).toContain('Eid');
    });
  });

  describe('not-found (Q4.4)', () => {
    it('shows the not-found state when getBySlug 404s', async () => {
      const { fixture, service } = setup({ getThrows: true });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="store-not-found"]')).not.toBeNull();
      /* Never attempted to load products for a missing store. */
      expect(service.listProductsCalls).toEqual([]);
    });

    it('shows the not-found state when slug is missing', async () => {
      const { fixture, service } = setup({ slug: null });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="store-not-found"]')).not.toBeNull();
      expect(service.getBySlugCalls).toEqual([]);
    });

    it('shows the not-found state when slug is blank', async () => {
      const { fixture, service } = setup({ slug: '   ' });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="store-not-found"]')).not.toBeNull();
      expect(service.getBySlugCalls).toEqual([]);
    });

    it('not-found state links back to /stores', async () => {
      const { fixture } = setup({ getThrows: true });
      await flush();
      fixture.detectChanges();
      const cta = fixture.nativeElement.querySelector('[data-testid="store-not-found"] a') as HTMLAnchorElement;
      expect(cta.getAttribute('href')).toBe('/stores');
    });
  });
});
