import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { AddressService } from './address.service';
import type { Address } from './address.types';

const V3_BASE = 'https://api-v3.3bayti.ae';

function makeAddress(overrides: Partial<Address> = {}): Address {
  return {
    id: 1,
    recipient_name: 'Jane Doe',
    recipient_phone: '+971501234567',
    emirate: 'Dubai',
    area: 'Jumeirah',
    street_address: 'Some street',
    building_details: null,
    postal_code: null,
    label: 'Home',
    is_default_shipping: true,
    is_default_billing: false,
    created_at: '2026-05-19T00:00:00Z',
    updated_at: '2026-05-19T00:00:00Z',
    ...overrides,
  };
}

function setup(): { service: AddressService; controller: HttpTestingController } {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting()],
  });
  return {
    service: TestBed.inject(AddressService),
    controller: TestBed.inject(HttpTestingController),
  };
}

describe('AddressService', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('list()', () => {
    it('GETs /v3/me/addresses and sets the signal', async () => {
      const { service, controller } = setup();
      const promise = service.list();
      const req = controller.expectOne(`${V3_BASE}/v3/me/addresses`);
      expect(req.request.method).toBe('GET');
      req.flush({ addresses: [makeAddress()] });
      await promise;

      expect(service.addresses()).toHaveLength(1);
      expect(service.addresses()[0].id).toBe(1);
    });

    it('tolerates a bare-array response shape', async () => {
      const { service, controller } = setup();
      const promise = service.list();
      controller.expectOne(`${V3_BASE}/v3/me/addresses`).flush([makeAddress({ id: 7 })]);
      await promise;
      expect(service.addresses()[0].id).toBe(7);
    });

    it('exposes defaultShipping/defaultBilling derived signals', async () => {
      const { service, controller } = setup();
      const promise = service.list();
      controller.expectOne(`${V3_BASE}/v3/me/addresses`).flush({
        addresses: [
          makeAddress({ id: 1, is_default_shipping: true, is_default_billing: false }),
          makeAddress({ id: 2, is_default_shipping: false, is_default_billing: true }),
        ],
      });
      await promise;

      expect(service.defaultShipping()?.id).toBe(1);
      expect(service.defaultBilling()?.id).toBe(2);
    });

    it('hasAny is false for empty list and true after population', async () => {
      const { service, controller } = setup();
      const promise = service.list();
      controller.expectOne(`${V3_BASE}/v3/me/addresses`).flush({ addresses: [] });
      await promise;
      expect(service.hasAny()).toBe(false);

      const promise2 = service.list();
      controller.expectOne(`${V3_BASE}/v3/me/addresses`).flush({ addresses: [makeAddress()] });
      await promise2;
      expect(service.hasAny()).toBe(true);
    });

    it('toggles isLoading during the request', async () => {
      const { service, controller } = setup();
      const promise = service.list();
      expect(service.isLoading()).toBe(true);
      controller.expectOne(`${V3_BASE}/v3/me/addresses`).flush({ addresses: [] });
      await promise;
      expect(service.isLoading()).toBe(false);
    });
  });

  describe('create()', () => {
    it('POSTs the input and re-lists on success', async () => {
      const { service, controller } = setup();
      const input = {
        recipient_name: 'X',
        recipient_phone: '+971501234567',
        emirate: 'Dubai',
        area: 'JLT',
        is_default: false,
      };
      const promise = service.create(input);

      const post = controller.expectOne(`${V3_BASE}/v3/me/addresses`);
      expect(post.request.method).toBe('POST');
      expect(post.request.body).toEqual(input);
      post.flush(makeAddress({ id: 99 }));

      /* Drain the post .then chain so the chained list() call fires. */
      await Promise.resolve();
      await Promise.resolve();

      controller.expectOne(`${V3_BASE}/v3/me/addresses`).flush({ addresses: [makeAddress({ id: 99 })] });
      await promise;
      expect(service.addresses()[0].id).toBe(99);
    });
  });

  describe('update()', () => {
    it('PUTs to /v3/me/addresses/:id and re-lists', async () => {
      const { service, controller } = setup();
      const input = {
        recipient_name: 'Updated',
        recipient_phone: '+971501234567',
        emirate: 'Dubai',
        area: 'JBR',
      };
      const promise = service.update(5, input);
      const put = controller.expectOne(`${V3_BASE}/v3/me/addresses/5`);
      expect(put.request.method).toBe('PUT');
      expect(put.request.body).toEqual(input);
      put.flush(makeAddress({ id: 5, area: 'JBR' }));

      await Promise.resolve();
      await Promise.resolve();

      controller.expectOne(`${V3_BASE}/v3/me/addresses`).flush({
        addresses: [makeAddress({ id: 5, area: 'JBR' })],
      });
      await promise;
      expect(service.addresses()[0].area).toBe('JBR');
    });
  });

  describe('delete()', () => {
    it('DELETEs and re-lists', async () => {
      const { service, controller } = setup();
      const promise = service.delete(7);
      const del = controller.expectOne(`${V3_BASE}/v3/me/addresses/7`);
      expect(del.request.method).toBe('DELETE');
      del.flush(null, { status: 204, statusText: 'No Content' });

      await Promise.resolve();
      await Promise.resolve();

      controller.expectOne(`${V3_BASE}/v3/me/addresses`).flush({ addresses: [] });
      await promise;
      expect(service.addresses()).toEqual([]);
    });
  });

  describe('setDefault()', () => {
    it('PATCHes the default endpoint with the shipping/billing flag', async () => {
      const { service, controller } = setup();
      const promise = service.setDefault(3, { shipping: true });
      const patch = controller.expectOne(`${V3_BASE}/v3/me/addresses/3/default`);
      expect(patch.request.method).toBe('PATCH');
      expect(patch.request.body).toEqual({ shipping: true });
      patch.flush(makeAddress({ id: 3, is_default_shipping: true }));

      await Promise.resolve();
      await Promise.resolve();

      controller.expectOne(`${V3_BASE}/v3/me/addresses`).flush({
        addresses: [makeAddress({ id: 3, is_default_shipping: true })],
      });
      await promise;
      expect(service.defaultShipping()?.id).toBe(3);
    });
  });
});
