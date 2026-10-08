import { SimpleChange } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Observable, of, throwError } from 'rxjs';

import { ProductCollectionsComponent } from './product-collections.component';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { PermissionService } from '../../../services/permission.service';
import { HotToastService } from '../../../shared/toast/toast.service';

const PRODUCT_ID = 42;
const ref = (id: number, name: string, is_active = true, sort_order: number | null = 0) => ({ id, name, slug: name.toLowerCase(), is_active, sort_order });

interface FakeAdapter {
  get_v3: jasmine.Spy;
  put_v3: jasmine.Spy;
}

function fakeAdapter(): FakeAdapter {
  return {
    get_v3: jasmine.createSpy('get_v3').and.callFake((key: string) => {
      if (key === 'GET /admin/products/:id/collections') return of({ data: [ref(1, 'Summer'), ref(2, 'Eid', true, 5)] });
      if (key === 'GET /admin/collections') {
        return of({
          data: [
            { id: 1, name: 'Summer', slug: 'summer', is_active: true, display_order: 0, product_count: 3 },
            { id: 2, name: 'Eid', slug: 'eid', is_active: true, display_order: 1, product_count: 8 },
            { id: 3, name: 'Gifts', slug: 'gifts', is_active: false, display_order: 2, product_count: 0 },
          ],
          meta: { total: 3 },
        });
      }
      return of({ data: [] });
    }),
    put_v3: jasmine.createSpy('put_v3').and.callFake((_key: string, body: { collection_ids: number[] }) =>
      of({ data: body.collection_ids.map((id) => ref(id, id === 3 ? 'Gifts' : id === 2 ? 'Eid' : 'Summer', id !== 3, 9)) }),
    ),
  };
}

function makeComponent(adapter: FakeAdapter, canManage = true) {
  const toast = jasmine.createSpyObj<HotToastService>('HotToastService', ['success', 'error']);
  const component = new ProductCollectionsComponent(
    adapter as unknown as PortalCrudAdapter,
    { can: (p: string) => canManage || p !== 'catalog.collections_manage' } as unknown as PermissionService,
    toast,
  );
  component.productId = PRODUCT_ID;
  component.ngOnChanges({ productId: new SimpleChange(0, PRODUCT_ID, true) });
  return { component, toast };
}

