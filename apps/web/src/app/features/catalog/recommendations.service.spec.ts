import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { RecommendationsService, type Recommendation } from './recommendations.service';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { ApiConfigService } from '../../core/api/api-config.service';
import type { Product } from './product.model';

const V3 = 'https://api-v3.3bayti.ae';

function makeProduct(o: Partial<Product> = {}): Product {
  return {
    id: 1, slug: 'abaya-01', name: 'Abaya',
    price: { amount: '299.00', currency: 'AED' },
    sale_price: null, primary_image: null, ...o,
  } as Product;
}

function rec(o: Partial<Recommendation> = {}): Recommendation {
  return { product: makeProduct(), score: '5.0000', source: 'copurchase', ...o };
}

function setup(): { service: RecommendationsService; controller: HttpTestingController } {
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      RoutedHttpClient,
      ApiConfigService,
      RecommendationsService,
    ],
  });
  return {
    service: TestBed.inject(RecommendationsService),
    controller: TestBed.inject(HttpTestingController),
  };
}

describe('RecommendationsService', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('forProduct', () => {
    it('GETs /v3/products/:slug/recommendations with the limit and returns rows', async () => {
      const { service, controller } = setup();
      const promise = service.forProduct('abaya-01', 8);
      const req = controller.expectOne(r => r.url === `${V3}/v3/products/abaya-01/recommendations`);
      expect(req.request.params.get('limit')).toBe('8');
      req.flush({ data: [rec({ source: 'copurchase' }), rec({ source: 'category' })], meta: { total: 2, limit: 8 } });
      const out = await promise;
      expect(out).toHaveLength(2);
      expect(out[0].product.slug).toBe('abaya-01');
      expect(out[1].source).toBe('category');
    });

    it('defaults the limit to 10', async () => {
      const { service, controller } = setup();
      const promise = service.forProduct('abaya-01');
      const req = controller.expectOne(r => r.url === `${V3}/v3/products/abaya-01/recommendations`);
      expect(req.request.params.get('limit')).toBe('10');
      req.flush({ data: [], meta: { total: 0, limit: 10 } });
      expect(await promise).toEqual([]);
    });

    it('returns [] for an empty slug without hitting the network', async () => {
      const { service, controller } = setup();
      const out = await service.forProduct('');
      expect(out).toEqual([]);
      controller.verify(); // no outstanding requests
    });

    it('degrades to [] on HTTP error (recommendations never block the PDP)', async () => {
      const { service, controller } = setup();
      const promise = service.forProduct('abaya-01');
      const req = controller.expectOne(r => r.url === `${V3}/v3/products/abaya-01/recommendations`);
      req.flush('boom', { status: 500, statusText: 'Server Error' });
      expect(await promise).toEqual([]);
    });

    it('tolerates a non-array data payload', async () => {
      const { service, controller } = setup();
      const promise = service.forProduct('abaya-01');
      const req = controller.expectOne(r => r.url === `${V3}/v3/products/abaya-01/recommendations`);
      req.flush({ data: null });
      expect(await promise).toEqual([]);
    });
  });

  describe('forMe', () => {
    it('GETs /v3/me/recommendations with the limit', async () => {
      const { service, controller } = setup();
      const promise = service.forMe(6);
      const req = controller.expectOne(r => r.url === `${V3}/v3/me/recommendations`);
      expect(req.request.params.get('limit')).toBe('6');
      req.flush({ data: [rec({ source: 'category' })], meta: { total: 1, limit: 6 } });
      const out = await promise;
      expect(out).toHaveLength(1);
    });

    it('degrades to [] on 401 (unauthenticated)', async () => {
      const { service, controller } = setup();
      const promise = service.forMe();
      const req = controller.expectOne(r => r.url === `${V3}/v3/me/recommendations`);
      req.flush('unauth', { status: 401, statusText: 'Unauthorized' });
      expect(await promise).toEqual([]);
    });
  });
});
