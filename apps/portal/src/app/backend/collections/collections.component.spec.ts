import { Component, ElementRef } from '@angular/core';
import { ComponentFixture, TestBed, fakeAsync, flush, tick } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { CdkDragDrop } from '@angular/cdk/drag-drop';
import { Observable, Subject, of, throwError } from 'rxjs';

import { CollectionsComponent } from './collections.component';
import { CollectionRow } from './collection-list.api';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { PermissionService } from '../../services/permission.service';
import { HotToastService } from '../../shared/toast/toast.service';
import { AxConfirmService } from '../../shared/overlays/ax-confirm.service';
import { AxExportService } from '../../shared/data/enterprise';

const apiCollection = (id: number, name: string, display_order: number | null, product_count = 0) => ({
  id, name, collection: name, slug: name.toLowerCase(), description: null, cover_image_url: null,
  is_active: true, display_order, product_count, created_at: '2026-10-01T00:00:00+00:00',
});

/** Storefront order: A, B, C. */
const LIST = [apiCollection(1, 'A', 0, 4), apiCollection(2, 'B', 1, 0), apiCollection(3, 'C', 2, 12)];

interface FakeAdapter {
  get_v3: jasmine.Spy;
  put_v3: jasmine.Spy;
  delete_v3: jasmine.Spy;
}

function fakeAdapter(): FakeAdapter {
  return {
    get_v3: jasmine.createSpy('get_v3').and.returnValue(of({ data: LIST, meta: { total: LIST.length, limit: 100, offset: 0 } })),
    put_v3: jasmine.createSpy('put_v3').and.callFake((_key: string, body: { collection_ids: number[] }) =>
      of({ data: body.collection_ids.map((id, i) => ({ ...LIST.find((c) => c.id === id)!, display_order: i })) }),
    ),
    delete_v3: jasmine.createSpy('delete_v3').and.returnValue(of(null)),
  };
}

const drop = (previousIndex: number, currentIndex: number) =>
  ({ previousIndex, currentIndex } as CdkDragDrop<CollectionRow[]>);

function makeComponent(adapter: FakeAdapter, canManage = true, host: HTMLElement = document.createElement('div')) {
  const toast = jasmine.createSpyObj<HotToastService>('HotToastService', ['success', 'error']);
  const component = new CollectionsComponent(
    jasmine.createSpyObj<Router>('Router', ['navigate']),
    jasmine.createSpyObj<NavigationHistoryService>('NavigationHistoryService', ['back']),
    adapter as unknown as PortalCrudAdapter,
    toast,
    jasmine.createSpyObj<AxConfirmService>('AxConfirmService', { confirm: Promise.resolve(true) }),
    { can: (p: string) => canManage || p !== 'catalog.collections_manage' } as unknown as PermissionService,
    jasmine.createSpyObj<AxExportService>('AxExportService', { export: Promise.resolve() }),
    new ElementRef(host),
  );
  return { component, toast };
}

