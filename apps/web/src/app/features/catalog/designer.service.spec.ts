import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DesignerService } from './designer.service';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { ApiConfigService } from '../../core/api/api-config.service';
import type { Designer } from './designer.model';
import type { Product } from './product.model';

const V3 = 'https://api-v3.3bayti.ae';

function envelope<T>(data: T, meta?: { total: number; limit: number; offset: number; has_more: boolean }) {
  return meta ? { data, meta } : { data };
}

function makeDesigner(o: Partial<Designer> = {}): Designer {
  return {
    id: 1, slug: 'acme-couture', name: 'Acme Couture',
    description: 'Fine kaftans', logo_url: 'https://img/logo.png',
    cover_image_url: 'https://img/cover.png', is_verified: true, ...o,
  };
}

function makeProduct(o: Partial<Product> = {}): Product {
  return {
    id: 1, slug: 'abaya-01', name: 'Abaya',
    price: { amount: '299.00', currency: 'AED' },
    sale_price: null, primary_image: null, ...o,
  } as Product;
}

function setup(): { service: DesignerService; controller: HttpTestingController } {
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      RoutedHttpClient,
      ApiConfigService,
      DesignerService,
    ],
  });
  return {
    service: TestBed.inject(DesignerService),
    controller: TestBed.inject(HttpTestingController),
  };
}

describe('DesignerService', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('directory loadMore', () => {
    it('GETs /v3/vendors and populates the directory', async () => {
      const { service, controller } = setup();
      const promise = service.loadMore();
      const req = controller.expectOne(r => r.url === `${V3}/v3/vendors`);
      expect(req.request.method).toBe('GET');
      expect(req.request.params.get('limit')).toBe('24');
      expect(req.request.params.get('offset')).toBe('0');
      req.flush(envelope([makeDesigner({ id: 1 }), makeDesigner({ id: 2, slug: 'b' })],
        { total: 2, limit: 24, offset: 0, has_more: false }));
      await promise;
      expect(service.directory()).toHaveLength(2);
      expect(service.hasMore()).toBe(false);
    });

    it('replaces accumulator at offset 0, appends at offset > 0', async () => {
      const { service, controller } = setup();
      const p1 = service.loadMore({ offset: 0, limit: 2 });
      controller.expectOne(r => r.url === `${V3}/v3/vendors`).flush(
        envelope([makeDesigner({ id: 1 }), makeDesigner({ id: 2, slug: 'b' })],
          { total: 4, limit: 2, offset: 0, has_more: true }));
      await p1;
      expect(service.directory()).toHaveLength(2);
      expect(service.hasMore()).toBe(true);

      const p2 = service.loadMore();
      const req2 = controller.expectOne(r => r.url === `${V3}/v3/vendors`);
      expect(req2.request.params.get('offset')).toBe('2');
      req2.flush(envelope([makeDesigner({ id: 3, slug: 'c' })],
        { total: 4, limit: 2, offset: 2, has_more: false }));
      await p2;
      expect(service.directory()).toHaveLength(3);
      expect(service.hasMore()).toBe(false);
    });

    it('defaults has_more to false when meta is absent', async () => {
      const { service, controller } = setup();
      const promise = service.loadMore();
      controller.expectOne(r => r.url === `${V3}/v3/vendors`).flush(envelope([makeDesigner()]));
      await promise;
      expect(service.hasMore()).toBe(false);
    });

    it('tolerates a non-array data payload', async () => {
      const { service, controller } = setup();
      const promise = service.loadMore();
      controller.expectOne(r => r.url === `${V3}/v3/vendors`)
        .flush(envelope({ unexpected: true } as unknown as Designer[]));
      await promise;
      expect(service.directory()).toEqual([]);
    });

    it('toggles isLoadingList during the request', async () => {
      const { service, controller } = setup();
      const promise = service.loadMore();
      expect(service.isLoadingList()).toBe(true);
      controller.expectOne(r => r.url === `${V3}/v3/vendors`).flush(envelope([]));
      await promise;
      expect(service.isLoadingList()).toBe(false);
    });

    it('reset clears the accumulator', async () => {
      const { service, controller } = setup();
      const promise = service.loadMore();
      controller.expectOne(r => r.url === `${V3}/v3/vendors`).flush(
        envelope([makeDesigner()], { total: 1, limit: 24, offset: 0, has_more: true }));
      await promise;
      expect(service.directory()).toHaveLength(1);
      service.reset();
      expect(service.directory()).toEqual([]);
      expect(service.hasMore()).toBe(false);
    });
  });

  describe('getFeatured', () => {
    it('GETs /v3/featured-vendors with the limit', async () => {
      const { service, controller } = setup();
      const promise = service.getFeatured(6);
      const req = controller.expectOne(r => r.url === `${V3}/v3/featured-vendors`);
      expect(req.request.params.get('limit')).toBe('6');
      req.flush(envelope([{ slug: 'acme', name: 'Acme', description: null, rating: 4.5, rating_count: 10, products: [] }]));
      const result = await promise;
      expect(result).toHaveLength(1);
      expect(result[0].slug).toBe('acme');
    });

    it('tolerates non-array data', async () => {
      const { service, controller } = setup();
      const promise = service.getFeatured();
      controller.expectOne(r => r.url === `${V3}/v3/featured-vendors`).flush(envelope(null as never));
      const result = await promise;
      expect(result).toEqual([]);
    });
  });

  describe('getBySlug', () => {
    it('GETs /v3/vendors/:slug and unwraps data', async () => {
      const { service, controller } = setup();
      const promise = service.getBySlug('acme-couture');
      const req = controller.expectOne(`${V3}/v3/vendors/acme-couture`);
      expect(req.request.method).toBe('GET');
      req.flush(envelope(makeDesigner({ slug: 'acme-couture', name: 'Acme Couture' })));
      const result = await promise;
      expect(result.slug).toBe('acme-couture');
      expect(result.name).toBe('Acme Couture');
    });

    it('propagates 404 for an unknown slug', async () => {
      const { service, controller } = setup();
      const promise = service.getBySlug('nope');
      controller.expectOne(`${V3}/v3/vendors/nope`)
        .flush({ error_code: 'NOT_FOUND' }, { status: 404, statusText: 'Not Found' });
      await expect(promise).rejects.toBeDefined();
    });
  });

  describe('listProducts', () => {
    it('GETs /v3/vendors/:slug/products with pagination + returns hasMore', async () => {
      const { service, controller } = setup();
      const promise = service.listProducts('acme-couture', { limit: 12, offset: 0 });
      const req = controller.expectOne(r => r.url === `${V3}/v3/vendors/acme-couture/products`);
      expect(req.request.params.get('limit')).toBe('12');
      expect(req.request.params.get('offset')).toBe('0');
      req.flush(envelope([makeProduct({ id: 1 }), makeProduct({ id: 2, slug: 'p2' })],
        { total: 30, limit: 12, offset: 0, has_more: true }));
      const page = await promise;
      expect(page.items).toHaveLength(2);
      expect(page.hasMore).toBe(true);
    });

    it('defaults limit/offset and hasMore=false without meta', async () => {
      const { service, controller } = setup();
      const promise = service.listProducts('acme-couture');
      const req = controller.expectOne(r => r.url === `${V3}/v3/vendors/acme-couture/products`);
      expect(req.request.params.get('limit')).toBe('24');
      expect(req.request.params.get('offset')).toBe('0');
      req.flush(envelope([makeProduct()]));
      const page = await promise;
      expect(page.hasMore).toBe(false);
    });
  });
});
