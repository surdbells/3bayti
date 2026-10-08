import { Component, ElementRef } from '@angular/core';
import { ComponentFixture, TestBed, fakeAsync, flush, tick } from '@angular/core/testing';
import { ActivatedRoute, Router, convertToParamMap, provideRouter } from '@angular/router';
import { CdkDragDrop } from '@angular/cdk/drag-drop';
import { Observable, of, throwError } from 'rxjs';

import { EditCollectionComponent } from './edit-collection.component';
import { CuratedProduct } from './collection-builder.model';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { NavigationHistoryService } from '../../../services/navigation-history.service';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { PermissionService } from '../../../services/permission.service';
import { HotToastService } from '../../../shared/toast/toast.service';
import { AxConfirmService } from '../../../shared/overlays/ax-confirm.service';

/** This collection's id in every test. */
const COLLECTION_ID = 7;

const listProduct = (id: number, name = `Product ${id}`) => ({
  id,
  slug: `product-${id}`,
  name,
  price: { amount: 100 + id, currency: 'AED' },
  sale_price: null,
  primary_image: { url: `https://img.test/${id}.jpg` },
  images: [{ url: `https://img.test/${id}.jpg` }],
  vendor: { name: 'Almas' },
  in_stock: true,
});

/**
 * Contract A fixture: product 1 is in THIS collection and in "Eid edit";
 * product 2 only in this one; product 3 in two others.
 */
const MEMBERSHIPS: Record<string, unknown[]> = {
  '1': [
    { id: COLLECTION_ID, name: 'Summer', slug: 'summer', is_active: true },
    { id: 9, name: 'Eid edit', slug: 'eid', is_active: true },
  ],
  '2': [{ id: COLLECTION_ID, name: 'Summer', slug: 'summer', is_active: true }],
  '3': [
    { id: 9, name: 'Eid edit', slug: 'eid', is_active: true },
    { id: 11, name: 'Gifts', slug: 'gifts', is_active: false },
  ],
};

interface FakeAdapter {
  get_v3: jasmine.Spy;
  put_v3: jasmine.Spy;
}

function fakeAdapter(): FakeAdapter {
  return {
    get_v3: jasmine.createSpy('get_v3').and.callFake((key: string, opts?: any): Observable<unknown> => {
      switch (key) {
        case 'GET /admin/collections/:id':
          return of({ data: { id: COLLECTION_ID, name: 'Summer', slug: 'summer', is_active: true, display_order: 2 } });
        case 'GET /admin/collections/:id/products':
          return of({ data: [listProduct(1), listProduct(2)] });
        case 'GET /admin/collections/memberships': {
          const ids = String(opts?.query?.product_ids ?? '').split(',');
          return of({ data: Object.fromEntries(ids.map((id) => [id, MEMBERSHIPS[id] ?? []])) });
        }
        case 'GET /products':
          return of({ data: [listProduct(1), listProduct(3)], meta: { total: 2 } });
        case 'GET /admin/products/:id':
          return of({ data: { id: Number(opts?.params?.id), name: 'Product detail', description: '<p>Hand made</p>', category: 'Abayas', stock_status: 'in_stock', stock_quantity: 4, images: [] } });
        default:
          return of({ data: [] });
      }
    }),
    put_v3: jasmine.createSpy('put_v3').and.callFake((key: string, body: any) =>
      key === 'PUT /admin/collections/:id'
        ? of({ data: { id: COLLECTION_ID, slug: 'summer', display_order: 2, ...body } })
        : of({ data: (body?.product_ids ?? []).map((id: number) => listProduct(id)) }),
    ),
  };
}

const membershipCalls = (adapter: FakeAdapter): string[] =>
  adapter.get_v3.calls
    .allArgs()
    .filter(([key]) => key === 'GET /admin/collections/memberships')
    .map(([, opts]) => String(opts.query.product_ids));

const toastStub = () => jasmine.createSpyObj<HotToastService>('HotToastService', ['success', 'error']);
const confirmStub = () => jasmine.createSpyObj<AxConfirmService>('AxConfirmService', { confirm: Promise.resolve(true) });
const routeStub = () => ({ queryParamMap: of(convertToParamMap({ id: String(COLLECTION_ID) })), snapshot: { queryParamMap: convertToParamMap({ id: String(COLLECTION_ID) }) } });