describe('ProductCollectionsComponent (logic)', () => {
  it('loads the product\'s collections by v3 id (contract C)', () => {
    const adapter = fakeAdapter();
    const { component } = makeComponent(adapter);
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /admin/products/:id/collections', { params: { id: '42' } });
    expect(component.collections.map((c) => c.name)).toEqual(['Summer', 'Eid']);
  });

  it('adds the product to more collections: PUTs the complete set (contract D)', () => {
    const adapter = fakeAdapter();
    const { component, toast } = makeComponent(adapter);
    component.startEditing();
    expect(component.allCollections?.length).toBe(3);
    expect(component.draftIds).toEqual([1, 2]);

    component.onDraftChange([1, 2, 3]);
    component.saveDraft();
    expect(adapter.put_v3).toHaveBeenCalledOnceWith(
      'PUT /admin/products/:id/collections',
      { collection_ids: [1, 2, 3], expected_collection_ids: [1, 2] },
      { params: { id: '42' } },
    );
    expect(component.collections.map((c) => c.id)).toEqual([1, 2, 3]);
    expect(component.editing).toBeFalse();
    expect(toast.success).toHaveBeenCalledWith('Added to 1 collection.');
  });

  it('removes one collection optimistically and rolls back with the API message on failure', () => {
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

  it('removing from every collection sends an empty set', () => {
    const adapter = fakeAdapter();
    const { component } = makeComponent(adapter);
    component.startEditing();
    component.onDraftChange([]);
    component.saveDraft();
    expect(adapter.put_v3.calls.mostRecent().args[1]).toEqual({ collection_ids: [], expected_collection_ids: [1, 2] });
    expect(component.collections).toEqual([]);
  });

  it('applies only this change on top of the stored set, keeping memberships changed elsewhere', () => {
    const adapter = fakeAdapter();
    const { component } = makeComponent(adapter); // loaded [1, 2]
    // Since then, collection 4's builder added this product there.
    adapter.get_v3.and.returnValue(of({ data: [ref(1, 'Summer'), ref(2, 'Eid'), ref(4, 'Ramadan')] }));

    component.remove(component.collections[0]);
    expect(adapter.put_v3.calls.mostRecent().args[1]).toEqual({ collection_ids: [2, 4], expected_collection_ids: [1, 2, 4] });
    // The response (the stored set) becomes the chips, so 4 shows up too.
    expect(component.collections.map((c) => c.id)).toEqual([2, 4]);
  });

  it('retries on 409 CONFLICT_STALE with a fresh read, keeping the change made elsewhere', () => {
    const adapter = fakeAdapter();
    const { component, toast } = makeComponent(adapter); // loaded [1, 2]
    const stale = { status: 409, error: { error: { code: 'CONFLICT_STALE', message: 'Changed elsewhere.' } } };
    let reads = 0;
    adapter.get_v3.and.callFake(() => {
      reads++;
      // First read [1, 2]; another writer then adds collection 4 before our PUT lands.
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

  it('does not remove from a chip while the picker is open (the draft would re-add it)', () => {
    const adapter = fakeAdapter();
    const { component } = makeComponent(adapter);
    component.startEditing();
    component.remove(component.collections[0]);
    expect(adapter.put_v3).not.toHaveBeenCalled();
    expect(component.collections.map((c) => c.id)).toEqual([1, 2]);
  });

  it('a save still running for the previous product never leaves the next one stuck "saving"', () => {
    const adapter = fakeAdapter();
    let finish!: (res: unknown) => void;
    adapter.put_v3.and.returnValue(new Observable((sub) => { finish = (res) => { sub.next(res); sub.complete(); }; }));
    const { component } = makeComponent(adapter);
    component.remove(component.collections[0]);
    expect(component.saving).toBeTrue();

    // The page moves on to product 43 while product 42's save is in flight.
    component.productId = 43;
    component.ngOnChanges({ productId: new SimpleChange(PRODUCT_ID, 43, false) });
    expect(component.saving).toBeFalse();
    const forNext = component.collections;

    // 42's late answer is not applied to 43.
    finish({ data: [ref(2, 'Eid')] });
    expect(component.collections).toBe(forNext);
    expect(component.saving).toBeFalse();
  });

  it('cannot edit without catalog.collections_manage', () => {
    const adapter = fakeAdapter();
    const { component } = makeComponent(adapter, false);
    component.startEditing();
    component.remove(component.collections[0]);
    expect(component.editing).toBeFalse();
    expect(adapter.put_v3).not.toHaveBeenCalled();
  });
});

describe('ProductCollectionsComponent (DOM)', () => {
  let fixture: ComponentFixture<ProductCollectionsComponent>;

  it('lists the product\'s collections as chips linking to their editors', async () => {
    await TestBed.configureTestingModule({
      imports: [ProductCollectionsComponent],
      providers: [
        provideRouter([]),
        { provide: PortalCrudAdapter, useValue: fakeAdapter() },
        { provide: PermissionService, useValue: { can: () => true } },
        { provide: HotToastService, useValue: jasmine.createSpyObj('HotToastService', ['success', 'error']) },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(ProductCollectionsComponent);
    fixture.componentRef.setInput('productId', PRODUCT_ID);
    fixture.detectChanges();

    const chips = (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLAnchorElement>('.pc-chip__link');
    expect(Array.from(chips).map((a) => a.textContent?.trim())).toEqual(['Summer', 'Eid']);
    expect(chips[0].getAttribute('href')).toBe('/admin/collections/edit?id=1');
  });
});
