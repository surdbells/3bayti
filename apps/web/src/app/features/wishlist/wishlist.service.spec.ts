import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { WishlistService } from './wishlist.service';
import { AuthService } from '../../core/auth/auth.service';
import type { Product } from '../catalog/product.model';

const V3 = 'https://api-v3.3bayti.ae/v3/me/wishlist';

function makeProduct(id: number): Product {
  return {
    id,
    slug: `p-${id}`,
    name: `Product ${id}`,
    price: { amount: 100, currency: 'AED' },
    sale_price: null,
    in_stock: true,
    is_new: false,
    is_bestseller: false,
    primary_image: null,
    vendor: null,
    rating: null,
    review_count: 0,
  } as unknown as Product;
}

function setup(): { service: WishlistService; controller: HttpTestingController } {
  /* Minimal AuthService stub, the service injects it only for its
     reset-on-signout effect; treat the user as authed throughout. */
  const authStub = { isAuthenticated: signal(true) };
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: AuthService, useValue: authStub },
      WishlistService,
    ],
  });
  return {
    service: TestBed.inject(WishlistService),
    controller: TestBed.inject(HttpTestingController),
  };
}

describe('WishlistService', () => {
  afterEach(() => {
    try {
      TestBed.inject(HttpTestingController).verify();
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('load', () => {
    it('loads a page, sets products + meta + saved ids', async () => {
      const { service, controller } = setup();
      const promise = service.load(24, 0);
      const req = controller.expectOne(`${V3}?limit=24&offset=0`);
      expect(req.request.method).toBe('GET');
      req.flush({
        data: [makeProduct(1), makeProduct(2)],
        meta: { total: 2, limit: 24, offset: 0, has_more: false },
      });
      await promise;
      expect(service.products()).toHaveLength(2);
      expect(service.isSaved(1)).toBe(true);
      expect(service.isSaved(2)).toBe(true);
      expect(service.count()).toBe(2);
    });

    it('appends on offset > 0 (load-more)', async () => {
      const { service, controller } = setup();
      const p1 = service.load(2, 0);
      controller.expectOne(`${V3}?limit=2&offset=0`).flush({
        data: [makeProduct(1), makeProduct(2)],
        meta: { total: 4, limit: 2, offset: 0, has_more: true },
      });
      await p1;
      const p2 = service.loadMore();
      controller.expectOne(`${V3}?limit=2&offset=2`).flush({
        data: [makeProduct(3), makeProduct(4)],
        meta: { total: 4, limit: 2, offset: 2, has_more: false },
      });
      await p2;
      expect(service.products().map(p => p.id)).toEqual([1, 2, 3, 4]);
    });

    it('loadMore is a no-op when has_more is false', async () => {
      const { service, controller } = setup();
      const p1 = service.load(24, 0);
      controller.expectOne(`${V3}?limit=24&offset=0`).flush({
        data: [makeProduct(1)],
        meta: { total: 1, limit: 24, offset: 0, has_more: false },
      });
      await p1;
      await service.loadMore();
      controller.verify(); // no extra request issued
    });
  });

  describe('add', () => {
    it('POSTs the product id and marks it saved', async () => {
      const { service, controller } = setup();
      const promise = service.add(makeProduct(7));
      const req = controller.expectOne(V3);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ product_id: 7 });
      req.flush({ data: makeProduct(7) }, { status: 201, statusText: 'Created' });
      await promise;
      expect(service.isSaved(7)).toBe(true);
    });
  });

  describe('remove', () => {
    it('DELETEs by id, unmarks it, prunes the list + decrements total', async () => {
      const { service, controller } = setup();
      const loaded = service.load(24, 0);
      controller.expectOne(`${V3}?limit=24&offset=0`).flush({
        data: [makeProduct(1), makeProduct(2)],
        meta: { total: 2, limit: 24, offset: 0, has_more: false },
      });
      await loaded;

      const promise = service.remove(1);
      const req = controller.expectOne(`${V3}/1`);
      expect(req.request.method).toBe('DELETE');
      req.flush(null, { status: 204, statusText: 'No Content' });
      await promise;

      expect(service.isSaved(1)).toBe(false);
      expect(service.products().map(p => p.id)).toEqual([2]);
      expect(service.count()).toBe(1);
    });
  });

  describe('toggle', () => {
    it('adds when not saved', async () => {
      const { service, controller } = setup();
      const promise = service.toggle(makeProduct(9));
      controller.expectOne(V3).flush({ data: makeProduct(9) }, { status: 201, statusText: 'Created' });
      await promise;
      expect(service.isSaved(9)).toBe(true);
    });

    it('removes when already saved', async () => {
      const { service, controller } = setup();
      const add = service.add(makeProduct(9));
      controller.expectOne(V3).flush({ data: makeProduct(9) }, { status: 201, statusText: 'Created' });
      await add;
      expect(service.isSaved(9)).toBe(true);

      const promise = service.toggle(makeProduct(9));
      controller.expectOne(`${V3}/9`).flush(null, { status: 204, statusText: 'No Content' });
      await promise;
      expect(service.isSaved(9)).toBe(false);
    });
  });

  describe('reset', () => {
    it('clears saved ids, products and meta', async () => {
      const { service, controller } = setup();
      const loaded = service.load(24, 0);
      controller.expectOne(`${V3}?limit=24&offset=0`).flush({
        data: [makeProduct(1)],
        meta: { total: 1, limit: 24, offset: 0, has_more: false },
      });
      await loaded;
      service.reset();
      expect(service.products()).toEqual([]);
      expect(service.isSaved(1)).toBe(false);
      expect(service.meta()).toBeNull();
    });
  });
});