describe('CollectionsComponent (logic)', () => {
  it('loads every collection in storefront order (paging with limit 100)', () => {
    const adapter = fakeAdapter();
    const pageOf = (from: number, count: number) =>
      Array.from({ length: count }, (_, i) => apiCollection(from + i, `C${from + i}`, from + i - 1));
    adapter.get_v3.and.callFake((_key: string, opts: any) =>
      of(opts.query.offset === 0
        ? { data: pageOf(1, 100), meta: { total: 130 } }
        : { data: pageOf(101, 30), meta: { total: 130 } }),
    );
    const { component } = makeComponent(adapter);
    component.ngOnInit();
    expect(adapter.get_v3.calls.allArgs().map(([, o]) => o.query)).toEqual([
      { limit: 100, offset: 0 },
      { limit: 100, offset: 100 },
    ]);
    expect(component.rows.length).toBe(130);
    expect(component.rows[0].name).toBe('C1');
  });

  it('saves the FULL id order after a drop (contract B)', () => {
    const adapter = fakeAdapter();
    const { component } = makeComponent(adapter);
    component.ngOnInit();

    component.onDrop(drop(2, 0));
    expect(adapter.put_v3).toHaveBeenCalledOnceWith('PUT /admin/collections/order', { collection_ids: [3, 1, 2] });
    expect(component.rows.map((r) => r.id)).toEqual([3, 1, 2]);
    expect(component.rows.map((r) => r.display_order)).toEqual([0, 1, 2]);
    expect(component.saving).toBeFalse();
  });

  it('reorders optimistically, rolls back to the last saved order on failure, and reloads a rejected list', () => {
    const adapter = fakeAdapter();
    let fail!: (err: unknown) => void;
    adapter.put_v3.and.returnValue(new Observable((sub) => { fail = (err) => sub.error(err); }));
    const { component, toast } = makeComponent(adapter);
    component.ngOnInit();

    component.onDrop(drop(0, 2));
    // Optimistic: already moved.
    expect(component.rows.map((r) => r.id)).toEqual([2, 3, 1]);
    expect(component.saving).toBeTrue();
    // A move made meanwhile applies at once and waits for the save to land.
    component.moveUp(1);
    expect(component.rows.map((r) => r.id)).toEqual([3, 2, 1]);
    expect(adapter.put_v3).toHaveBeenCalledTimes(1);

    adapter.get_v3.calls.reset();
    fail({ status: 422, error: { error: { code: 'VALIDATION', message: 'Unknown collection id(s): 1' } } });
    expect(component.rows.map((r) => r.id)).toEqual([1, 2, 3]);
    expect(component.saving).toBeFalse();
    expect(toast.error).toHaveBeenCalledWith('Unknown collection id(s): 1');
    // 422 = this page's list is stale (e.g. a collection was deleted elsewhere): reload it.
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /admin/collections', jasmine.anything());
    // The queued move was dropped with the failed save (nothing more sent).
    expect(adapter.put_v3).toHaveBeenCalledTimes(1);
  });

  it('does not reload after a network failure (nothing stale to fix)', () => {
    const adapter = fakeAdapter();
    adapter.put_v3.and.returnValue(throwError(() => ({ status: 0 })));
    const { component } = makeComponent(adapter);
    component.ngOnInit();
    adapter.get_v3.calls.reset();
    component.onDrop(drop(0, 1));
    expect(component.rows.map((r) => r.id)).toEqual([1, 2, 3]);
    expect(adapter.get_v3).not.toHaveBeenCalled();
  });

  it('moves with the keyboard (arrow keys on the handle, and the move buttons)', () => {
    const adapter = fakeAdapter();
    const { component } = makeComponent(adapter);
    component.ngOnInit();

    const down = new KeyboardEvent('keydown', { key: 'ArrowDown' });
    component.onHandleKeydown(down, 0);
    expect(adapter.put_v3.calls.mostRecent().args[1]).toEqual({ collection_ids: [2, 1, 3] });

    component.moveUp(0); // already first: nothing to do
    expect(adapter.put_v3).toHaveBeenCalledTimes(1);
    component.moveDown(1);
    expect(adapter.put_v3.calls.mostRecent().args[1]).toEqual({ collection_ids: [2, 3, 1] });
  });

  it('is read-only without catalog.collections_manage', () => {
    const adapter = fakeAdapter();
    const { component } = makeComponent(adapter, false);
    component.ngOnInit();
    expect(component.canReorder).toBeFalse();
    component.onDrop(drop(0, 1));
    component.moveDown(0);
    expect(adapter.put_v3).not.toHaveBeenCalled();
  });

  it('pauses reordering while the list is filtered', () => {
    const adapter = fakeAdapter();
    const { component } = makeComponent(adapter);
    component.ngOnInit();
    component.search = 'b';
    expect(component.visibleRows.map((r) => r.id)).toEqual([2]);
    expect(component.canReorder).toBeFalse();
    component.moveUp(0);
    expect(adapter.put_v3).not.toHaveBeenCalled();
  });

  it('queues moves made while a save is in flight and saves the latest full order once it lands', () => {
    const adapter = fakeAdapter();
    const first = new Subject<unknown>();
    const echo = (ids: number[]) => ({ data: ids.map((id, i) => ({ ...LIST.find((c) => c.id === id)!, display_order: i })) });
    let calls = 0;
    adapter.put_v3.and.callFake((_key: string, body: { collection_ids: number[] }) =>
      ++calls === 1 ? first : of(echo(body.collection_ids)),
    );
    const { component } = makeComponent(adapter);
    component.ngOnInit();

    component.onDrop(drop(0, 1)); // [2, 1, 3], saving
    component.onDrop(drop(1, 2)); // [2, 3, 1], queued
    component.onHandleKeydown(new KeyboardEvent('keydown', { key: 'ArrowUp' }), 2); // [2, 1, 3], queued
    expect(adapter.put_v3).toHaveBeenCalledTimes(1);
    expect(component.rows.map((r) => r.id)).toEqual([2, 1, 3]);

    component.onDrop(drop(2, 0)); // [3, 2, 1], queued
    first.next(echo([2, 1, 3]));
    first.complete();

    // The queued moves are saved together, as one full order.
    expect(adapter.put_v3).toHaveBeenCalledTimes(2);
    expect(adapter.put_v3.calls.mostRecent().args[1]).toEqual({ collection_ids: [3, 2, 1] });
    expect(component.rows.map((r) => r.id)).toEqual([3, 2, 1]);
    expect(component.saving).toBeFalse();
  });

  it('keeps focus on the control that moved the row (Move down button, or the handle)', fakeAsync(() => {
    const adapter = fakeAdapter();
    const host = document.createElement('div');
    host.innerHTML = [1, 2, 3]
      .map((id) => `<div data-collection-id="${id}"><button data-focus="handle"></button><button data-focus="up"></button><button data-focus="down"></button></div>`)
      .join('');
    document.body.appendChild(host);
    const { component } = makeComponent(adapter, true, host);
    component.ngOnInit();

    component.moveDown(0);
    tick();
    expect(document.activeElement).toBe(host.querySelector('[data-collection-id="1"] [data-focus="down"]'));

    component.onHandleKeydown(new KeyboardEvent('keydown', { key: 'ArrowUp' }), 1);
    tick();
    expect(document.activeElement).toBe(host.querySelector('[data-collection-id="1"] [data-focus="handle"]'));
    flush();
    host.remove();
  }));

  it('holds deletes while an order save is in flight', () => {
    const adapter = fakeAdapter();
    adapter.put_v3.and.returnValue(new Observable(() => undefined));
    const { component, toast } = makeComponent(adapter);
    component.ngOnInit();
    component.onDrop(drop(0, 1));
    component.confirmDelete(component.rows[0]);
    expect(toast.error).toHaveBeenCalled();
    expect(adapter.delete_v3).not.toHaveBeenCalled();
  });

  it('surfaces a load failure with the API message', () => {
    const adapter = fakeAdapter();
    adapter.get_v3.and.returnValue(throwError(() => ({ status: 403, error: { error: { message: 'Forbidden' } } })));
    const { component } = makeComponent(adapter);
    component.ngOnInit();
    expect(component.loadError).toBe('Forbidden');
    expect(component.rows).toEqual([]);
  });
});

