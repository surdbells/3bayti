import { of } from 'rxjs';

import {
  VendorProductsPage,
  collectionIdsFromRows,
  collectionIdsFromValues,
  sameIdSet,
} from './vendor-products.page';

/**
 * Vendor quick-edit "Collections" action: loads active collections + the
 * product's memberships, offers a checkbox alert and saves the full set via
 * PUT /vendor/products/:id/collections with an optimistic precondition.
 * Built without TestBed (plain constructor injection) so the spec stays fast.
 */
describe('VendorProductsPage collections', () => {
  const product = {
    id: 42,
    name: 'Abaya',
    image: null,
    price: 100,
    price_formatted: 'AED 100.00',
    status: 'published',
    stock_status: 'in_stock',
    stock_quantity: 3,
  };

  let adapter: jasmine.SpyObj<any>;
  let toast: jasmine.SpyObj<any>;
  let alertCtrl: jasmine.SpyObj<any>;
  let alertEl: jasmine.SpyObj<any>;
  let page: VendorProductsPage;

  const ok = (data: unknown, meta?: unknown) => of({ response_code: 200, status: 'success', message: '', data, meta });

  beforeEach(() => {
    adapter = jasmine.createSpyObj('MobileNetworkAdapter', ['get_v3', 'put_v3']);
    toast = jasmine.createSpyObj('AxNotificationService', ['success', 'error', 'info']);
    alertEl = jasmine.createSpyObj('HTMLIonAlertElement', ['present']);
    alertEl.present.and.resolveTo();
    alertCtrl = jasmine.createSpyObj('AlertController', ['create']);
    alertCtrl.create.and.resolveTo(alertEl);
    const i18n = { t: (k: string) => k };

    page = new VendorProductsPage(
      {} as any, {} as any, toast, adapter, {} as any, alertCtrl, i18n as any,
    );
    (page as any).initial.token = 'tok';

    adapter.get_v3.and.callFake((key: string) => {
      if (key === 'GET /vendor/collections') {
        return ok([{ id: 1, name: 'Eid' }, { id: 2, name: 'Summer' }], { total: 2, limit: 100, offset: 0 });
      }
      if (key === 'GET /vendor/products/:id/collections') {
        return ok([{ id: 2, name: 'Summer', slug: 'summer', sort_order: 4 }]);
      }
      throw new Error('unexpected ' + key);
    });
  });

  it('offers active collections as checkboxes with current memberships checked', async () => {
    await page.openCollections(product);

    expect(adapter.get_v3).toHaveBeenCalledWith('GET /vendor/collections', jasmine.objectContaining({
      authToken: 'tok',
      queryParams: { limit: 100, offset: 0 },
    }));
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /vendor/products/:id/collections', jasmine.objectContaining({
      authToken: 'tok',
      pathParams: { id: '42' },
    }));
    const opts = alertCtrl.create.calls.mostRecent().args[0];
    expect(opts.inputs).toEqual([
      { type: 'checkbox', label: 'Eid', value: 1, checked: false },
      { type: 'checkbox', label: 'Summer', value: 2, checked: true },
    ]);
    expect(alertEl.present).toHaveBeenCalled();
  });

  it('saves the chosen set with the loaded set as expected_collection_ids', async () => {
    adapter.put_v3.and.returnValue(ok([{ id: 1 }, { id: 2 }]));
    await page.openCollections(product);
    const save = alertCtrl.create.calls.mostRecent().args[0].buttons[1];

    expect(save.handler([1, 2])).toBeTrue();

    expect(adapter.put_v3).toHaveBeenCalledWith(
      'PUT /vendor/products/:id/collections',
      { collection_ids: [1, 2], expected_collection_ids: [2] },
      { authToken: 'tok', pathParams: { id: '42' } },
    );
    expect(toast.success).toHaveBeenCalledWith('collections_saved', jasmine.anything());
  });

  it('does not PUT when the selection is unchanged', async () => {
    await page.openCollections(product);
    alertCtrl.create.calls.mostRecent().args[0].buttons[1].handler([2]);
    expect(adapter.put_v3).not.toHaveBeenCalled();
  });

  it('shows a reload hint on 409 CONFLICT_STALE', async () => {
    adapter.put_v3.and.returnValue(of({
      response_code: 409, status: 'error', message: 'stale', error_code: 'CONFLICT_STALE', data: null,
    }));
    await page.openCollections(product);
    alertCtrl.create.calls.mostRecent().args[0].buttons[1].handler([]);
    expect(toast.error).toHaveBeenCalledWith('collections_changed_reload', jasmine.anything());
  });

  it('surfaces the server message for other failures', async () => {
    adapter.put_v3.and.returnValue(of({ response_code: 422, status: 'error', message: 'Unknown collection id(s): 9', data: null }));
    await page.openCollections(product);
    alertCtrl.create.calls.mostRecent().args[0].buttons[1].handler([9]);
    expect(toast.error).toHaveBeenCalledWith('Unknown collection id(s): 9', jasmine.anything());
  });

  it('tells the vendor when there are no active collections', async () => {
    adapter.get_v3.and.callFake((key: string) =>
      key === 'GET /vendor/collections' ? ok([], { total: 0 }) : ok([]),
    );
    await page.openCollections(product);
    expect(toast.info).toHaveBeenCalledWith('no_active_collections', jasmine.anything());
    expect(alertCtrl.create).not.toHaveBeenCalled();
  });

  it('shows the load error and no picker when a GET fails', async () => {
    adapter.get_v3.and.callFake((key: string) =>
      key === 'GET /vendor/collections'
        ? ok([{ id: 1, name: 'Eid' }], { total: 1 })
        : of({ response_code: 404, status: 'error', message: 'Product not found.', data: null }),
    );
    await page.openCollections(product);
    expect(toast.error).toHaveBeenCalledWith('Product not found.', jasmine.anything());
    expect(alertCtrl.create).not.toHaveBeenCalled();
  });

  it('pages through more than 100 collections', async () => {
    const pageOne = Array.from({ length: 100 }, (_, i) => ({ id: i + 1, name: 'C' + (i + 1) }));
    adapter.get_v3.and.callFake((key: string, opts: any) => {
      if (key === 'GET /vendor/collections') {
        return opts.queryParams.offset === 0
          ? ok(pageOne, { total: 101 })
          : ok([{ id: 101, name: 'C101' }], { total: 101 });
      }
      return ok([]);
    });
    await page.openCollections(product);
    expect(alertCtrl.create.calls.mostRecent().args[0].inputs.length).toBe(101);
  });

  describe('helpers', () => {
    it('collectionIdsFromRows keeps positive integer ids', () => {
      expect(collectionIdsFromRows([{ id: 3 }, { id: '4' }, { id: 0 }, {}])).toEqual([3, 4]);
      expect(collectionIdsFromRows(null)).toEqual([]);
    });

    it('collectionIdsFromValues de-duplicates', () => {
      expect(collectionIdsFromValues([1, '1', 2, 'x'])).toEqual([1, 2]);
      expect(collectionIdsFromValues(undefined)).toEqual([]);
    });

    it('sameIdSet ignores order', () => {
      expect(sameIdSet([1, 2], [2, 1])).toBeTrue();
      expect(sameIdSet([1], [1, 2])).toBeFalse();
      expect(sameIdSet([], [])).toBeTrue();
    });
  });
});
