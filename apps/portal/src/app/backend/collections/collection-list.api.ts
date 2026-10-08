import { EMPTY, Observable } from 'rxjs';
import { expand, map, reduce } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';

/** One admin collection (GET /admin/collections item, PUT /admin/collections/order item). */
export interface CollectionRow {
  id: number;
  name: string;
  slug: string;
  is_active: boolean;
  display_order: number | null;
  product_count: number;
  cover_image_url: string | null;
}

/** GET /admin/collections caps `limit` at 100; callers page through all. */
export const COLLECTIONS_PAGE_LIMIT = 100;
/** Safety stop for paging (100 × 50 = 5,000 collections). */
const MAX_PAGES = 50;

/** Normalise one admin collection shape. */
export function collectionRowFromApi(c: any): CollectionRow {
  const order = Number(c?.display_order);
  const count = Number(c?.product_count);
  return {
    id: Number(c?.id) || 0,
    name: String(c?.name ?? c?.collection ?? '').trim() || '—',
    slug: typeof c?.slug === 'string' ? c.slug : '',
    is_active: c?.is_active === true,
    display_order: c?.display_order == null || !Number.isFinite(order) ? null : order,
    product_count: Number.isFinite(count) && count >= 0 ? count : 0,
    cover_image_url: typeof c?.cover_image_url === 'string' && c.cover_image_url ? c.cover_image_url : null,
  };
}

/** A list payload (`{ data: [...] }` or `{ data: { items } }`) → rows. */
export function collectionRowsFromApi(res: any): CollectionRow[] {
  const raw: any[] = Array.isArray(res?.data) ? res.data : res?.data?.items ?? [];
  return raw.map(collectionRowFromApi).filter((r) => r.id > 0);
}

/**
 * EVERY collection in storefront order (display_order ASC, NULLS LAST, then
 * id DESC, as the API lists them), paging with limit 100 until the reported
 * total is reached.
 */
export function fetchAllCollections(adapter: PortalCrudAdapter): Observable<CollectionRow[]> {
  const page = (offset: number, index: number) =>
    adapter.get_v3('GET /admin/collections', { query: { limit: COLLECTIONS_PAGE_LIMIT, offset } }).pipe(
      map((res: any) => {
        const total = Number(res?.meta?.total);
        return { rows: collectionRowsFromApi(res), total: Number.isFinite(total) ? total : null, offset, index };
      }),
    );
  return page(0, 0).pipe(
    expand((p) => {
      const next = p.offset + COLLECTIONS_PAGE_LIMIT;
      const more = p.total != null ? next < p.total : p.rows.length === COLLECTIONS_PAGE_LIMIT;
      return more && p.rows.length > 0 && p.index + 1 < MAX_PAGES ? page(next, p.index + 1) : EMPTY;
    }),
    reduce((all, p) => all.concat(p.rows), [] as CollectionRow[]),
    // A row can repeat if the list shifted between pages; keep the first.
    map((all) => all.filter((r, i) => all.findIndex((o) => o.id === r.id) === i)),
  );
}
