import { Subject, of, throwError } from 'rxjs';

import { CollectionMembershipCache, MEMBERSHIP_BATCH_SIZE } from './collection-membership-cache';
import {
  alsoInLabel,
  membershipMapFromApi,
  plainText,
  productFromApi,
} from './collection-builder.model';

describe('CollectionMembershipCache', () => {
  const response = (ids: number[]) => ({
    data: Object.fromEntries(
      ids.map((id) => [String(id), id % 2 ? [{ id: 9, name: 'Eid edit', slug: 'eid', is_active: true }] : []]),
    ),
  });

  it('fetches every unknown id in ONE batched request and caches the result', () => {
    const fetch = jasmine.createSpy('fetch').and.callFake((ids: number[]) => of(response(ids)));
    const cache = new CollectionMembershipCache(fetch);

    cache.ensure([1, 2, 2, 3]);
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch.calls.mostRecent().args[0]).toEqual([1, 2, 3]);
    expect(cache.get(1)?.map((c) => c.name)).toEqual(['Eid edit']);
    expect(cache.get(2)).toEqual([]);

    // Known ids are never refetched; only the new one is.
    cache.ensure([1, 2, 3, 4]);
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(fetch.calls.mostRecent().args[0]).toEqual([4]);
  });

  it('does not refire for ids already in flight', () => {
    const pending = new Subject<unknown>();
    const fetch = jasmine.createSpy('fetch').and.returnValue(pending);
    const cache = new CollectionMembershipCache(fetch);

    cache.ensure([5, 6]);
    cache.ensure([5, 6]);
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(cache.isLoading(5)).toBeTrue();
    expect(cache.get(5)).toBeUndefined();

    pending.next(response([5, 6]));
    pending.complete();
    expect(cache.isLoading(5)).toBeFalse();
    expect(cache.get(5)?.length).toBe(1);
  });

  it(`splits more than ${MEMBERSHIP_BATCH_SIZE} ids into contract-sized batches`, () => {
    const fetch = jasmine.createSpy('fetch').and.callFake((ids: number[]) => of(response(ids)));
    const cache = new CollectionMembershipCache(fetch);
    cache.ensure(Array.from({ length: 450 }, (_, i) => i + 1));
    expect(fetch.calls.allArgs().map(([ids]) => (ids as number[]).length)).toEqual([200, 200, 50]);
  });

  it('ignores a response that was in flight before invalidate()', () => {
    const first = new Subject<unknown>();
    const fetch = jasmine.createSpy('fetch').and.returnValues(first, of(response([1])));
    const cache = new CollectionMembershipCache(fetch);

    cache.ensure([1]);
    cache.invalidate();
    cache.ensure([1]);
    expect(cache.get(1)?.length).toBe(1);

    // The stale answer (in no collections) must not overwrite the fresh one.
    first.next({ data: { '1': [] } });
    expect(cache.get(1)?.length).toBe(1);
  });

  it('refresh() refetches but keeps showing the last-known value until the answer lands', () => {
    const later = new Subject<unknown>();
    const fetch = jasmine.createSpy('fetch').and.returnValues(of(response([1, 2])), later);
    const cache = new CollectionMembershipCache(fetch);
    cache.ensure([1, 2]);

    cache.refresh([1]);
    expect(fetch.calls.mostRecent().args[0]).toEqual([1]);
    expect(cache.isLoading(1)).toBeTrue();
    expect(cache.isPending(1)).toBeFalse(); // old value still shown
    expect(cache.get(1)?.length).toBe(1);
    expect(cache.get(2)).toBeUndefined(); // not on screen: dropped

    later.next({ data: { '1': [] } });
    expect(cache.get(1)).toEqual([]);
    expect(cache.isLoading(1)).toBeFalse();
  });

  it('marks a failed batch so cards stop showing "loading"', () => {
    const fetch = jasmine.createSpy('fetch').and.returnValue(throwError(() => new Error('boom')));
    const cache = new CollectionMembershipCache(fetch);
    cache.ensure([3]);
    expect(cache.isLoading(3)).toBeFalse();
    expect(cache.hasFailed(3)).toBeTrue();
    cache.ensure([3]);
    expect(fetch).toHaveBeenCalledTimes(1);
    cache.retryFailed();
    cache.ensure([3]);
    expect(fetch).toHaveBeenCalledTimes(2);
  });

  it('reports a failed lookup as UNKNOWN (not as "in no collection") and keeps its error', () => {
    const forbidden = { status: 403, error: { error: { message: 'Forbidden' } } };
    const fetch = jasmine.createSpy('fetch').and.returnValue(throwError(() => forbidden));
    const cache = new CollectionMembershipCache(fetch);
    cache.ensure([3, 4]);
    expect(cache.get(3)).toBeUndefined();
    expect(cache.isUnknown(3)).toBeTrue();
    expect(cache.isPending(3)).toBeFalse();
    expect(cache.lastError).toBe(forbidden);
  });

  it('retry(ids) looks failed ids up again, and only those (plus unknown ones)', () => {
    const fetch = jasmine.createSpy('fetch').and.returnValues(
      of(response([1])),
      throwError(() => new Error('boom')),
      of(response([3])),
    );
    const cache = new CollectionMembershipCache(fetch);
    cache.ensure([1]);
    cache.ensure([3]);
    expect(cache.isUnknown(3)).toBeTrue();

    cache.retry([1, 3]);
    // 1 is cached and fine: not refetched. 3 failed: refetched.
    expect(fetch).toHaveBeenCalledTimes(3);
    expect(fetch.calls.mostRecent().args[0]).toEqual([3]);
    expect(cache.isUnknown(3)).toBeFalse();
    expect(cache.get(3)?.length).toBe(1);
    expect(cache.lastError).toBeNull();
  });

  it('retry(ids) refetches an id whose refresh failed even though an older value is still shown', () => {
    const fetch = jasmine.createSpy('fetch').and.returnValues(
      of(response([1])),
      throwError(() => new Error('boom')),
      of({ data: { '1': [] } }),
    );
    const cache = new CollectionMembershipCache(fetch);
    cache.ensure([1]);
    cache.refresh([1]);
    expect(cache.hasFailed(1)).toBeTrue();
    expect(cache.isUnknown(1)).toBeFalse(); // the last-known value is still there
    cache.retry([1]);
    expect(fetch).toHaveBeenCalledTimes(3);
    expect(cache.get(1)).toEqual([]);
  });
});

