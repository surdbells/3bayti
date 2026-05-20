import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { DesignerDetailPageComponent } from './designer-detail-page';
import { DesignerService } from '../catalog/designer.service';
import type { DesignerProductsPage } from '../catalog/designer.service';
import { provideI18n } from '../../core/i18n';
import type { Designer } from '../catalog/designer.model';
import type { Product } from '../catalog/product.model';

function makeDesigner(o: Partial<Designer> = {}): Designer {
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

class StubDesignerService {
  isLoadingList = signal(false).asReadonly();
  directory = signal<Designer[]>([]).asReadonly();
  hasMore = signal(false).asReadonly();

  getBySlugCalls: string[] = [];
  listProductsCalls: Array<{ slug: string; offset: number }> = [];
  designerResult: Designer = makeDesigner();
  productPages: DesignerProductsPage[] = [{ items: [], hasMore: false }];
  private pageIdx = 0;
  getThrows = false;
  listThrows = false;

  async getBySlug(slug: string): Promise<Designer> {
    this.getBySlugCalls.push(slug);
    if (this.getThrows) throw new Error('not found');
    return this.designerResult;
  }
  async listProducts(slug: string, params: { limit?: number; offset?: number } = {}): Promise<DesignerProductsPage> {
    this.listProductsCalls.push({ slug, offset: params.offset ?? 0 });
    if (this.listThrows) throw new Error('products failed');
    const page = this.productPages[Math.min(this.pageIdx, this.productPages.length - 1)];
    this.pageIdx++;
    return page;
  }
}

function setup(opts: {
  slug?: string | null;
  designer?: Designer;
  productPages?: DesignerProductsPage[];
  getThrows?: boolean;
  listThrows?: boolean;
} = {}): {
  fixture: ComponentFixture<DesignerDetailPageComponent>;
  service: StubDesignerService;
} {
  const service = new StubDesignerService();
  if (opts.designer !== undefined) service.designerResult = opts.designer;
  if (opts.productPages !== undefined) service.productPages = opts.productPages;
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
    imports: [DesignerDetailPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: DesignerService, useValue: service },
      { provide: ActivatedRoute, useValue: activatedRouteStub },
    ],
  });
  const fixture = TestBed.createComponent(DesignerDetailPageComponent);
  fixture.detectChanges();
  return { fixture, service };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

describe('DesignerDetailPageComponent', () => {
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

  describe('loading the designer', () => {
    it('fetches the designer by slug from the route', async () => {
      const { service } = setup({ slug: 'acme-couture' });
      await flush();
      expect(service.getBySlugCalls).toEqual(['acme-couture']);
    });

    it('renders the designer name + verified badge', async () => {
      const { fixture } = setup({
        designer: makeDesigner({ name: 'Maison Noor', is_verified: true }),
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.textContent).toContain('Maison Noor');
      expect(fixture.nativeElement.querySelector('.designer-detail__verified')).not.toBeNull();
    });

    it('omits the verified badge when not verified', async () => {
      const { fixture } = setup({ designer: makeDesigner({ is_verified: false }) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('.designer-detail__verified')).toBeNull();
    });

    it('renders the description via innerHTML', async () => {
      const { fixture } = setup({
        designer: makeDesigner({ description: 'Fine <em>kaftans</em> since 1998' }),
      });
      await flush();
      fixture.detectChanges();
      const desc = fixture.nativeElement.querySelector('[data-testid="designer-description"]');
      expect(desc?.querySelector('em')).not.toBeNull();
      expect(desc?.textContent).toContain('kaftans');
    });

    it('fetches the first product page after the designer loads', async () => {
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
      const grid = fixture.nativeElement.querySelector('[data-testid="designer-product-grid"]');
      expect(grid).not.toBeNull();
      expect(grid.querySelectorAll('ui-product-card')).toHaveLength(2);
    });

    it('shows the empty-products state when the designer has no products', async () => {
      const { fixture } = setup({ productPages: [{ items: [], hasMore: false }] });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="designer-products-empty"]')).not.toBeNull();
    });
  });

  describe('product load-more', () => {
    it('hides the button when hasMore is false', async () => {
      const { fixture } = setup({
        productPages: [{ items: [makeProduct()], hasMore: false }],
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="designer-products-load-more"]')).toBeNull();
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
      const btn = fixture.nativeElement.querySelector('[data-testid="designer-products-load-more"]') as HTMLButtonElement;
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
      expect(fixture.nativeElement.querySelector('[data-testid="designer-products-load-more"]')).toBeNull();
    });
  });

  describe('not-found (Q4.4)', () => {
    it('shows the not-found state when getBySlug 404s', async () => {
      const { fixture, service } = setup({ getThrows: true });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="designer-not-found"]')).not.toBeNull();
      /* Never attempted to load products for a missing designer. */
      expect(service.listProductsCalls).toEqual([]);
    });

    it('shows the not-found state when slug is missing', async () => {
      const { fixture, service } = setup({ slug: null });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="designer-not-found"]')).not.toBeNull();
      expect(service.getBySlugCalls).toEqual([]);
    });

    it('shows the not-found state when slug is blank', async () => {
      const { fixture, service } = setup({ slug: '   ' });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="designer-not-found"]')).not.toBeNull();
      expect(service.getBySlugCalls).toEqual([]);
    });

    it('not-found state links back to /designer', async () => {
      const { fixture } = setup({ getThrows: true });
      await flush();
      fixture.detectChanges();
      const cta = fixture.nativeElement.querySelector('[data-testid="designer-not-found"] a') as HTMLAnchorElement;
      expect(cta.getAttribute('href')).toBe('/designer');
    });
  });
});
