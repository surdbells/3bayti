import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { AddressService, SavedAddress } from './address.service';
import { MobileNetworkAdapter } from '../http/mobile-network-adapter';

function addr(id: number, over: Partial<SavedAddress> = {}): SavedAddress {
  return {
    id,
    label: `Home ${id}`,
    recipient_name: 'Jane Doe',
    recipient_phone: '+971500000000',
    emirate: 'Dubai',
    area: 'Al Marmoom',
    street_address: '1 Street',
    building_details: 'Villa 2',
    postal_code: null,
    country: 'AE',
    is_default: false,
    is_default_shipping: false,
    is_default_billing: false,
    ...over,
  };
}

class AdapterStub {
  getResp: any = { response_code: 200, status: 'success', data: [] };
  postResp: any = { response_code: 201, status: 'success', data: addr(99) };
  patchResp: any = { response_code: 200, status: 'success', data: {} };
  putResp: any = { response_code: 200, status: 'success', data: addr(99) };
  deleteResp: any = { response_code: 200, status: 'success', data: {} };
  lastGet: any = null;
  lastPost: any = null;
  lastPatch: any = null;
  lastPut: any = null;
  lastDelete: any = null;

  get_v3(routeKey: string, opts: any) {
    this.lastGet = { routeKey, opts };
    return of(this.getResp);
  }
  post_v3(routeKey: string, body: any, opts: any) {
    this.lastPost = { routeKey, body, opts };
    return of(this.postResp);
  }
  patch_v3(routeKey: string, body: any, opts: any) {
    this.lastPatch = { routeKey, body, opts };
    return of(this.patchResp);
  }
  put_v3(routeKey: string, body: any, opts: any) {
    this.lastPut = { routeKey, body, opts };
    return of(this.putResp);
  }
  delete_v3(routeKey: string, opts: any) {
    this.lastDelete = { routeKey, opts };
    return of(this.deleteResp);
  }
}

describe('AddressService', () => {
  let service: AddressService;
  let adapter: AdapterStub;

  beforeEach(() => {
    adapter = new AdapterStub();
    TestBed.configureTestingModule({
      providers: [
        AddressService,
        { provide: MobileNetworkAdapter, useValue: adapter },
      ],
    });
    service = TestBed.inject(AddressService);
  });

  it('lists addresses from a bare array', async () => {
    adapter.getResp = { response_code: 200, status: 'success', data: [addr(1), addr(2)] };
    const list = await service.list('tok');
    expect(adapter.lastGet.routeKey).toBe('GET /me/addresses');
    expect(adapter.lastGet.opts.authToken).toBe('tok');
    expect(list.length).toBe(2);
  });

  it('lists addresses from an { items } envelope', async () => {
    adapter.getResp = { response_code: 200, status: 'success', data: { items: [addr(1)] } };
    const list = await service.list('tok');
    expect(list.length).toBe(1);
  });

  it('returns [] on a non-success list response', async () => {
    adapter.getResp = { response_code: 500, status: 'error' };
    const list = await service.list('tok');
    expect(list).toEqual([]);
  });

  it('creates an address and returns the created row', async () => {
    adapter.postResp = { response_code: 201, status: 'success', data: addr(50) };
    const created = await service.create('tok', {
      recipient_name: 'Jane', recipient_phone: '+971500000000',
      emirate: 'Dubai', area: 'Al Marmoom', street_address: '1 St',
    });
    expect(adapter.lastPost.routeKey).toBe('POST /me/addresses');
    expect(created?.id).toBe(50);
  });

  it('returns null when create fails', async () => {
    adapter.postResp = { response_code: 422, status: 'error' };
    const created = await service.create('tok', {
      recipient_name: 'Jane', recipient_phone: 'x',
      emirate: 'Dubai', area: 'Al Marmoom', street_address: '1 St',
    });
    expect(created).toBeNull();
  });

  it('updates an address and returns the updated row', async () => {
    adapter.putResp = { response_code: 200, status: 'success', data: addr(7, { area: 'New Area' }) };
    const updated = await service.update('tok', 7, { area: 'New Area' });
    expect(adapter.lastPut.routeKey).toBe('PUT /me/addresses/:id');
    expect(adapter.lastPut.opts.pathParams).toEqual({ id: '7' });
    expect(updated?.area).toBe('New Area');
  });

  it('returns null when update fails', async () => {
    adapter.putResp = { response_code: 422, status: 'error' };
    const updated = await service.update('tok', 7, { area: 'x' });
    expect(updated).toBeNull();
  });

  it('removes an address (200 envelope)', async () => {
    adapter.deleteResp = { response_code: 200, status: 'success' };
    const ok = await service.remove('tok', 3);
    expect(adapter.lastDelete.routeKey).toBe('DELETE /me/addresses/:id');
    expect(adapter.lastDelete.opts.pathParams).toEqual({ id: '3' });
    expect(ok).toBeTrue();
  });

  it('removes an address (204 no content)', async () => {
    adapter.deleteResp = { response_code: 204 };
    const ok = await service.remove('tok', 3);
    expect(ok).toBeTrue();
  });

  it('returns false when remove fails', async () => {
    adapter.deleteResp = { response_code: 500, status: 'error' };
    const ok = await service.remove('tok', 3);
    expect(ok).toBeFalse();
  });

  it('sets an address as default', async () => {
    const ok = await service.setDefault('tok', 7);
    expect(adapter.lastPatch.routeKey).toBe('PATCH /me/addresses/:id/default');
    expect(adapter.lastPatch.opts.pathParams).toEqual({ id: '7' });
    // The API rejects an empty/all-null body with 422 — the PATCH must
    // carry both role flags so the promotion actually takes effect.
    expect(adapter.lastPatch.body).toEqual({ shipping: true, billing: true });
    expect(ok).toBeTrue();
  });

  it('sends is_default (not the split shipping/billing flags) on create', async () => {
    await service.create('tok', {
      recipient_name: 'Jane', recipient_phone: '+971500000000',
      emirate: 'Dubai', area: 'Al Marmoom', street_address: '1 St',
      is_default: true,
    });
    // The v3 CreateAddressInput only accepts `is_default`; the old split
    // flags were silently dropped, so the address was never promoted.
    expect(adapter.lastPost.body.is_default).toBeTrue();
    expect(adapter.lastPost.body.is_default_shipping).toBeUndefined();
    expect(adapter.lastPost.body.is_default_billing).toBeUndefined();
  });
});