describe('collection builder parsers', () => {
  it('keys EVERY requested id, unknown ones as []', () => {
    const map = membershipMapFromApi(
      { data: { '1': [{ id: 4, name: 'Ramadan', slug: 'ramadan', is_active: false }] } },
      [1, 2],
    );
    expect(map.get(1)).toEqual([{ id: 4, name: 'Ramadan', slug: 'ramadan', is_active: false, sort_order: null }]);
    expect(map.get(2)).toEqual([]);
  });

  it('reads the list shape (object primary_image, money objects, honest sale price)', () => {
    const p = productFromApi({
      id: 12,
      slug: 'silk-abaya',
      name: 'Silk abaya',
      price: { amount: 450, currency: 'AED' },
      sale_price: { amount: 399, currency: 'AED' },
      primary_image: { url: 'https://img/1.jpg' },
      images: [{ url: 'https://img/1.jpg' }, { url: 'https://img/2.jpg' }],
      vendor: { name: 'Almas' },
      in_stock: true,
    });
    expect(p.image).toBe('https://img/1.jpg');
    expect(p.images).toEqual(['https://img/1.jpg', 'https://img/2.jpg']);
    expect(p.price).toBe(450);
    expect(p.sale_price).toBe(399);
    expect(p.vendor_name).toBe('Almas');

    const bogusSale = productFromApi({ id: 1, price: 100, sale_price: 0 });
    expect(bogusSale.sale_price).toBeNull();
  });

  it('turns rich-text descriptions into plain text', () => {
    expect(plainText('<p>Soft&nbsp;silk</p><p>Hand <b>made</b></p>')).toBe('Soft silk\nHand made');
  });

  it('labels other-collection counts', () => {
    expect(alsoInLabel(1)).toBe('Also in 1 collection');
    expect(alsoInLabel(3)).toBe('Also in 3 collections');
  });
});
