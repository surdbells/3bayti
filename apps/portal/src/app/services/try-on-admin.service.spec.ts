import { TestBed } from '@angular/core/testing';
import { Observable, Subject, of, throwError } from 'rxjs';

import {
  TRY_ON_BULK_MAX,
  TryOnAdminService,
  chunkTryOnIds,
  parseTryOnBulk,
  parseTryOnEligibility,
  parseTryOnPage,
  parseTryOnProduct,
  parseTryOnStatus,
  tryOnApiError,
} from './try-on-admin.service';
import { PortalCrudAdapter } from './portal-crud-adapter';

const item = (id: number, extra: Record<string, unknown> = {}) => ({
  id,
  slug: `abaya-${id}`,
  name: `Abaya ${id}`,
  status: 'active',
  vendor: { id: 9, name: 'Noor Atelier', slug: 'noor-atelier' },
  primary_image_url: `https://api-v3.3bayti.ae/uploads/products/${id}.jpg`,
  try_on_enabled: false,
  eligibility: { eligible: true, reason: null },
  ...extra,
});

interface FakeAdapter {
  get_v3: jasmine.Spy;
  put_v3: jasmine.Spy;
  post_v3: jasmine.Spy;
  getSession: jasmine.Spy;
}

function fakeAdapter(): FakeAdapter {
  return {
    get_v3: jasmine.createSpy('get_v3'),
    put_v3: jasmine.createSpy('put_v3'),
    post_v3: jasmine.createSpy('post_v3'),
    getSession: jasmine.createSpy('getSession').and.returnValue({ id: 7 }),
  };
}

function makeService(adapter: FakeAdapter): TryOnAdminService {
  TestBed.configureTestingModule({
    providers: [{ provide: PortalCrudAdapter, useValue: adapter }],
  });
  return TestBed.inject(TryOnAdminService);
}

describe('Try-On admin parsers', () => {
  it('reads the status from a { data } envelope or a raw body', () => {
    const raw = { available: true, tryon_flag: true, ai_enabled: true, enabled_products: 4, eligible_formats: ['jpeg', 'png', 'webp'] };
    expect(parseTryOnStatus(raw)).toEqual(raw);
    expect(parseTryOnStatus({ data: raw })).toEqual(raw);
  });

  it('fails closed on a missing/garbled status (available only when literally true)', () => {
    const s = parseTryOnStatus({ available: 'yes', enabled_products: -3 });
    expect(s.available).toBeFalse();
    expect(s.tryon_flag).toBeFalse();
    expect(s.ai_enabled).toBeFalse();
    expect(s.enabled_products).toBe(0);
    expect(s.eligible_formats).toEqual(['jpeg', 'png', 'webp']);
    expect(parseTryOnStatus(null).available).toBeFalse();
  });

  it('parses eligibility, keeping only known reasons', () => {
    expect(parseTryOnEligibility({ eligible: true, reason: null })).toEqual({ eligible: true, reason: null });
    expect(parseTryOnEligibility({ eligible: false, reason: 'unsupported_format' })).toEqual({ eligible: false, reason: 'unsupported_format' });
    expect(parseTryOnEligibility({ eligible: false, reason: 'weird' })).toEqual({ eligible: false, reason: null });
    // eligible:true never carries a reason
    expect(parseTryOnEligibility({ eligible: true, reason: 'no_image' })).toEqual({ eligible: true, reason: null });
    expect(parseTryOnEligibility(undefined)).toBeNull();
    expect(parseTryOnEligibility({ reason: 'no_image' })).toBeNull();
  });

  it('parses a product item (v3 id, vendor, image, raw flag) and rejects id-less rows', () => {
    const p = parseTryOnProduct(item(12, { try_on_enabled: true, primary_image_url: '' }));
    expect(p).toEqual({
      id: 12,
      slug: 'abaya-12',
      name: 'Abaya 12',
      status: 'active',
      vendor: { id: 9, name: 'Noor Atelier', slug: 'noor-atelier' },
      primary_image_url: null,
      try_on_enabled: true,
      eligibility: { eligible: true, reason: null },
    });
    expect(parseTryOnProduct({ data: item(3) })?.id).toBe(3);
    expect(parseTryOnProduct(item(5, { vendor: null }))?.vendor).toBeNull();
    expect(parseTryOnProduct({ name: 'no id' })).toBeNull();
    expect(parseTryOnProduct({ id: 'abc' })).toBeNull();
  });

  it('parses the admin list envelope (data + meta)', () => {
    const page = parseTryOnPage(
      { data: [item(1), { bogus: true }, item(2)], meta: { total: 57, limit: 20, offset: 40, has_more: false } },
      20,
      40,
    );
    expect(page.items.map((p) => p.id)).toEqual([1, 2]);
    expect(page.total).toBe(57);
    expect(page.limit).toBe(20);
    expect(page.offset).toBe(40);
    expect(page.hasMore).toBeFalse();
  });

  it('falls back to the requested limit/offset when meta is absent', () => {
    const page = parseTryOnPage({ data: [item(1)] }, 50, 0);
    expect(page.total).toBe(1);
    expect(page.limit).toBe(50);
    expect(page.offset).toBe(0);
    expect(page.hasMore).toBeFalse();
  });

  it('parses a bulk result, mapping unknown skip reasons to not_found', () => {
    expect(parseTryOnBulk({ data: { updated: [1, '2', 0, 'x'], skipped: [{ id: 3, reason: 'unsupported_format' }, { id: 4, reason: '??' }, { reason: 'no_image' }] } }))
      .toEqual({ updated: [1, 2], skipped: [{ id: 3, reason: 'unsupported_format' }, { id: 4, reason: 'not_found' }] });
    expect(parseTryOnBulk(null)).toEqual({ updated: [], skipped: [] });
  });

  it('dedupes ids, drops invalid ones and chunks at the API maximum', () => {
    expect(chunkTryOnIds([3, 3, 0, -1, 'x', 4, 5], 2)).toEqual([[3, 4], [5]]);
    const many = Array.from({ length: TRY_ON_BULK_MAX + 1 }, (_, i) => i + 1);
    const chunks = chunkTryOnIds(many);
    expect(chunks.length).toBe(2);
    expect(chunks[0].length).toBe(TRY_ON_BULK_MAX);
    expect(chunks[1]).toEqual([TRY_ON_BULK_MAX + 1]);
    expect(chunkTryOnIds([])).toEqual([]);
  });

  it('reads the v3 error envelope (code, message, details.reason)', () => {
    const err = { status: 422, error: { error: { code: 'TRYON_IMAGE_INELIGIBLE', message: 'GIF images are not supported.', details: { reason: 'unsupported_format' } } } };
    expect(tryOnApiError(err)).toEqual({ code: 'TRYON_IMAGE_INELIGIBLE', message: 'GIF images are not supported.', reason: 'unsupported_format' });
    expect(tryOnApiError({ status: 0, error: new ProgressEvent('error') })).toBeNull();
  });
});

