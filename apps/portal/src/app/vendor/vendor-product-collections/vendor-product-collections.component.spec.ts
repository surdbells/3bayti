import { SimpleChange } from '@angular/core';
import { Observable, of, throwError } from 'rxjs';

import { VendorProductCollectionsComponent } from './vendor-product-collections.component';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { I18nService } from '../../i18n.service';

const PRODUCT_ID = 42;
const ref = (id: number, name: string) => ({ id, name, slug: name.toLowerCase(), sort_order: 3 });
const NAMES: Record<number, string> = { 1: 'Summer', 2: 'Eid', 3: 'Gifts', 4: 'Ramadan' };

interface FakeAdapter {
  get_v3: jasmine.Spy;
  put_v3: jasmine.Spy;
}

function fakeAdapter(): FakeAdapter {
  return {
    get_v3: jasmine.createSpy('get_v3').and.callFake((key: string) => {
      if (key === 'GET /vendor/products/:id/collections') return of({ data: [ref(1, 'Summer'), ref(2, 'Eid')] });
      if (key === 'GET /vendor/collections') {
        return of({
          data: [
            { id: 1, name: 'Summer', slug: 'summer', my_product_count: 3 },
            { id: 2, name: 'Eid', slug: 'eid', my_product_count: 1 },
            { id: 3, name: 'Gifts', slug: 'gifts', my_product_count: 0 },
          ],
          meta: { total: 3, limit: 100, offset: 0 },
        });
      }
      return of({ data: [] });
    }),
    put_v3: jasmine.createSpy('put_v3').and.callFake((_key: string, body: { collection_ids: number[] }) =>
      of({ data: body.collection_ids.map((id) => ref(id, NAMES[id] ?? 'C' + id)) }),
    ),
  };
}

const fakeI18n = {
  t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key),
} as unknown as I18nService;

function makeComponent(adapter: FakeAdapter) {
  const toast = jasmine.createSpyObj<HotToastService>('HotToastService', ['success', 'error']);
  const component = new VendorProductCollectionsComponent(adapter as unknown as PortalCrudAdapter, toast, fakeI18n);
  component.productId = PRODUCT_ID;
  component.ngOnChanges({ productId: new SimpleChange(0, PRODUCT_ID, true) });
  return { component, toast };
}

describe('VendorProductCollectionsComponent (logic)', () => {
  it('loads the product\'s collections from the VENDOR endpoint by v3 id', () => {
    const adapter = fakeAdapter();
    const { component } = makeComponent(adapter);
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /vendor/products/:id/collections', { params: { id: '42' } });
    expect(component.collections.map((c) => c.name)).toEqual(['Summer', 'Eid']);
  });

  it('picker options come from GET /vendor/collections (active only, never the admin list)', () => {
    const adapter = fakeAdapter();
    const { component } = makeComponent(adapter);
    component.startEditing();
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /vendor/collections', { query: { limit: 100, offset: 0 } });
    expect(adapter.get_v3.calls.allArgs().some((a) => String(a[0]).startsWith('GET /admin'))).toBeFalse();
    expect(component.options.map((o) => o.label)).toEqual(['Summer', 'Eid', 'Gifts']);
    expect(component.draftIds).toEqual([1, 2]);
  });

  it('saves the complete set with expected_collection_ids via PUT /vendor/products/:id/collections', () => {
    const adapter = fakeAdapter();
    const { component, toast } = makeComponent(adapter);
    component.startEditing();
    component.onDraftChange([1, 2, 3]);
    component.saveDraft();
    expect(adapter.put_v3).toHaveBeenCalledOnceWith(
      'PUT /vendor/products/:id/collections',
      { collection_ids: [1, 2, 3], expected_collection_ids: [1, 2] },
      { params: { id: '42' } },
    );
    const body = adapter.put_v3.calls.mostRecent().args[1];
    expect('collection_id' in body).toBeFalse();
    expect(component.collections.map((c) => c.id)).toEqual([1, 2, 3]);
    expect(component.editing).toBeFalse();
    expect(toast.success).toHaveBeenCalledWith('vendor_collections.collections_saved');
  });

  it('removes one optimistically and rolls back with the API message on failure', () => {
    const adapter = fakeAdapter();
    let fail!: (err: unknown) => void;
    adapter.put_v3.and.returnValue(new Observable((sub) => { fail = (err) => sub.error(err); }));
    const { component, toast } = makeComponent(adapter);

    component.remove(component.collections[0]);
    expect(adapter.put_v3.calls.mostRecent().args[1]).toEqual({ collection_ids: [2], expected_collection_ids: [1, 2] });
    expect(component.collections.map((c) => c.id)).toEqual([2]);
    expect(component.saving).toBeTrue();

    fail({ status: 422, error: { error: { message: 'Unknown collection id(s): 2' } } });
    expect(component.collections.map((c) => c.id)).toEqual([1, 2]);
    expect(component.saving).toBeFalse();
    expect(toast.error).toHaveBeenCalledWith('Unknown collection id(s): 2');
  });

  it('retries on 409 CONFLICT_STALE with a fresh read, keeping the change made elsewhere', () => {
    const adapter = fakeAdapter();
    const { component, toast } = makeComponent(adapter);
    const stale = { status: 409, error: { error: { code: 'CONFLICT_STALE', message: 'Changed elsewhere.' } } };
    let reads = 0;
    adapter.get_v3.and.callFake(() => {
      reads++;
      return of({ data: reads === 1 ? [ref(1, 'Summer'), ref(2, 'Eid')] : [ref(1, 'Summer'), ref(2, 'Eid'), ref(4, 'Ramadan')] });
    });
    let puts = 0;
    adapter.put_v3.and.callFake((_key: string, body: { collection_ids: number[] }) => {
      puts++;
      return puts === 1 ? throwError(() => stale) : of({ data: body.collection_ids.map((id) => ref(id, 'C' + id)) });
    });

    component.remove(component.collections[0]);

    expect(adapter.put_v3.calls.count()).toBe(2);
    expect(adapter.put_v3.calls.mostRecent().args[1]).toEqual({ collection_ids: [2, 4], expected_collection_ids: [1, 2, 4] });
    expect(component.collections.map((c) => c.id)).toEqual([2, 4]);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('shows the API message when the product cannot be loaded (foreign / unknown → 404)', () => {
    const adapter = fakeAdapter();
    adapter.get_v3.and.returnValue(throwError(() => ({ status: 404, error: { error: { message: 'Product not found.' } } })));
    const { component } = makeComponent(adapter);
    expect(component.loading).toBeFalse();
    expect(component.loadError).toBe('Product not found.');
  });
});
