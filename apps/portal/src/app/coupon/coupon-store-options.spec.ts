import { Observable, of, throwError } from 'rxjs';
import { fetchAllStores, StorePageQuery, STORE_MAX_PAGES, STORE_PAGE_SIZE } from './coupon-store-options';

/**
 * fetchAllStores feeds the coupon create/edit store pickers. It must ask the
 * directory for the A-Z order (the default is now most-followed-first), page
 * through EVERY store (the pickers used to stop at the first 24), and never
 * loop forever or list a store twice.
 */
describe('fetchAllStores', () => {
  /** A fake GET /v3/vendors: serves `catalogue` by limit/offset with an honest meta.has_more. */
  function directory(catalogue: { id: number; name: string }[], opts: { ignoreOffset?: boolean; omitMeta?: boolean } = {}) {
    const calls: StorePageQuery[] = [];
    const fetcher = (q: StorePageQuery): Observable<any> => {
      calls.push(q);
      const offset = opts.ignoreOffset ? 0 : q.offset;
      const data = catalogue.slice(offset, offset + q.limit);
      const meta = { total: catalogue.length, limit: q.limit, offset, has_more: offset + data.length < catalogue.length };
      return of(opts.omitMeta ? { data } : { data, meta });
    };
    return { calls, fetcher };
  }

  function stores(n: number): { id: number; name: string }[] {
    return Array.from({ length: n }, (_, i) => ({ id: i + 1, name: `Store ${i + 1}` }));
  }

  function collect(source: Observable<any[]>): any[] {
    let out: any[] | undefined;
    source.subscribe((rows) => (out = rows));
    if (out === undefined) throw new Error('fetchAllStores did not emit');
    return out;
  }

  it('pages with sort=name and the max page size until a short page, keeping page order', () => {
    const all = stores(99);
    const dir = directory(all);

    const rows = collect(fetchAllStores(dir.fetcher));

    expect(STORE_PAGE_SIZE).toBe(48); // the /v3/vendors max limit
    expect(dir.calls).toEqual([
      { sort: 'name', limit: 48, offset: 0 },
      { sort: 'name', limit: 48, offset: 48 },
      { sort: 'name', limit: 48, offset: 96 },
    ]);
    expect(rows.map((r) => r.id)).toEqual(all.map((s) => s.id));
  });

  it('stops on has_more=false even when the last page is full', () => {
    const dir = directory(stores(96));

    const rows = collect(fetchAllStores(dir.fetcher));

    expect(dir.calls.length).toBe(2);
    expect(rows.length).toBe(96);
  });

  it('stops on a short page when the response has no meta', () => {
    const dir = directory(stores(50), { omitMeta: true });

    const rows = collect(fetchAllStores(dir.fetcher));

    expect(dir.calls.length).toBe(2);
    expect(rows.length).toBe(50);
  });

  it('makes one request and returns [] for an empty directory or an empty body', () => {
    const empty = directory([]);
    expect(collect(fetchAllStores(empty.fetcher))).toEqual([]);
    expect(empty.calls.length).toBe(1);

    let nullCalls = 0;
    expect(collect(fetchAllStores(() => { nullCalls++; return of(null); }))).toEqual([]);
    expect(nullCalls).toBe(1);
  });

  it('caps the number of requests (runaway guard)', () => {
    // A server that always claims more and always returns fresh full pages.
    let calls = 0;
    const endless = (q: StorePageQuery): Observable<any> => {
      calls++;
      const data = Array.from({ length: q.limit }, (_, i) => ({ id: q.offset + i + 1, name: 'x' }));
      return of({ data, meta: { has_more: true } });
    };

    const rows = collect(fetchAllStores(endless, 2, 3));

    expect(calls).toBe(3);
    expect(rows.length).toBe(6);
    expect(STORE_MAX_PAGES).toBeGreaterThan(1);
  });

  it('stops, without duplicates, when the server ignores offset', () => {
    const dir = directory(stores(10), { ignoreOffset: true });

    const rows = collect(fetchAllStores(dir.fetcher, 2, 50));

    expect(dir.calls.length).toBe(2);
    expect(rows.map((r) => r.id)).toEqual([1, 2]);
  });

  it('propagates a failed page as an error (no partial list)', () => {
    let calls = 0;
    const flaky = (q: StorePageQuery): Observable<any> => {
      calls++;
      if (q.offset > 0) return throwError(() => new Error('boom'));
      return of({ data: stores(2), meta: { has_more: true } });
    };

    let error: unknown;
    let emitted = false;
    fetchAllStores(flaky, 2).subscribe({ next: () => (emitted = true), error: (e) => (error = e) });

    expect(calls).toBe(2);
    expect(emitted).toBeFalse();
    expect((error as Error).message).toBe('boom');
  });

  it('keeps per-subscription state (a re-subscribe reloads everything)', () => {
    const dir = directory(stores(3));
    const source = fetchAllStores(dir.fetcher);

    expect(collect(source).length).toBe(3);
    expect(collect(source).length).toBe(3);
  });
});