// ─────────────────────────────────────────────────────────────────────────
// Logic (no template compile): curation, memberships, reorder, save.
// ─────────────────────────────────────────────────────────────────────────
describe('EditCollectionComponent (logic)', () => {
  let adapter: FakeAdapter;
  let component: EditCollectionComponent;

  beforeEach(() => {
    adapter = fakeAdapter();
    component = new EditCollectionComponent(
      routeStub() as unknown as ActivatedRoute,
      jasmine.createSpyObj<Router>('Router', ['navigate']),
      jasmine.createSpyObj<NavigationHistoryService>('NavigationHistoryService', ['back']),
      adapter as unknown as PortalCrudAdapter,
      toastStub(),
      confirmStub(),
      { can: () => true } as unknown as PermissionService,
      new ElementRef(document.createElement('div')),
    );
    component.ngOnInit();
  });

  afterEach(() => component.ngOnDestroy());

  it('loads the curated list and fetches its memberships in ONE batched request', () => {
    expect(component.items.map((p) => p.id)).toEqual([1, 2]);
    expect(membershipCalls(adapter)).toEqual(['1,2']);
    // Other collections exclude the current one.
    expect(component.othersFor(1)?.map((c) => c.name)).toEqual(['Eid edit']);
    expect(component.othersFor(2)).toEqual([]);
  });

  it('only asks for memberships of grid products it does not know yet', () => {
    component.selectAddTab('new_arrivals');
    expect(component.browse.items.map((p) => p.id)).toEqual([1, 3]);
    expect(membershipCalls(adapter)).toEqual(['1,2', '3']);
    expect(component.othersFor(3)?.length).toBe(2);
  });

  it('lets a product that is in other collections be added (multi-collection)', () => {
    component.selectAddTab('new_arrivals');
    const p3 = component.browse.items.find((p) => p.id === 3)!;
    component.toggleProduct(p3);
    expect(component.isAdded(3)).toBeTrue();
    expect(component.productsDirty).toBeTrue();
    component.toggleProduct(p3);
    expect(component.isAdded(3)).toBeFalse();
  });

  it('reorders the curated list on drop and makes the moved product the cover', () => {
    component.onCuratedDrop({ previousIndex: 1, currentIndex: 0 } as CdkDragDrop<CuratedProduct[]>);
    expect(component.items.map((p) => p.id)).toEqual([2, 1]);
    expect(component.productsDirty).toBeTrue();
  });

  it('saves the curated order, then refreshes memberships for the cards on screen', () => {
    component.moveDown(0);
    component.saveChanges();
    expect(adapter.put_v3).toHaveBeenCalledWith(
      'PUT /admin/collections/:id/products',
      { product_ids: [2, 1], expected_product_ids: [1, 2] },
      { params: { id: String(COLLECTION_ID) } },
    );
    expect(component.productsDirty).toBeFalse();
    expect(membershipCalls(adapter).length).toBe(2);
    // display_order is no longer edited here, so details are not PUT.
    expect(adapter.put_v3).not.toHaveBeenCalledWith('PUT /admin/collections/:id', jasmine.anything(), jasmine.anything());
  });

  it('never sends display_order when the details are saved', () => {
    component.form.name = 'Summer edit';
    component.saveChanges();
    const call = adapter.put_v3.calls.allArgs().find(([key]) => key === 'PUT /admin/collections/:id');
    expect(call?.[1]).toEqual({ name: 'Summer edit', description: null, is_active: true });
  });

  it('keeps curation locked (and the list intact) while a save is in flight', () => {
    adapter.put_v3.and.returnValue(new Observable(() => undefined));
    component.moveDown(0);
    component.saveChanges();
    component.removeItem(0);
    expect(component.items.map((p) => p.id)).toEqual([2, 1]);
  });

  it('reports a failed save and keeps the unsaved list', () => {
    const toast = (component as any).toast as jasmine.SpyObj<HotToastService>;
    adapter.put_v3.and.returnValue(throwError(() => ({ status: 422, error: { error: { message: 'Unknown product id(s): 2' } } })));
    component.moveDown(0);
    component.saveChanges();
    expect(toast.error).toHaveBeenCalledWith('Unknown product id(s): 2');
    expect(component.productsDirty).toBeTrue();
  });

  it('re-reads the stored list before saving products, and saves when nothing changed elsewhere', () => {
    component.moveDown(0);
    adapter.get_v3.calls.reset();
    component.saveChanges();
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /admin/collections/:id/products', { params: { id: String(COLLECTION_ID) } });
    expect(adapter.put_v3).toHaveBeenCalledWith('PUT /admin/collections/:id/products', { product_ids: [2, 1], expected_product_ids: [1, 2] }, jasmine.anything());
    expect(component.mergeNotice).toBe('');
  });

  it('merges products changed elsewhere instead of overwriting them (no PUT; review first)', () => {
    // Loaded [1, 2]. Meanwhile, elsewhere: product 9 added, product 1 removed.
    const base = fakeAdapter().get_v3;
    adapter.get_v3.and.callFake((key: string, opts?: any) =>
      key === 'GET /admin/collections/:id/products'
        ? of({ data: [listProduct(2), listProduct(9)] })
        : base(key, opts),
    );
    // This page: add product 3, then save.
    component.addProduct({ id: 3, slug: 'p3', name: 'Product 3', image: null, images: [], price: 1, sale_price: null, vendor_name: null, in_stock: true });
    component.saveChanges();

    expect(adapter.put_v3).not.toHaveBeenCalled();
    // Their removal (1) and addition (9) are folded in; this page's add (3) is kept.
    expect(component.items.map((p) => p.id)).toEqual([2, 3, 9]);
    // The stored list is the new baseline: 9 counts as saved, 3 as unsaved.
    expect(component.isSaved(9)).toBeTrue();
    expect(component.isSaved(3)).toBeFalse();
    expect(component.productsDirty).toBeTrue();
    expect(component.mergeNotice).toContain('1 product added, 1 removed');
    expect(component.savingInFlight).toBeFalse();

    // Saving again now sends the merged list.
    adapter.get_v3.and.callFake((key: string, opts?: any) =>
      key === 'GET /admin/collections/:id/products' ? of({ data: [listProduct(2), listProduct(9)] }) : base(key, opts),
    );
    component.saveChanges();
    expect(adapter.put_v3).toHaveBeenCalledWith('PUT /admin/collections/:id/products', { product_ids: [2, 3, 9], expected_product_ids: [2, 9] }, jasmine.anything());
    expect(component.mergeNotice).toBe('');
  });

  it('merges (no error toast) when the API rejects the products PUT as stale (409 CONFLICT_STALE)', () => {
    const toast = (component as any).toast as jasmine.SpyObj<HotToastService>;
    const base = fakeAdapter().get_v3;
    let reads = 0;
    // The pre-save read still matches the loaded [1, 2]; product 9 is added
    // elsewhere between that read and the PUT, so the API answers 409.
    adapter.get_v3.and.callFake((key: string, opts?: any) => {
      if (key !== 'GET /admin/collections/:id/products') return base(key, opts);
      reads++;
      return of({ data: reads === 1 ? [listProduct(1), listProduct(2)] : [listProduct(1), listProduct(2), listProduct(9)] });
    });
    adapter.put_v3.and.callFake((key: string) =>
      key === 'PUT /admin/collections/:id/products'
        ? throwError(() => ({ status: 409, error: { error: { code: 'CONFLICT_STALE', message: 'Changed elsewhere.' } } }))
        : of({ data: null }),
    );
    adapter.get_v3.calls.reset();
    component.moveDown(0);
    component.saveChanges();

    expect(toast.error).not.toHaveBeenCalled();
    expect(component.items.map((p) => p.id)).toEqual([2, 1, 9]);
    expect(component.isSaved(9)).toBeTrue();
    expect(component.mergeNotice).toContain('1 product added');
    expect(component.savingInFlight).toBeFalse();
  });

  it('ignores a save response that lands after the page moved to another collection', () => {
    let finish!: (res: unknown) => void;
    adapter.put_v3.and.callFake(() => new Observable((sub) => { finish = (res) => { sub.next(res); sub.complete(); }; }));
    component.moveDown(0);
    component.saveChanges();

    // The page is re-pointed at collection 8 (its products: [5]).
    adapter.get_v3.and.callFake((key: string) => {
      if (key === 'GET /admin/collections/:id/products') return of({ data: [listProduct(5)] });
      if (key === 'GET /admin/collections/:id') return of({ data: { id: 8, name: 'Gifts', slug: 'gifts', is_active: true } });
      return of({ data: {} });
    });
    (component as any).openCollection('8');
    expect(component.items.map((p) => p.id)).toEqual([5]);

    // Collection 7's PUT response arrives late: it must not become 8's list.
    finish({ data: [listProduct(2), listProduct(1)] });
    expect(component.collectionId).toBe(8);
    expect(component.items.map((p) => p.id)).toEqual([5]);
    expect(component.productsDirty).toBeFalse();
    expect(component.savingInFlight).toBeFalse();
  });

  it('blocks leaving while a save is in flight (no prompt, the save keeps its page)', () => {
    const toast = (component as any).toast as jasmine.SpyObj<HotToastService>;
    const confirm = (component as any).confirm as jasmine.SpyObj<AxConfirmService>;
    adapter.put_v3.and.returnValue(new Observable(() => undefined));
    component.moveDown(0);
    component.saveChanges();
    expect(component.canDeactivate()).toBeFalse();
    expect(toast.error).toHaveBeenCalled();
    expect(confirm.confirm).not.toHaveBeenCalled();
  });

  it('closes the details panel before asking to leave with unsaved changes', async () => {
    const confirm = (component as any).confirm as jasmine.SpyObj<AxConfirmService>;
    component.moveDown(0);
    component.openPanel(component.items[0]);
    expect(component.panel).not.toBeNull();
    const answer = component.canDeactivate();
    expect(component.panel).toBeNull();
    expect(confirm.confirm).toHaveBeenCalled();
    expect(await answer).toBeTrue();
  });

  it('flags cards whose memberships lookup failed, and retries them', () => {
    adapter.get_v3.and.callFake((key: string, opts?: any) =>
      key === 'GET /admin/collections/memberships'
        ? throwError(() => ({ status: 403, error: { error: { message: 'Forbidden' } } }))
        : fakeAdapter().get_v3(key, opts),
    );
    (component as any).openCollection(String(COLLECTION_ID));
    expect(component.othersFor(1)).toBeUndefined();
    expect(component.memberships.isUnknown(1)).toBeTrue();
    expect(component.membershipFailures).toBe(2);
    expect(component.membershipErrorText).toContain('catalog.collections_view');

    // The lookup works again: Retry fills the cards in.
    const healthy = fakeAdapter();
    adapter.get_v3.and.callFake((key: string, opts?: any) => healthy.get_v3(key, opts));
    component.retryMemberships();
    expect(component.membershipFailures).toBe(0);
    expect(component.othersFor(1)?.map((c) => c.name)).toEqual(['Eid edit']);
  });

  it('opening the panel retries a product whose lookup failed', () => {
    adapter.get_v3.and.callFake((key: string, opts?: any) =>
      key === 'GET /admin/collections/memberships' ? throwError(() => new Error('down')) : fakeAdapter().get_v3(key, opts),
    );
    (component as any).openCollection(String(COLLECTION_ID));
    expect(component.memberships.isUnknown(2)).toBeTrue();

    const healthy = fakeAdapter();
    adapter.get_v3.and.callFake((key: string, opts?: any) => healthy.get_v3(key, opts));
    component.openPanel(component.items[1]);
    expect(component.memberships.isUnknown(2)).toBeFalse();
    expect(component.othersFor(2)).toEqual([]);
  });
});

