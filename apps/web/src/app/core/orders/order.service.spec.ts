import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { OrderService } from './order.service';
import type { Order, OrderListItem } from './order.types';

const V3_BASE = 'https://api-v3.3bayti.ae';

function makeListItem(overrides: Partial<OrderListItem> = {}): OrderListItem {
  return {
    id: 1,
    order_reference: 'BAYTI-2026-001',
    status: 'paid',
    date: '2026-05-19T10:00:00Z',
    subtotal: '200.00',
    delivery_fee: '25.00',
    discount: '0.00',
    total: '225.00',
    currency: 'AED',
    paid_at: '2026-05-19T10:05:00Z',
    items: [],
    applied_promo: null,
    ...overrides,
  };
}

function makeOrder(overrides: Partial<Order> = {}): Order {
  return {
    ...makeListItem(),
    billing_address: {
      id: 1, recipient_name: 'Jane', recipient_phone: '+971501234567',
      emirate: 'Dubai', area: 'JLT', street_address: null,
      building_details: null, postal_code: null, label: 'Home',
    },
    shipping_address: {
      id: 1, recipient_name: 'Jane', recipient_phone: '+971501234567',
      emirate: 'Dubai', area: 'JLT', street_address: null,
      building_details: null, postal_code: null, label: 'Home',
    },
    ...overrides,
  } as Order;
}

function setup(): { service: OrderService; controller: HttpTestingController } {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting()],
  });
  return {
    service: TestBed.inject(OrderService),
    controller: TestBed.inject(HttpTestingController),
  };
}

