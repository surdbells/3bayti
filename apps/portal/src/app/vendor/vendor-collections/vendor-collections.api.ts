import { EMPTY, Observable } from 'rxjs';
import { expand, map, reduce } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';

/**
 * Vendor-side collection shapes + loaders.
 *
 * Collections are admin-curated storefront line-ups (collection_products join
 * table). A vendor can put ONLY their own products into ACTIVE collections:
 *   GET /vendor/collections                 active collections + my_product_count
 *   GET/PUT /vendor/collections/:id/products  the vendor's own products in one
 *   GET/PUT /vendor/products/:id/collections  one product's active collections
 * Every write sends the complete set of the vendor's own ids plus the set it
 * read as `expected_*`; the API answers 409 CONFLICT_STALE when that changed.
 * Never the legacy products.collection_id.
 */

/** One active collection as a vendor sees it (GET /vendor/collections item). */
export interface VendorCollectionRow {
  id: number;
  name: string;
  slug: string;
  description: string;
  cover_image_url: string | null;
  /** How many of the caller's own products are in it. */
  my_product_count: number;
}

/** A collection one product is in (GET /vendor/products/:id/collections item). */
export interface VendorCollectionRef {
  id: number;
  name: string;
  slug: string;
}

/** One of the vendor's products (vendorManageShape: GET /vendor/products, GET /vendor/collections/:id/products). */
export interface VendorProductCard {
  /** v3 product id. */
  id: number;
  name: string;
  image: string | null;
  price_formatted: string;
  status: string;
}

/** GET /vendor/collections caps `limit` at 100. */
export const VENDOR_COLLECTIONS_PAGE_LIMIT = 100;
const MAX_PAGES = 50;

export function vendorCollectionRowFromApi(c: any): VendorCollectionRow {
  const count = Number(c?.my_product_count);
  return {
    id: Number(c?.id) || 0,
    name: String(c?.name ?? '').trim() || '—',
    slug: typeof c?.slug === 'string' ? c.slug : '',
    description: typeof c?.description === 'string' ? c.description : '',
    cover_image_url: typeof c?.cover_image_url === 'string' && c.cover_image_url ? c.cover_image_url : null,
    my_product_count: Number.isFinite(count) && count >= 0 ? count : 0,
  };
}

/** `{ data: [...] }` → rows (invalid ids dropped). */
export function vendorCollectionRowsFromApi(res: any): VendorCollectionRow[] {
  const raw: any[] = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : [];
  return raw.map(vendorCollectionRowFromApi).filter((r) => r.id > 0);
}

/** `{ data: [{ id, name, slug }] }` → refs. */
export function vendorCollectionRefsFromApi(res: any): VendorCollectionRef[] {
  const raw: any[] = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : [];
  return raw
    .map((c) => ({
      id: Number(c?.id) || 0,
      name: String(c?.name ?? '').trim() || '—',
      slug: typeof c?.slug === 'string' ? c.slug : '',
    }))
    .filter((c) => c.id > 0);
}

export function vendorProductCardFromApi(p: any): VendorProductCard {
  const price = Number(p?.price?.amount ?? p?.price);
  return {
    id: Number(p?.id) || 0,
    name: String(p?.name ?? '').trim() || '—',
    image: (typeof p?.image === 'string' && p.image) || p?.primary_image?.url || null,
    price_formatted:
      typeof p?.price_formatted === 'string' && p.price_formatted
        ? p.price_formatted
        : Number.isFinite(price) ? `AED ${price.toFixed(2)}` : '',
    status: typeof p?.status === 'string' ? p.status : '',
  };
}

export function vendorProductCardsFromApi(res: any): VendorProductCard[] {
  const raw: any[] = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : [];
  return raw.map(vendorProductCardFromApi).filter((p) => p.id > 0);
}

/** Is this error the API's 409 CONFLICT_STALE precondition failure? */
export function isStaleConflict(err: any): boolean {
  return err?.status === 409 && err?.error?.error?.code === 'CONFLICT_STALE';
}

/** EVERY active collection in storefront order, paging with limit 100. */
export function fetchAllVendorCollections(adapter: PortalCrudAdapter): Observable<VendorCollectionRow[]> {
  const page = (offset: number, index: number) =>
    adapter.get_v3('GET /vendor/collections', { query: { limit: VENDOR_COLLECTIONS_PAGE_LIMIT, offset } }).pipe(
      map((res: any) => {
        const total = Number(res?.meta?.total);
        return { rows: vendorCollectionRowsFromApi(res), total: Number.isFinite(total) ? total : null, offset, index };
      }),
    );
  return page(0, 0).pipe(
    expand((p) => {
      const next = p.offset + VENDOR_COLLECTIONS_PAGE_LIMIT;
      const more = p.total != null ? next < p.total : p.rows.length === VENDOR_COLLECTIONS_PAGE_LIMIT;
      return more && p.rows.length > 0 && p.index + 1 < MAX_PAGES ? page(next, p.index + 1) : EMPTY;
    }),
    reduce((acc, p) => acc.concat(p.rows), [] as VendorCollectionRow[]),
    // A row can repeat if the list shifted between pages; keep the first.
    map((all) => all.filter((r, i) => all.findIndex((o) => o.id === r.id) === i)),
  );
}
