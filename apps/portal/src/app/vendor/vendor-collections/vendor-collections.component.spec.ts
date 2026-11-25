import { fakeAsync, tick } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';

import { VendorCollectionsComponent } from './vendor-collections.component';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { I18nService } from '../../i18n.service';

const product = (id: number, name: string) => ({ id, name, image: null, price: 10, price_formatted: 'AED 10.00', status: 'active' });

interface FakeAdapter {
  get_v3: jasmine.Spy;
  put_v3: jasmine.Spy;
}

function fakeAdapter(members: () => unknown[] = () => [product(11, 'Abaya'), product(12, 'Scarf')]): FakeAdapter {
  return {
    get_v3: jasmine.createSpy('get_v3').and.callFake((key: string) => {
      if (key === 'GET /vendor/collections') {
        return of({
          data: [
            { id: 5, name: 'Summer', slug: 'summer', description: 'Light', cover_image_url: null, my_product_count: 2 },
            { id: 6, name: 'Eid', slug: 'eid', description: '', cover_image_url: 'https://x/y.jpg', my_product_count: 0 },
          ],
          meta: { total: 2, limit: 100, offset: 0 },
        });
      }
      if (key === 'GET /vendor/collections/:id/products') return of({ data: members(), meta: { total: 2 } });
      if (key === 'GET /vendor/products') return of({ data: [product(11, 'Abaya'), product(13, 'Kaftan')] });
      return of({ data: [] });
    }),
    put_v3: jasmine.createSpy('put_v3').and.callFake((_key: string, body: { product_ids: number[] }) =>
      of({ data: body.product_ids.map((id) => product(id, 'P' + id)), meta: { total: body.product_ids.length } }),
    ),
  };
}

const fakeI18n = {
  t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key),
} as unknown as I18nService;

function makeComponent(adapter: FakeAdapter) {
  const toast = jasmine.createSpyObj<HotToastService>('HotToastService', ['success', 'error']);
  const component = new VendorCollectionsComponent(adapter as unknown as PortalCrudAdapter, toast, fakeI18n);
  component.ngOnInit();
  return { component, toast };
}

describe('VendorCollectionsComponent (logic)', () => {
  it('lists active collections with the vendor\'s own product count', () => {
    const adapter = fakeAdapter();
    const { component } = makeComponent(adapter);
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /vendor/collections', { query: { limit: 100, offset: 0 } });
    expect(component.collections.map((c) => [c.id, c.my_product_count])).toEqual([[5, 2], [6, 0]]);
    expect(component.loading).toBeFalse();
  });

  it('opening a collection loads ONLY the vendor\'s products in it and searches their own catalogue', fakeAsync(() => {
    const adapter = fakeAdapter();
    const { component } = makeComponent(adapter);
    component.toggle(component.collections[0]);
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /vendor/collections/:id/products', { params: { id: '5' } });
    expect(component.members.map((p) => p.id)).toEqual([11, 12]);
    tick(300);
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /vendor/products', { query: { limit: 20, offset: 0 } });
    expect(component.results.map((p) => p.id)).toEqual([11, 13]);
    expect(component.isMember(component.results[0])).toBeTrue();
    expect(component.isMember(component.results[1])).toBeFalse();

    component.onSearch(' kaf ');
    tick(300);
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /vendor/products', { query: { limit: 20, offset: 0, search: 'kaf' } });
  }));

  it('adding PUTs the full own set (appended) with expected_product_ids and updates the count', () => {
    const adapter = fakeAdapter();
    const { component, toast } = makeComponent(adapter);
    component.toggle(component.collections[0]);
    component.add(product(13, 'Kaftan') as any);
    expect(adapter.put_v3).toHaveBeenCalledOnceWith(
      'PUT /vendor/collections/:id/products',
      { product_ids: [11, 12, 13], expected_product_ids: [11, 12] },
      { params: { id: '5' } },
    );
    expect(component.members.map((p) => p.id)).toEqual([11, 12, 13]);
    expect(component.collections[0].my_product_count).toBe(3);
    expect(toast.success).toHaveBeenCalled();
  });

  it('removing rolls back with the API message on failure', () => {
    const adapter = fakeAdapter();
    let fail!: (err: unknown) => void;
    adapter.put_v3.and.returnValue(new Observable((sub) => { fail = (err) => sub.error(err); }));
    const { component, toast } = makeComponent(adapter);
    component.toggle(component.collections[0]);

    component.remove(component.members[0]);
    expect(adapter.put_v3.calls.mostRecent().args[1]).toEqual({ product_ids: [12], expected_product_ids: [11, 12] });
    expect(component.members.map((p) => p.id)).toEqual([12]);

    fail({ status: 422, error: { error: { message: 'Unknown product id(s): 12', details: {} } } });
    expect(component.members.map((p) => p.id)).toEqual([11, 12]);
    expect(component.saving).toBeFalse();
    expect(toast.error).toHaveBeenCalledWith('Unknown product id(s): 12');
  });

  it('retries on 409 CONFLICT_STALE against a fresh read', () => {
    let reads = 0;
    const adapter = fakeAdapter(() => {
      reads++;
      // 1: panel load, 2: first commit read, 3+: another tab added product 14.
      return reads <= 2 ? [product(11, 'Abaya'), product(12, 'Scarf')] : [product(11, 'Abaya'), product(12, 'Scarf'), product(14, 'Bag')];
    });
    const stale = { status: 409, error: { error: { code: 'CONFLICT_STALE', message: 'Changed.' } } };
    let puts = 0;
    adapter.put_v3.and.callFake((_k: string, body: { product_ids: number[] }) => {
      puts++;
      return puts === 1 ? throwError(() => stale) : of({ data: body.product_ids.map((id) => product(id, 'P' + id)) });
    });
    const { component, toast } = makeComponent(adapter);
    component.toggle(component.collections[0]);

    component.remove(component.members[1]);
    expect(adapter.put_v3.calls.count()).toBe(2);
    expect(adapter.put_v3.calls.mostRecent().args[1]).toEqual({ product_ids: [11, 14], expected_product_ids: [11, 12, 14] });
    expect(component.members.map((p) => p.id)).toEqual([11, 14]);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('closing the panel ignores a late save response', () => {
    const adapter = fakeAdapter();
    let done!: (v: unknown) => void;
    adapter.put_v3.and.returnValue(new Observable((sub) => { done = (v) => { sub.next(v); sub.complete(); }; }));
    const { component, toast } = makeComponent(adapter);
    component.toggle(component.collections[0]);
    component.add(product(13, 'Kaftan') as any);
    component.close();
    done({ data: [product(11, 'Abaya')] });
    expect(component.members).toEqual([]);
    expect(toast.success).not.toHaveBeenCalled();
  });
});