// ─────────────────────────────────────────────────────────────────────────
// DOM: indicators + details panel (needs the TestBed template compiler).
// ─────────────────────────────────────────────────────────────────────────
@Component({ selector: 'app-admin-shell', standalone: true, template: '<ng-content></ng-content>' })
class AdminShellStubComponent {}

describe('EditCollectionComponent (DOM)', () => {
  let fixture: ComponentFixture<EditCollectionComponent>;
  let adapter: FakeAdapter;
  let el: HTMLElement;

  beforeEach(async () => {
    adapter = fakeAdapter();
    await TestBed.configureTestingModule({
      imports: [EditCollectionComponent],
      providers: [
        provideRouter([]),
        { provide: ActivatedRoute, useValue: routeStub() },
        { provide: PortalCrudAdapter, useValue: adapter },
        { provide: PermissionService, useValue: { can: () => true } },
        { provide: HotToastService, useValue: toastStub() },
        { provide: AxConfirmService, useValue: confirmStub() },
        { provide: NavigationHistoryService, useValue: { back: () => undefined } },
      ],
    })
      .overrideComponent(EditCollectionComponent, {
        remove: { imports: [AdminShellComponent] },
        add: { imports: [AdminShellStubComponent] },
      })
      .compileComponents();

    fixture = TestBed.createComponent(EditCollectionComponent);
    el = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
  });

  const card = (id: number, scope = '') => el.querySelector<HTMLElement>(`${scope} [data-product-card="${id}"]`);

  it('renders "in this collection" and "also in N collections" indicators from the memberships response', () => {
    const first = card(1, '.ec-curated')!;
    expect(first.classList).toContain('ec-card--in');
    expect(first.querySelector('.ec-flag-in')?.textContent).toContain('Cover');
    expect(first.querySelector('.ec-flag-also')?.textContent).toContain('Also in 1 collection');
    expect(first.querySelector('.ec-flag-also')?.getAttribute('title')).toBe('Also in: Eid edit');
    // Only in this collection: no "also in" chip.
    expect(card(2, '.ec-curated')!.querySelector('.ec-flag-also')).toBeNull();

    // Browse grid: product 3 is not here but is in two others (one inactive).
    fixture.componentInstance.selectAddTab('new_arrivals');
    fixture.detectChanges();
    const p3 = card(3, '.ec-grid')!;
    expect(p3.classList).not.toContain('ec-card--in');
    expect(p3.querySelector('.ec-flag-in')).toBeNull();
    expect(p3.querySelector('.ec-flag-also')?.getAttribute('title')).toBe('Also in: Eid edit, Gifts (inactive)');
    expect(card(1, '.ec-grid')!.querySelector('.ec-flag-in')?.textContent).toContain('In this collection');
  });

  it('opens the details panel from a card, traps it as a modal dialog, and returns focus on Esc', fakeAsync(() => {
    const title = card(2, '.ec-curated')!.querySelector<HTMLButtonElement>('[data-focus="title"]')!;
    title.focus();
    title.click();
    fixture.detectChanges();
    tick();
    fixture.detectChanges();

    const dialog = el.querySelector<HTMLElement>('[role="dialog"]')!;
    expect(dialog).not.toBeNull();
    expect(dialog.getAttribute('aria-modal')).toBe('true');
    const heading = el.querySelector<HTMLElement>('#' + dialog.getAttribute('aria-labelledby'))!;
    expect(heading).not.toBeNull();
    expect(document.activeElement).toBe(heading);
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /admin/products/:id', { params: { id: '2' } });
    expect(dialog.textContent).toContain('Hand made');

    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    fixture.detectChanges();
    tick();
    expect(el.querySelector('[role="dialog"]')).toBeNull();
    expect(document.activeElement).toBe(title);
    flush();
  }));

  it('Esc still closes the panel after focus left its controls (e.g. a click on its text)', fakeAsync(() => {
    card(2, '.ec-curated')!.querySelector<HTMLButtonElement>('[data-focus="title"]')!.click();
    fixture.detectChanges();
    tick();
    fixture.detectChanges();
    const dialog = el.querySelector<HTMLElement>('[role="dialog"]')!;
    expect(dialog.getAttribute('tabindex')).toBe('-1');

    (document.activeElement as HTMLElement | null)?.blur();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    fixture.detectChanges();
    tick();
    expect(el.querySelector('[role="dialog"]')).toBeNull();
    flush();
  }));

  it('marks unsaved adds in the curated card body, in both grid and list view', () => {
    const c = fixture.componentInstance;
    c.selectAddTab('new_arrivals');
    fixture.detectChanges();
    c.addProduct(c.browse.items.find((p) => p.id === 3)!);
    fixture.detectChanges();
    expect(card(3, '.ec-curated')!.querySelector('.ec-card__unsaved')?.textContent).toContain('Unsaved add');
    expect(card(1, '.ec-curated')!.querySelector('.ec-card__unsaved')).toBeNull();
    // The image flag no longer carries it on curated cards (Remove covers that corner).
    expect(card(3, '.ec-curated')!.querySelector('.ec-flag-in__unsaved')).toBeNull();

    c.setCuratedView('list');
    fixture.detectChanges();
    expect(card(3, '.ec-curated')!.querySelector('.ec-card__unsaved')).not.toBeNull();
    c.setCuratedView('grid');
  });

  it('shows "Collections unknown" (not nothing) when the memberships lookup fails', () => {
    const c = fixture.componentInstance;
    adapter.get_v3.and.callFake((key: string, opts?: any) =>
      key === 'GET /admin/collections/memberships' ? throwError(() => new Error('down')) : fakeAdapter().get_v3(key, opts),
    );
    (c as any).openCollection(String(COLLECTION_ID));
    fixture.detectChanges();
    expect(card(1, '.ec-curated')!.querySelector('.ec-flag-also--unknown')?.textContent).toContain('Collections unknown');
    const notice = el.querySelector<HTMLElement>('.ec-notice');
    expect(notice?.textContent).toContain("Couldn't check which other collections 2 products are in");
    expect(notice?.querySelector('button')?.textContent).toContain('Retry');
  });

  it("the card's own Add/Remove button toggles membership without opening the panel", () => {
    fixture.componentInstance.selectAddTab('new_arrivals');
    fixture.detectChanges();
    const toggle = card(3, '.ec-grid')!.querySelector<HTMLButtonElement>('[data-card-action]')!;
    toggle.click();
    fixture.detectChanges();
    expect(el.querySelector('[role="dialog"]')).toBeNull();
    expect(fixture.componentInstance.isAdded(3)).toBeTrue();
    expect(toggle.textContent).toContain('Remove');
  });
});
