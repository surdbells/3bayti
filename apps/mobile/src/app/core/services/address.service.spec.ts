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
  lastGet: any = null;
  lastPost: any = null;
  lastPatch: any = null;

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

  it('sets an address as default', async () => {
    const ok = await service.setDefault('tok', 7);
    expect(adapter.lastPatch.routeKey).toBe('PATCH /me/addresses/:id/default');
    expect(adapter.lastPatch.opts.pathParams).toEqual({ id: '7' });
    expect(ok).toBeTrue();
  });
});