describe('OrderService', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('loadMore', () => {
    it('GETs /v3/orders with default limit + offset on first load', async () => {
      const { service, controller } = setup();
      const promise = service.loadMore();
      const req = controller.expectOne(r => r.url === `${V3_BASE}/v3/orders`);
      expect(req.request.params.get('limit')).toBe('10');
      expect(req.request.params.get('offset')).toBe('0');
      req.flush([makeListItem(), makeListItem({ id: 2 })]);
      await promise;
      expect(service.listItems()).toHaveLength(2);
    });

    it('replaces accumulator when offset=0', async () => {
      const { service, controller } = setup();
      const p1 = service.loadMore();
      controller.expectOne(r => r.url === `${V3_BASE}/v3/orders`).flush([makeListItem({ id: 1 })]);
      await p1;
      expect(service.listItems()).toHaveLength(1);

      /* Second load with offset=0 (e.g. on route re-entry) */
      const p2 = service.loadMore({ offset: 0 });
      controller.expectOne(r => r.url === `${V3_BASE}/v3/orders`).flush([
        makeListItem({ id: 5 }),
        makeListItem({ id: 6 }),
      ]);
      await p2;
      expect(service.listItems()).toHaveLength(2);
      expect(service.listItems()[0].id).toBe(5);
    });

    it('appends when offset > 0', async () => {
      const { service, controller } = setup();
      const p1 = service.loadMore();
      controller.expectOne(r => r.url === `${V3_BASE}/v3/orders`).flush([
        makeListItem({ id: 1 }), makeListItem({ id: 2 }), makeListItem({ id: 3 }),
        makeListItem({ id: 4 }), makeListItem({ id: 5 }), makeListItem({ id: 6 }),
        makeListItem({ id: 7 }), makeListItem({ id: 8 }), makeListItem({ id: 9 }),
        makeListItem({ id: 10 }),
      ]);
      await p1;
      expect(service.loadedCount()).toBe(10);

      const p2 = service.loadMore();
      const req2 = controller.expectOne(r => r.url === `${V3_BASE}/v3/orders`);
      expect(req2.request.params.get('offset')).toBe('10');
      req2.flush([makeListItem({ id: 11 })]);
      await p2;
      expect(service.loadedCount()).toBe(11);
    });

    it('hasMore is true when last page filled, false when short', async () => {
      const { service, controller } = setup();
      const p1 = service.loadMore({ limit: 3 });
      controller.expectOne(r => r.url === `${V3_BASE}/v3/orders`).flush([
        makeListItem({ id: 1 }), makeListItem({ id: 2 }), makeListItem({ id: 3 }),
      ]);
      await p1;
      expect(service.hasMore()).toBe(true);

      const p2 = service.loadMore();
      controller.expectOne(r => r.url === `${V3_BASE}/v3/orders`).flush([makeListItem({ id: 4 })]);
      await p2;
      expect(service.hasMore()).toBe(false);
    });

    it('hasMore is false on empty initial load', async () => {
      const { service, controller } = setup();
      const promise = service.loadMore();
      controller.expectOne(r => r.url === `${V3_BASE}/v3/orders`).flush([]);
      await promise;
      expect(service.hasMore()).toBe(false);
    });

    it('toggles isLoadingList during the request', async () => {
      const { service, controller } = setup();
      const promise = service.loadMore();
      expect(service.isLoadingList()).toBe(true);
      controller.expectOne(r => r.url === `${V3_BASE}/v3/orders`).flush([]);
      await promise;
      expect(service.isLoadingList()).toBe(false);
    });

    it('tolerates non-array response defensively', async () => {
      const { service, controller } = setup();
      const promise = service.loadMore();
      controller.expectOne(r => r.url === `${V3_BASE}/v3/orders`)
        .flush({ unexpected: 'shape' } as unknown as OrderListItem[]);
      await promise;
      expect(service.listItems()).toEqual([]);
    });
  });

  describe('reset', () => {
    it('clears the accumulator', async () => {
      const { service, controller } = setup();
      const promise = service.loadMore();
      controller.expectOne(r => r.url === `${V3_BASE}/v3/orders`).flush([makeListItem()]);
      await promise;
      expect(service.listItems()).toHaveLength(1);

      service.reset();
      expect(service.listItems()).toEqual([]);
      expect(service.hasMore()).toBe(false);
    });
  });

  describe('getById', () => {
    it('GETs /v3/orders/:id and returns the detail shape', async () => {
      const { service, controller } = setup();
      const promise = service.getById(42);
      const req = controller.expectOne(`${V3_BASE}/v3/orders/42`);
      expect(req.request.method).toBe('GET');
      req.flush(makeOrder({ id: 42 }));
      const result = await promise;
      expect(result.id).toBe(42);
      expect(result.shipping_address).toBeDefined();
    });

    it('toggles isLoadingDetail during the request', async () => {
      const { service, controller } = setup();
      const promise = service.getById(1);
      expect(service.isLoadingDetail()).toBe(true);
      controller.expectOne(`${V3_BASE}/v3/orders/1`).flush(makeOrder());
      await promise;
      expect(service.isLoadingDetail()).toBe(false);
    });
  });

  describe('cancel', () => {
    it('POSTs /v3/orders/:id/cancel and returns the updated order', async () => {
      const { service, controller } = setup();
      const promise = service.cancel(7);
      const req = controller.expectOne(`${V3_BASE}/v3/orders/7/cancel`);
      expect(req.request.method).toBe('POST');
      req.flush(makeOrder({ id: 7, status: 'cancelled' }));
      const result = await promise;
      expect(result.status).toBe('cancelled');
    });

    it('patches the cached list item if loaded', async () => {
      const { service, controller } = setup();
      const p1 = service.loadMore();
      controller.expectOne(r => r.url === `${V3_BASE}/v3/orders`).flush([
        makeListItem({ id: 1, status: 'pending_payment' }),
        makeListItem({ id: 2, status: 'paid' }),
      ]);
      await p1;

      const p2 = service.cancel(1);
      controller.expectOne(`${V3_BASE}/v3/orders/1/cancel`)
        .flush(makeOrder({ id: 1, status: 'cancelled' }));
      await p2;

      const updated = service.listItems().find(o => o.id === 1);
      expect(updated?.status).toBe('cancelled');
      /* The other order is untouched. */
      expect(service.listItems().find(o => o.id === 2)?.status).toBe('paid');
    });

    it('cancel still resolves when the order is not in the cached list', async () => {
      const { service, controller } = setup();
      const promise = service.cancel(99);
      controller.expectOne(`${V3_BASE}/v3/orders/99/cancel`)
        .flush(makeOrder({ id: 99, status: 'cancelled' }));
      await expect(promise).resolves.toBeDefined();
    });
  });

  describe('submitReturn', () => {
    it('POSTs multipart/form-data to /v3/orders/:id/returns', async () => {
      const { service, controller } = setup();
      const photo = new File(['fake-image-bytes'], 'p1.jpg', { type: 'image/jpeg' });
      const promise = service.submitReturn(7, {
        reason: 'defective',
        customer_notes: 'broken zipper',
        order_item_ids: [1, 2],
        photos: [photo],
      });
      const req = controller.expectOne(`${V3_BASE}/v3/orders/7/returns`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toBeInstanceOf(FormData);

      const body = req.request.body as FormData;
      expect(body.get('reason')).toBe('defective');
      expect(body.get('customer_notes')).toBe('broken zipper');
      expect(body.getAll('order_item_ids[]')).toEqual(['1', '2']);
      const files = body.getAll('photos[]');
      expect(files).toHaveLength(1);
      expect((files[0] as File).name).toBe('p1.jpg');

      req.flush({ id: 99, status: 'pending', reason: 'defective',
                  requested_at: '2026-05-20T00:00:00Z', item_count: 2 });
      const result = await promise;
      expect(result.id).toBe(99);
    });

    it('omits customer_notes when null', async () => {
      const { service, controller } = setup();
      const promise = service.submitReturn(1, {
        reason: 'changed_mind',
        customer_notes: null,
        order_item_ids: [5],
        photos: [],
      });
      const req = controller.expectOne(`${V3_BASE}/v3/orders/1/returns`);
      const body = req.request.body as FormData;
      expect(body.has('customer_notes')).toBe(false);
      expect(body.get('reason')).toBe('changed_mind');
      expect(body.getAll('order_item_ids[]')).toEqual(['5']);
      req.flush({ id: 1, status: 'pending', reason: 'changed_mind',
                  requested_at: '2026-05-20T00:00:00Z', item_count: 1 });
      await promise;
    });

    it('toggles isLoadingDetail during the request', async () => {
      const { service, controller } = setup();
      const promise = service.submitReturn(1, {
        reason: 'defective', customer_notes: null,
        order_item_ids: [1], photos: [],
      });
      expect(service.isLoadingDetail()).toBe(true);
      controller.expectOne(`${V3_BASE}/v3/orders/1/returns`).flush({
        id: 1, status: 'pending', reason: 'defective',
        requested_at: '2026-05-20T00:00:00Z', item_count: 1,
      });
      await promise;
      expect(service.isLoadingDetail()).toBe(false);
    });
  });
});
