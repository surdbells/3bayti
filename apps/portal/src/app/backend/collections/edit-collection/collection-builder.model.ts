/**
 * Shapes + parsers shared by the collection product builder (edit-collection),
 * its details panel and the product page's "Collections" section.
 *
 * Products arrive in several API shapes (public listShape from GET /products
 * and GET /admin/collections/:id/products, and the admin vendorDetailShape
 * from GET /admin/products/:id). The parsers here normalise all of them into
 * flat view models so templates never have to dig through `{ amount }` money
 * objects or `{ url }` image objects.
 */

/** One product card: a curated member, or a search/browse hit. */
export interface CuratedProduct {
  /** v3 product id: what PUT /admin/collections/:id/products takes. */
  id: number;
  slug: string;
  name: string;
  /** Primary image URL (null = no image, the card shows a placeholder). */
  image: string | null;
  /** Every image URL the list shape carried (primary first). */
  images: string[];
  /** Regular price. */
  price: number | null;
  /** Set only when the product is genuinely on sale (> 0 and below `price`). */
  sale_price: number | null;
  vendor_name: string | null;
  /** false when the API says the product is out of stock (it won't show). */
  in_stock: boolean | null;
}

/** A collection a product belongs to (contract A / C rows). */
export interface CollectionRef {
  id: number;
  name: string;
  slug: string;
  is_active: boolean;
  /** Position inside that collection (contract C only; 0 = its cover). */
  sort_order?: number | null;
}

/** Extra detail for the details panel, from GET /admin/products/:id. */
export interface ProductDetail {
  name: string;
  images: string[];
  price: number | null;
  sale_price: number | null;
  store_name: string | null;
  category: string | null;
  sku: string | null;
  /** Product status ('active', 'draft', …). */
  status: string | null;
  stock_quantity: number | null;
  stock_status: string | null;
  in_stock: boolean | null;
  /** Plain-text description (HTML stripped), '' when none. */
  description: string;
}

/** Money as `{ amount }` (v3 shape), a number, or a numeric string. */
export function amountOf(v: unknown): number | null {
  const amt = (v as { amount?: unknown } | null)?.amount ?? v;
  if (typeof amt === 'number') return Number.isFinite(amt) ? amt : null;
  if (typeof amt === 'string' && amt.trim() !== '' && !isNaN(Number(amt))) return Number(amt);
  return null;
}

/**
 * The sale price, only when the product is genuinely on sale: present, above
 * zero and below the regular price (the API already nulls a bogus one on the
 * list shape; the admin detail shape does not, so this keeps both honest).
 */
export function salePriceOf(raw: unknown, price: number | null): number | null {
  const sale = amountOf(raw);
  return sale != null && sale > 0 && price != null && sale < price ? sale : null;
}

/** One image entry as a URL: a string, or an `{ url }` object. */
function urlOf(img: unknown): string | null {
  if (typeof img === 'string') return img.trim() || null;
  const url = (img as { url?: unknown } | null)?.url;
  return typeof url === 'string' && url.trim() ? url : null;
}

/**
 * Every image URL a product shape carries, primary first, de-duplicated.
 * GET /v3/products (listShape) returns `primary_image` as an OBJECT
 * ({ url, alt, width, height }), NOT a string; binding that object straight to
 * [src] yields "[object Object]", so every shape goes through urlOf().
 */
export function imagesOf(p: any): string[] {
  const out: string[] = [];
  const push = (u: string | null) => {
    if (u && !u.includes('placeholder') && !out.includes(u)) out.push(u);
  };
  push(urlOf(p?.primary_image));
  push(urlOf(p?.primary_image_url));
  push(urlOf(p?.image));
  if (Array.isArray(p?.images)) p.images.forEach((img: unknown) => push(urlOf(img)));
  return out;
}

