import { defer, EMPTY, Observable } from 'rxjs';
import { expand, map, reduce } from 'rxjs/operators';

/**
 * Loads EVERY store for the coupon create/edit store pickers.
 *
 * 'GET /utility/stores' is GET /v3/vendors, the paginated public store
 * directory (limit clamped to 48 server-side, default 24). The pickers used
 * to call it with no params, so they only ever saw the first 24 stores. The
 * directory's DEFAULT order is now "most followed first" (the storefront
 * ranking), so the pickers also ask for sort=name to stay alphabetical.
 *
 * Pages through with limit/offset until the API says there is nothing more
 * (meta.has_more === false) or a page comes back short, and returns the raw
 * store rows in page order, so each component keeps its own response mapping.
 *
 * Runaway guards: at most `maxPages` requests, and paging stops as soon as a
 * page adds no store id that was not already seen (a server ignoring
 * `offset` would otherwise loop on the same page). Rows already seen are
 * dropped, so a store is never listed twice.
 */

/** The /v3/vendors maximum page size (ListVendorsController::MAX_LIMIT). */
export const STORE_PAGE_SIZE = 48;

/** Hard stop: 50 pages x 48 = 2,400 stores, far above the catalogue size. */
export const STORE_MAX_PAGES = 50;

// A type alias (not an interface) so it is assignable to the adapter's
// Record-typed `query` option.
export type StorePageQuery = {
  sort: 'name';
  limit: number;
  offset: number;
};

/** Issues one directory request, e.g. `q => adapter.get_v3('GET /utility/stores', { query: q })`. */
export type StorePageFetcher = (query: StorePageQuery) => Observable<any>;

interface LoadedPage {
  index: number;
  rows: any[];
  last: boolean;
}

export function fetchAllStores(
  fetchPage: StorePageFetcher,
  pageSize: number = STORE_PAGE_SIZE,
  maxPages: number = STORE_MAX_PAGES,
): Observable<any[]> {
  return defer(() => {
    const seen = new Set<unknown>();

    const load = (index: number): Observable<LoadedPage> =>
      fetchPage({ sort: 'name', limit: pageSize, offset: index * pageSize }).pipe(
        map((res: any): LoadedPage => {
          const rows: any[] = Array.isArray(res?.data) ? res.data : [];
          const fresh = rows.filter((row: any) => {
            const id = row?.id;
            if (id === undefined || id === null) return true;
            if (seen.has(id)) return false;
            seen.add(id);
            return true;
          });
          const last =
            rows.length < pageSize ||
            res?.meta?.has_more === false ||
            fresh.length === 0 ||
            index + 1 >= maxPages;
          return { index, rows: fresh, last };
        }),
      );

    return load(0).pipe(
      expand((page) => (page.last ? EMPTY : load(page.index + 1))),
      reduce((all: any[], page) => all.concat(page.rows), []),
    );
  });
}