describe('TryOnAdminService', () => {
  it('lists products with page/limit/offset, q and the enabled filter (T2)', () => {
    const adapter = fakeAdapter();
    adapter.get_v3.and.returnValue(of({ data: [item(1)], meta: { total: 1, limit: 50, offset: 50, has_more: false } }));
    const svc = makeService(adapter);

    let total = -1;
    svc.listProducts({ q: '  abaya ', enabled: true, page: 2, limit: 50 }).subscribe((p) => (total = p.total));

    expect(adapter.get_v3).toHaveBeenCalledOnceWith('GET /admin/try-on/products', {
      query: { page: 2, limit: 50, offset: 50, q: 'abaya', enabled: 'true' },
    });
    expect(total).toBe(1);
  });

  it('omits q/enabled for "all", clamps the limit to 100 and defaults to page 1 / 20', () => {
    const adapter = fakeAdapter();
    adapter.get_v3.and.returnValue(of({ data: [], meta: { total: 0 } }));
    const svc = makeService(adapter);

    svc.listProducts({ q: '   ', enabled: null, limit: 500 }).subscribe();
    svc.listProducts().subscribe();
    svc.listProducts({ enabled: false }).subscribe();

    expect(adapter.get_v3.calls.argsFor(0)).toEqual(['GET /admin/try-on/products', { query: { page: 1, limit: 100, offset: 0 } }]);
    expect(adapter.get_v3.calls.argsFor(1)).toEqual(['GET /admin/try-on/products', { query: { page: 1, limit: 20, offset: 0 } }]);
    expect(adapter.get_v3.calls.argsFor(2)).toEqual(['GET /admin/try-on/products', { query: { page: 1, limit: 20, offset: 0, enabled: 'false' } }]);
  });

  it('toggles one product by v3 id and returns the updated item (T3)', () => {
    const adapter = fakeAdapter();
    adapter.put_v3.and.returnValue(of({ data: item(42, { try_on_enabled: true }) }));
    const svc = makeService(adapter);

    let enabled: boolean | undefined;
    svc.setEnabled(42, true).subscribe((p) => (enabled = p.try_on_enabled));

    expect(adapter.put_v3).toHaveBeenCalledOnceWith('PUT /admin/products/:id/try-on', { enabled: true }, { params: { id: '42' } });
    expect(enabled).toBeTrue();
  });

  it('passes a 422 through untouched so the caller can read it', () => {
    const adapter = fakeAdapter();
    const err = { status: 422, error: { error: { code: 'TRYON_IMAGE_INELIGIBLE', message: 'x', details: { reason: 'no_image' } } } };
    adapter.put_v3.and.returnValue(throwError(() => err));
    const svc = makeService(adapter);

    let caught: unknown;
    svc.setEnabled(1, true).subscribe({ error: (e) => (caught = e) });
    expect(caught).toBe(err);
  });

  it('sends bulk updates in ≤200-id chunks and merges the results (T4)', () => {
    const adapter = fakeAdapter();
    adapter.post_v3.and.callFake((_key: string, body: { product_ids: number[] }) =>
      of({ updated: body.product_ids.slice(1), skipped: [{ id: body.product_ids[0], reason: 'unsupported_format' }] }),
    );
    const svc = makeService(adapter);
    const ids = [...Array.from({ length: 250 }, (_, i) => i + 1), 1, 2];

    let result: { updated: readonly number[]; skipped: readonly { id: number }[] } | undefined;
    svc.bulkSetEnabled(ids, true).subscribe((r) => (result = r));

    expect(adapter.post_v3).toHaveBeenCalledTimes(2);
    const [key, first] = adapter.post_v3.calls.argsFor(0);
    expect(key).toBe('POST /admin/try-on/products/bulk');
    expect(first.product_ids.length).toBe(200);
    expect(first.enabled).toBeTrue();
    expect(adapter.post_v3.calls.argsFor(1)[1].product_ids.length).toBe(50);
    expect(result!.updated.length).toBe(248);
    expect(result!.skipped.map((s) => s.id)).toEqual([1, 201]);
  });

  it('makes no call for an empty bulk selection', () => {
    const adapter = fakeAdapter();
    const svc = makeService(adapter);
    let result: unknown;
    svc.bulkSetEnabled([], false).subscribe((r) => (result = r));
    expect(adapter.post_v3).not.toHaveBeenCalled();
    expect(result).toEqual({ updated: [], skipped: [] });
  });

  it('fetches the status once per session for the nav and caches it (T1)', () => {
    const adapter = fakeAdapter();
    adapter.get_v3.and.returnValue(of({ data: { available: true, tryon_flag: true, ai_enabled: true, enabled_products: 3 } }));
    const svc = makeService(adapter);

    expect(svc.available()).toBeFalse();
    svc.ensureStatus();
    svc.ensureStatus();

    expect(adapter.get_v3).toHaveBeenCalledOnceWith('GET /admin/try-on/status');
    expect(svc.available()).toBeTrue();
    expect(svc.status()?.enabled_products).toBe(3);
  });

  it('fails closed (hidden) on a status error and does not retry within the session', () => {
    const adapter = fakeAdapter();
    adapter.get_v3.and.returnValue(throwError(() => ({ status: 500 })));
    const svc = makeService(adapter);

    svc.ensureStatus();
    svc.ensureStatus();

    expect(adapter.get_v3).toHaveBeenCalledTimes(1);
    expect(svc.status()).toBeNull();
    expect(svc.available()).toBeFalse();
  });

  it('re-fetches the status for a different signed-in user', () => {
    const adapter = fakeAdapter();
    adapter.get_v3.and.returnValue(of({ available: true, tryon_flag: true, ai_enabled: true, enabled_products: 1 }));
    const svc = makeService(adapter);

    svc.ensureStatus();
    adapter.getSession.and.returnValue({ id: 8 });
    adapter.get_v3.and.returnValue(throwError(() => ({ status: 403 })));
    svc.ensureStatus();

    expect(adapter.get_v3).toHaveBeenCalledTimes(2);
    // the previous user's status is dropped, and the failure fails closed
    expect(svc.available()).toBeFalse();
  });

  it('shares an in-flight status request and lets loadStatus refresh it', () => {
    const adapter = fakeAdapter();
    const pending = new Subject<unknown>();
    adapter.get_v3.and.returnValue(pending as Observable<unknown>);
    const svc = makeService(adapter);

    const seen: boolean[] = [];
    svc.loadStatus().subscribe((s) => seen.push(s.available));
    svc.ensureStatus();
    svc.loadStatus().subscribe((s) => seen.push(s.available));
    expect(adapter.get_v3).toHaveBeenCalledTimes(1);

    pending.next({ available: false, tryon_flag: false, ai_enabled: true, enabled_products: 0 });
    pending.complete();
    expect(seen).toEqual([false, false]);

    adapter.get_v3.and.returnValue(of({ available: true, tryon_flag: true, ai_enabled: true, enabled_products: 2 }));
    svc.loadStatus().subscribe();
    expect(adapter.get_v3).toHaveBeenCalledTimes(2);
    expect(svc.available()).toBeTrue();
  });

  it('keeps the cached enabled count in step with writes (never below 0)', () => {
    const adapter = fakeAdapter();
    adapter.get_v3.and.returnValue(of({ available: true, tryon_flag: true, ai_enabled: true, enabled_products: 1 }));
    const svc = makeService(adapter);

    svc.bumpEnabledCount(1); // no status yet: ignored
    expect(svc.status()).toBeNull();

    svc.ensureStatus();
    svc.bumpEnabledCount(1);
    expect(svc.status()?.enabled_products).toBe(2);
    svc.bumpEnabledCount(-5);
    expect(svc.status()?.enabled_products).toBe(0);
  });
});