/** Normalise any product shape (list, admin, or `{ product }` wrapper) to a card. */
export function productFromApi(p: any): CuratedProduct {
  const src = p?.product ?? p ?? {};
  const price = amountOf(src.price);
  const images = imagesOf(src);
  return {
    id: Number(src.id) || 0,
    slug: typeof src.slug === 'string' ? src.slug : '',
    name: typeof src.name === 'string' && src.name.trim() ? src.name : '—',
    image: images[0] ?? null,
    images,
    price,
    sale_price: salePriceOf(src.sale_price, price),
    vendor_name: src.vendor?.name ?? src.store_name ?? null,
    in_stock: typeof src.in_stock === 'boolean' ? src.in_stock : null,
  };
}

/** Normalise a product list payload (`{ data: [...] }`, a bare array, or `{ data: { items } }`). */
export function productsFromApi(res: any): CuratedProduct[] {
  const raw: any[] = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : res?.data?.items ?? [];
  return raw.map((p) => productFromApi(p)).filter((p) => p.id > 0);
}

/** Strip HTML (rich-text descriptions) down to readable plain text. */
export function plainText(html: unknown): string {
  if (typeof html !== 'string' || !html) return '';
  const withBreaks = html.replace(/<\s*(br|\/p|\/div|\/li|\/h[1-6])\s*\/?>/gi, '\n');
  const stripped = withBreaks.replace(/<[^>]*>/g, '');
  const decoded = stripped
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'");
  return decoded.replace(/[ \t]+/g, ' ').replace(/\n\s*\n+/g, '\n').trim();
}

/** GET /admin/products/:id (vendorDetailShape) → panel detail. */
export function detailFromApi(res: any): ProductDetail {
  const p = res?.data ?? res ?? {};
  const price = amountOf(p.price);
  const qty = Number(p.stock_quantity ?? p.quantity);
  return {
    name: typeof p.name === 'string' ? p.name : '',
    images: imagesOf(p),
    price,
    sale_price: salePriceOf(p.sale_price, price),
    store_name: p.store_name ?? p.vendor?.name ?? null,
    category: typeof p.category === 'string' ? p.category : p.category?.name ?? null,
    sku: typeof p.sku === 'string' && p.sku.trim() ? p.sku : null,
    status: typeof p.status === 'string' && p.status ? p.status : null,
    stock_quantity: Number.isFinite(qty) ? qty : null,
    stock_status: typeof p.stock_status === 'string' && p.stock_status ? p.stock_status : null,
    in_stock: typeof p.in_stock === 'boolean' ? p.in_stock : null,
    description: plainText(p.description),
  };
}

/** One collection row (contract A / C) → CollectionRef (null when unusable). */
export function collectionRefFromApi(c: any): CollectionRef | null {
  const id = Number(c?.id);
  if (!Number.isInteger(id) || id <= 0) return null;
  const sort = Number(c?.sort_order);
  return {
    id,
    name: String(c?.name ?? c?.collection ?? '').trim() || `Collection ${id}`,
    slug: typeof c?.slug === 'string' ? c.slug : '',
    is_active: c?.is_active !== false,
    sort_order: c?.sort_order == null || !Number.isFinite(sort) ? null : sort,
  };
}

/** A list of collection rows (contract C / D response, or a bare array). */
export function collectionRefsFromApi(res: any): CollectionRef[] {
  const raw: any[] = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : [];
  return raw.map(collectionRefFromApi).filter((c): c is CollectionRef => c !== null);
}

/**
 * Contract A response → product id → collections. Every requested id gets an
 * entry (missing keys → [] so an unassigned product is "known, in none"
 * rather than "still loading").
 */
export function membershipMapFromApi(res: any, requested: number[]): Map<number, CollectionRef[]> {
  const body = res?.data ?? res ?? {};
  const out = new Map<number, CollectionRef[]>();
  for (const id of requested) {
    const rows = body && typeof body === 'object' ? body[String(id)] : null;
    out.set(
      id,
      Array.isArray(rows) ? rows.map(collectionRefFromApi).filter((c): c is CollectionRef => c !== null) : [],
    );
  }
  return out;
}

/** "Also in 1 collection" / "Also in 3 collections". */
export function alsoInLabel(count: number): string {
  return `Also in ${count} collection${count === 1 ? '' : 's'}`;
}