@Component({ selector: 'app-admin-shell', standalone: true, template: '<ng-content></ng-content>' })
class AdminShellStubComponent {}

describe('CollectionsComponent (DOM)', () => {
  let fixture: ComponentFixture<CollectionsComponent>;

  const setup = async (canManage: boolean) => {
    await TestBed.configureTestingModule({
      imports: [CollectionsComponent],
      providers: [
        provideRouter([]),
        { provide: PortalCrudAdapter, useValue: fakeAdapter() },
        { provide: PermissionService, useValue: { can: (p: string) => canManage || p !== 'catalog.collections_manage' } },
        { provide: HotToastService, useValue: jasmine.createSpyObj('HotToastService', ['success', 'error']) },
        { provide: AxConfirmService, useValue: jasmine.createSpyObj('AxConfirmService', ['confirm']) },
        { provide: NavigationHistoryService, useValue: { back: () => undefined } },
      ],
    })
      .overrideComponent(CollectionsComponent, {
        remove: { imports: [AdminShellComponent] },
        add: { imports: [AdminShellStubComponent] },
      })
      .compileComponents();
    fixture = TestBed.createComponent(CollectionsComponent);
    fixture.detectChanges();
  };

  it('renders an ordered list with drag handles, product counts and status for managers', async () => {
    await setup(true);
    const rows = Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('.cl-row'));
    expect(rows.length).toBe(3);
    expect(rows[0].querySelector('.cl-handle')).not.toBeNull();
    expect(rows[2].querySelector('.cl-count')?.textContent).toContain('12');
    expect(rows[0].querySelector('.ax-badge')?.textContent).toContain('Active');
  });

  it('shows no drag handles or actions for view-only admins', async () => {
    await setup(false);
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelectorAll('.cl-row').length).toBe(3);
    expect(el.querySelector('.cl-handle')).toBeNull();
    expect(el.querySelector('.cl-actions')).toBeNull();
  });
});
