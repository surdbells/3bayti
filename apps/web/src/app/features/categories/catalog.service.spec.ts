import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { CatalogService, CATALOG_PAGE_SIZE, type Facets } from './catalog.service';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { ApiConfigService } from '../../core/api/api-config.service';
import type { Product } from '../catalog/product.model';

const V3 = 'https://api-v3.3bayti.ae';

function makeProduct(o: Partial<Product> = {}): Product {
  return {
    id: 1, slug: 'abaya-01', name: 'Abaya',
    price: { amount: 299, currency: 'AED' },
    sale_price: null, primary_image: null, ...o,
  } as Product;
}

function makeFacets(o: Partial<Facets> = {}): Facets {
  return {
    size: { values: [{ value: 'M', count: 4 }], total_distinct: 1 },
    color: { values: [{ value: 'black', count: 9 }], total_distinct: 1 },
    price: { values: [{ value: '0-50', count: 2, min: 0, max: 50 }] },
    vendor: { values: [{ value: 'acme', label: 'Acme', count: 3 }], total_distinct: 1 },
    category: { values: [{ value: 'abayas', label: 'Abayas', count: 12 }], total_distinct: 1 },
    total_products: 12,
    ...o,
  };
}

function setup(): { service: CatalogService; controller: HttpTestingController } {
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      RoutedHttpClient,
      ApiConfigService,
      CatalogService,
    ],
  });
  return {
    service: TestBed.inject(CatalogService),
    controller: TestBed.inject(HttpTestingController),
  };
}

describe('CatalogService', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('toQuery', () => {
    it('omits empty fields and only sends set ones', () => {
      const { service } = setup();
      expect(service.toQuery({ category: 'abayas' })).toEqual({ category: 'abayas' });
      expect(service.toQuery({})).toEqual({});
    });

    it('joins multi-value facets with commas and omits default sort', () => {
      const { service } = setup();
      const q = service.toQuery({
        category: 'abayas', sizes: ['S', 'M'], colors: ['black'],
        minPrice: 50, maxPrice: 500, sort: 'newest',
      });
      expect(q).toEqual({
        category: 'abayas', sizes: 'S,M', colors: 'black',
        min_price: 50, max_price: 500,
      });
      expect(q['sort']).toBeUndefined(); // newest is the default → omitted
    });

    it('includes a non-default sort', () => {
      const { service } = setup();
      expect(service.toQuery({ sort: 'price_asc' })['sort']).toBe('price_asc');
    });
  });

  describe('loadProducts', () => {
    it('GETs /v3/products with filters + pagination and replaces the accumulator', async () => {
      const { service, controller } = setup();
      const promise = service.loadProducts({ category: 'abayas', sizes: ['M'] }, 0);
      const req = controller.expectOne(r => r.url === `${V3}/v3/products`);
      expect(req.request.params.get('category')).toBe('abayas');
      expect(req.request.params.get('sizes')).toBe('M');
      expect(req.request.params.get('limit')).toBe(String(CATALOG_PAGE_SIZE));
      expect(req.request.params.get('offset')).toBe('0');
      req.flush({ data: [makeProduct({ id: 1 }), makeProduct({ id: 2, slug: 'p2' })],
        meta: { total: 30, limit: CATALOG_PAGE_SIZE, offset: 0, has_more: true } });
      const page = await promise;
      expect(page.items).toHaveLength(2);
      expect(page.total).toBe(30);
      expect(page.hasMore).toBe(true);
      expect(service.products()).toHaveLength(2);
      expect(service.hasMore()).toBe(true);
    });

    it('appends on load-more and advances the offset', async () => {
      const { service, controller } = setup();
      // page 0
      const p0 = service.loadProducts({ category: 'abayas' }, 0);
      controller.expectOne(r => r.url === `${V3}/v3/products`)
        .flush({ data: [makeProduct({ id: 1 })], meta: { total: 30, has_more: true } });
      await p0;
      // page 1, append
      const p1 = service.loadProducts({ category: 'abayas' }, 1, true);
      const req = controller.expectOne(r => r.url === `${V3}/v3/products`);
      expect(req.request.params.get('offset')).toBe(String(CATALOG_PAGE_SIZE));
      req.flush({ data: [makeProduct({ id: 2, slug: 'p2' })], meta: { total: 30, has_more: false } });
      await p1;
      expect(service.products()).toHaveLength(2);
      expect(service.hasMore()).toBe(false);
    });

    it('reset clears the accumulator', async () => {
      const { service, controller } = setup();
      const p = service.loadProducts({}, 0);
      controller.expectOne(r => r.url === `${V3}/v3/products`)
        .flush({ data: [makeProduct()], meta: { total: 1, has_more: false } });
      await p;
      expect(service.products()).toHaveLength(1);
      service.reset();
      expect(service.products()).toHaveLength(0);
      expect(service.total()).toBe(0);
    });
  });

  describe('loadFacets', () => {
    it('GETs /v3/products/facets and stores the result', async () => {
      const { service, controller } = setup();
      const promise = service.loadFacets({ category: 'abayas' });
      const req = controller.expectOne(r => r.url === `${V3}/v3/products/facets`);
      expect(req.request.params.get('category')).toBe('abayas');
      req.flush({ data: makeFacets(), meta: {} });
      const facets = await promise;
      expect(facets?.total_products).toBe(12);
      expect(service.facets()?.size.values[0].value).toBe('M');
    });

    it('keeps previous facets on error (filters still work without counts)', async () => {
      const { service, controller } = setup();
      // First successful load
      const p1 = service.loadFacets({ category: 'abayas' });
      controller.expectOne(r => r.url === `${V3}/v3/products/facets`).flush({ data: makeFacets() });
      await p1;
      // Second load errors
      const p2 = service.loadFacets({ category: 'abayas', sizes: ['M'] });
      controller.expectOne(r => r.url === `${V3}/v3/products/facets`)
        .flush('boom', { status: 500, statusText: 'Server Error' });
      const result = await p2;
      expect(result?.total_products).toBe(12); // previous facets retained
      expect(service.facets()?.total_products).toBe(12);
    });
  });
});
