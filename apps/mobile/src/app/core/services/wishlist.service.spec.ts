import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { WishlistService } from './wishlist.service';
import { MobileNetworkAdapter } from '../http/mobile-network-adapter';

class AdapterStub {
  getResp: any = { response_code: 200, status: 'success', data: [], meta: { total: 0 } };
  postResp: any = { response_code: 201, status: 'success', data: { id: 1, name: 'Eid', count: 0, created_at: 'x' } };
  patchResp: any = { response_code: 200, status: 'success', data: {} };
  deleteResp: any = { response_code: 204, status: 'success', data: null };
  lastGet: any = null;
  lastPost: any = null;
  lastPatch: any = null;
  lastDelete: any = null;

  get_v3(routeKey: string, opts: any) { this.lastGet = { routeKey, opts }; return of(this.getResp); }
  post_v3(routeKey: string, body: any, opts: any) { this.lastPost = { routeKey, body, opts }; return of(this.postResp); }
  patch_v3(routeKey: string, body: any, opts: any) { this.lastPatch = { routeKey, body, opts }; return of(this.patchResp); }
  delete_v3(routeKey: string, opts: any) { this.lastDelete = { routeKey, opts }; return of(this.deleteResp); }
}

describe('WishlistService', () => {
  let service: WishlistService;
  let adapter: AdapterStub;

  beforeEach(() => {
    adapter = new AdapterStub();
    TestBed.configureTestingModule({
      providers: [
        WishlistService,
        { provide: MobileNetworkAdapter, useValue: adapter },
      ],
    });
    service = TestBed.inject(WishlistService);
  });

  it('lists products with no label filter (all saved)', async () => {
    adapter.getResp = { response_code: 200, status: 'success', data: [{ id: 1, name: 'A' }], meta: { total: 1, has_more: false } };
    const page = await service.listProducts('tok', {});
    expect(adapter.lastGet.routeKey).toBe('GET /me/wishlist');
    expect(adapter.lastGet.opts.queryParams.label_id).toBeUndefined();
    expect(page.products.length).toBe(1);
    expect(page.total).toBe(1);
  });

  it('passes label_id when filtering by a label', async () => {
    await service.listProducts('tok', { labelId: 7 });
    expect(adapter.lastGet.opts.queryParams.label_id).toBe(7);
  });

  it('passes label_id=none for uncategorized', async () => {
    await service.listProducts('tok', { labelId: 'none' });
    expect(adapter.lastGet.opts.queryParams.label_id).toBe('none');
  });

  it('adds a product without a label', async () => {
    adapter.postResp = { response_code: 201, status: 'success', data: {} };
    const ok = await service.add('tok', 42);
    expect(adapter.lastPost.routeKey).toBe('POST /me/wishlist');
    expect(adapter.lastPost.body).toEqual({ product_id: 42 });
    expect(ok).toBeTrue();
  });

  it('adds a product into a label', async () => {
    await service.add('tok', 42, 9);
    expect(adapter.lastPost.body).toEqual({ product_id: 42, label_id: 9 });
  });

  it('removes a product (204)', async () => {
    const ok = await service.remove('tok', 42);
    expect(adapter.lastDelete.routeKey).toBe('DELETE /me/wishlist/:productId');
    expect(adapter.lastDelete.opts.pathParams).toEqual({ productId: '42' });
    expect(ok).toBeTrue();
  });

  it('moves a product to a label', async () => {
    await service.move('tok', 42, 9);
    expect(adapter.lastPatch.routeKey).toBe('PATCH /me/wishlist/:productId');
    expect(adapter.lastPatch.body).toEqual({ label_id: 9 });
    expect(adapter.lastPatch.opts.pathParams).toEqual({ productId: '42' });
  });

  it('moves a product to uncategorized (label 0)', async () => {
    await service.move('tok', 42, null);
    expect(adapter.lastPatch.body).toEqual({ label_id: 0 });
  });

  it('lists labels', async () => {
    adapter.getResp = { response_code: 200, status: 'success', data: [{ id: 1, name: 'Eid', count: 3, created_at: 'x' }] };
    const labels = await service.listLabels('tok');
    expect(adapter.lastGet.routeKey).toBe('GET /me/wishlist/labels');
    expect(labels.length).toBe(1);
    expect(labels[0].count).toBe(3);
  });

  it('creates a label', async () => {
    adapter.postResp = { response_code: 201, status: 'success', data: { id: 5, name: 'Work', count: 0, created_at: 'x' } };
    const label = await service.createLabel('tok', 'Work');
    expect(adapter.lastPost.routeKey).toBe('POST /me/wishlist/labels');
    expect(label?.id).toBe(5);
  });

  it('renames a label', async () => {
    const ok = await service.renameLabel('tok', 5, 'New');
    expect(adapter.lastPatch.routeKey).toBe('PATCH /me/wishlist/labels/:id');
    expect(adapter.lastPatch.opts.pathParams).toEqual({ id: '5' });
    expect(ok).toBeTrue();
  });

  it('deletes a label (204)', async () => {
    const ok = await service.deleteLabel('tok', 5);
    expect(adapter.lastDelete.routeKey).toBe('DELETE /me/wishlist/labels/:id');
    expect(ok).toBeTrue();
  });

  it('returns [] / false on non-success', async () => {
    adapter.getResp = { response_code: 500, status: 'error' };
    expect((await service.listProducts('tok', {})).products).toEqual([]);
    expect(await service.listLabels('tok')).toEqual([]);
  });
});
