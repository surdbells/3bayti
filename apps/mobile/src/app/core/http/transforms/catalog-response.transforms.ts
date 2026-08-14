/**
 * Catalog response transforms — v3 shape to legacy shape.
 *
 * Why this file exists
 * ====================
 * Mobile pages bind to legacy field names: a product card reads
 * `best_seller.product_id`, `best_seller.image_1`, etc. v3 returns
 * canonical names: `id`, `primary_image: {url, alt, width, height}`,
 * `price: {amount, currency}`, etc. Without a shape transform between
 * the wire and the page, every binding silently breaks (renders
 * empty/undefined or, worse, `[object Object]`).
 *
 * The adapter applies the transform AFTER `toLegacyEnvelope` wraps the
 * v3 payload, by looking up CATALOG_RESPONSE_TRANSFORMS by routeKey.
 * This mirrors the request-side pattern in
 * catalog-request.transforms.ts.
 *
 * Three target shapes
 * ===================
 * Most catalog endpoints fall into one of three response categories:
 *
 *   listShape          — array of products (cards): new_arrivals,
 *                        new_arrivals_listing, featured, explore_listing,
 *                        category_listing, vendors_products_listing,
 *                        store_latest
 *
 *   detailShape        — single product with full size/color/image
 *                        detail: single_product, singleProductUtility
 *
 *   vendorShape        — vendor metadata: read_vendor
 *
 * The transforms below produce one mobile-shaped object (or array)
 * per category. They are PURE — no I/O, no side effects, deterministic.
 *
 * Retirement
 * ==========
 * Same as the request transforms: this entire file is M3.1.5-era compat.
 * Once mobile is rebuilt against v3-native semantics (M3.1.10+), call
 * sites will consume the v3 shape directly and these transforms can be
 * deleted in one cleanup commit.
 */

/* ============================================================== *
 * Types
 * ============================================================== */

/**
 * A response transform takes the v3 envelope's `data` field and
 * returns a legacy-shaped equivalent. The adapter calls this AFTER
 * `toLegacyEnvelope` has already unwrapped the v3 outer envelope, so
 * the input here is just the data payload.
 *
 * For list endpoints v3 returns `{data: [...], meta: {...}}` and the
 * adapter currently surfaces just `data` to the call site; for detail
 * endpoints it's `{data: {...}}`. So transforms receive either an
 * object or an array depending on the endpoint. Each transform's
 * signature documents which.
 */
export type ResponseTransform = (data: unknown) => unknown;

/* ============================================================== *
 * Shared utilities
 * ============================================================== */

function isRecord(v: unknown): v is Record<string, unknown> {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function asString(v: unknown, fallback = ''): string {
  if (typeof v === 'string') return v;
  if (typeof v === 'number' && Number.isFinite(v)) return String(v);
  return fallback;
}

function asNumber(v: unknown, fallback = 0): number {
  if (typeof v === 'number' && Number.isFinite(v)) return v;
  if (typeof v === 'string' && v.trim() !== '') {
    const n = Number(v);
    if (Number.isFinite(n)) return n;
  }
  return fallback;
}

/**
 * Like asNumber but preserves null/missing — used for fields where
 * "no value" is semantically distinct from "zero" (e.g. label_id
 * NULL means "product has no label"; label_id 0 isn't a valid id).
 */
function asNumberOrNull(v: unknown): number | null {
  if (v === null || v === undefined) return null;
  if (typeof v === 'number' && Number.isFinite(v)) return v;
  if (typeof v === 'string' && v.trim() !== '') {
    const n = Number(v);
    if (Number.isFinite(n)) return n;
  }
  return null;
}

/**
 * Extract a flat price number from v3's `{amount, currency}` shape.
 * v3 always returns price as an object; this function handles the rare
 * case where data is malformed (returns 0 rather than throwing).
 */
function flatPrice(v: unknown): number {
  if (isRecord(v) && typeof v['amount'] === 'number') {
    return v['amount'];
  }
  return asNumber(v, 0);
}

/**
 * Extract a flat image URL string from v3's
 * `{url, alt, width, height}` shape. v3 returns null when no image —
 * we surface empty string so mobile's `[src]="..."` binding produces
 * an inert src attribute rather than `[src]="null"`.
 */
function flatImageUrl(v: unknown): string {
  if (isRecord(v) && typeof v['url'] === 'string') {
    return v['url'];
  }
  return '';
}

/**
 * Build a list of v3 image URLs from the detail-shape `images` array.
 * v3 always returns an array; we surface a list of bare URLs so
 * legacy callers that did `.split(',')` can be migrated to direct
 * array access in their page logic (or — for M3.1.5 — we surface as
 * a comma-joined string to match legacy's wire shape exactly).
 */
function imagesAsCsvString(v: unknown): string {
  if (!Array.isArray(v)) return '';
  const urls: string[] = [];
  for (const item of v) {
    if (isRecord(item) && typeof item['url'] === 'string') {
      urls.push(item['url']);
    } else if (typeof item === 'string') {
      urls.push(item);
    }
  }
  return urls.join(',');
}

/**
 * Build a comma-separated colors string from v3's array of
 * `{label, hex_code, in_stock}` objects. Same rationale as
 * imagesAsCsvString — legacy page code does `.split(',')`.
 */
function colorsAsCsvString(v: unknown): string {
  if (!Array.isArray(v)) return '';
  const labels: string[] = [];
  for (const item of v) {
    if (isRecord(item) && typeof item['label'] === 'string') {
      labels.push(item['label']);
    } else if (typeof item === 'string') {
      labels.push(item);
    }
  }
  return labels.join(',');
}

/**
 * Vendor display name from v3's `vendor: {slug, name}` embed in list
 * shape. Returns empty string when missing so card UI doesn't break.
 */
function vendorName(v: unknown): string {
  if (isRecord(v) && typeof v['name'] === 'string') {
    return v['name'];
  }
  return '';
}

/**
 * Format price as the legacy `price_formated` string — amount only ("299"
 * / "299.50"), no currency prefix. The PDP (the sole consumer) renders its
 * own Dirham glyph in front of this value, so emitting the currency here
 * would double it ("Ð AED 490"). We synthesize from v3's structured price.
 */
function formatPrice(price: unknown): string {
  if (isRecord(price)) {
    const amount = asNumber(price['amount'], 0);
    // Integer formatting matches legacy ("299" not "299.00") for
    // whole-number prices; preserves cents otherwise.
    const amountStr = Number.isInteger(amount) ? String(amount) : amount.toFixed(2);
    return amountStr;
  }
  return '';
}

/* ============================================================== *
 * List-shape transform (product cards)
 * ============================================================== */

/**
 * Mapping table for the 22 legacy size_* boolean flags. Mirrors the
 * SIZE_COLUMN_MAP in apps/api/src/Migration/MigrationSteps.php — kept
 * in sync by convention. Each entry maps a mobile field key to the
 * v3 size-label string the migration emitted.
 *
 * Note: legacy `size_normal` has no v3 equivalent (it wasn't migrated
 * per the SIZE_COLUMN_MAP). The transform always emits `size_normal:
 * false`, which is a minor UX regression flagged in the M3.1.5
 * device-test checklist.
 */
const SIZE_FLAG_TO_LABEL: Record<string, string> = {
  size_xs: 'XS', size_s: 'S', size_m: 'M', size_l: 'L',
  size_xl: 'XL', size_xxl: 'XXL',
  size_50: '50', size_51: '51', size_52: '52', size_53: '53',
  size_54: '54', size_55: '55', size_56: '56', size_57: '57',
  size_58: '58', size_59: '59', size_60: '60', size_61: '61',
  size_62: '62', size_63: '63', size_64: '64',
  size_custom: 'CUSTOM',
};

/**
 * Synthesize the 22 legacy size_* boolean flags from v3's
 * sizes: [{label, in_stock}] array. Each flag is true iff the label
 * is present in the v3 array AND in_stock is true.
 *
 * Returns an object with all 22 keys explicitly set so consumer pages
 * that do property-access (`single.size_xl`) get a boolean rather than
 * undefined.
 */
function synthesizeSizeFlags(v3Sizes: unknown): Record<string, boolean> {
  const flags: Record<string, boolean> = {};
  // Initialise all to false; legacy `size_normal` stays false (see note above).
  for (const flagKey of Object.keys(SIZE_FLAG_TO_LABEL)) {
    flags[flagKey] = false;
  }
  flags['size_normal'] = false;

  if (!Array.isArray(v3Sizes)) return flags;

  for (const item of v3Sizes) {
    if (!isRecord(item)) continue;
    const label = item['label'];
    const inStock = item['in_stock'];
    if (typeof label !== 'string') continue;
    // Find which flag key this label maps to.
    for (const [flagKey, labelValue] of Object.entries(SIZE_FLAG_TO_LABEL)) {
      if (labelValue === label) {
        flags[flagKey] = inStock === true;
        break;
      }
    }
  }
  return flags;
}

/**
 * Map a single v3 product list-item to the legacy product-card shape
 * mobile pages bind against.
 *
 * Legacy card fields mobile reads:
 *   product_id, product_name, image_1, price, store_name
 *
 * v3 list-shape fields (input):
 *   {id, slug, name, sku, price: {amount, currency},
 *    sale_price, primary_image: {url, alt, w, h},
 *    category_slug, vendor: {slug, name}, rating, review_count,
 *    in_stock, is_new, is_bestseller}
 */
function legacyProductCardFromV3List(item: unknown): Record<string, unknown> {
  if (!isRecord(item)) return {};
  return {
    // The single-product page resolves via /v3/products/by-id/{id}, so the
    // card carries the v3 id. Storefront is fully off legacy ids; v3-native
    // products have no legacy_product_id and used to tap through to NOT_FOUND.
    product_id: asNumber(item['id']),
    product_name: asString(item['name']),
    image_1: flatImageUrl(item['primary_image']),
    price: flatPrice(item['price']),
    // Sale price (ON-SALE card rendering). v3 list items carry sale_price
    // as a `{amount, currency}` object (or null). Mirror the detail
    // transform: flatten to a bare number when present, null otherwise, so
    // card templates can test `sale_price > 0 && sale_price < price`.
    sale_price: item['sale_price'] != null ? flatPrice(item['sale_price']) : null,
    store_name: vendorName(item['vendor']),
    // Vendor legacy store id — the vendor badge navigates to the storefront
    // via /vendors?id={id} (GET /v3/vendors/by-legacy-id/{id}), exactly like
    // the working featured-vendor card (account.page.html). The two consumers
    // read DIFFERENT field names off the card: the search page reads `store`
    // (search.page.html), the explore/vertican card reads `store_id`
    // (vertican.page.html). Emit BOTH from the same legacy id so each
    // navigates identically. 0 when the vendor has no legacy row (v3-native),
    // matching featuredShape's null store_id — the storefront guard skips a
    // by-legacy-id/0 fetch. Requires ProductSerializer::listShape to embed
    // vendor.legacy_id.
    store: isRecord(item['vendor']) ? asNumber(item['vendor']['legacy_id'], 0) : 0,
    store_id: isRecord(item['vendor']) ? asNumber(item['vendor']['legacy_id'], 0) : 0,
    // Secondary bindings some pages may use (vendors.page reads
    // `vendor_products.image` not `vendor_products.image_1`):
    image: flatImageUrl(item['primary_image']),
    // Gallery for the explore carousel. vertican.page getProductImages()
    // reads `images` (CSV string -> split, or array) and swipes/dots over
    // it, falling back to [image_1] when absent. The PDP detail shape
    // surfaces the full gallery under `images` (array of {url, ...}); the
    // list shape uses the same field name WHEN the backend includes it.
    // We map it via the same imagesAsCsvString helper detailShape uses, so
    // the carousel works the instant the backend listShape surfaces it.
    // NOTE: as of this change ProductSerializer::listShape (apps/api) does
    // NOT emit `images` (only `primary_image`), so item['images'] is
    // undefined and this resolves to '' -> getProductImages falls back to a
    // single image. A backend change is required to populate the gallery
    // here (see ProductSerializer::listShape ~line 188-225: add
    // 'images' => $this->imagesArray($p)).
    images: imagesAsCsvString(item['images']),
    // Pass-through fields that are useful and unambiguous:
    slug: asString(item['slug']),
    in_stock: item['in_stock'] === true,
    // M3.1.5.5 — vendors.page filters products by tapped label via
    // `@if(category.id === product.collection)`. The v3 list shape
    // surfaces label_id under products_by_labels (added in M3.1.5.5g
    // to ProductSerializer.listShape); map it to legacy field name
    // `collection`. Null when the product has no label assigned.
    collection: asNumberOrNull(item['label_id']),
  };
}

/**
 * v3 list-shape array -> legacy card array. Used for new_arrivals,
 * featured, explore_listing, category_listing, vendors_products_listing,
 * store_latest, and (paginated) new_arrivals_listing.
 *
 * Input is expected to be an array (v3's `{data: [...]}` payload, with
 * the outer envelope already stripped by toLegacyEnvelope). If the
 * input isn't an array, returns an empty array so consumer pages see
 * a length-zero list rather than throwing.
 */
export function transformProductListResponse(data: unknown): unknown {
  if (!Array.isArray(data)) return [];
  return data.map(legacyProductCardFromV3List);
}

/* ============================================================== *
 * Detail-shape transform (single product page)
 * ============================================================== */

/**
 * v3 detail-shape -> legacy single-product shape. Used for
 * single_product (customer/) and singleProductUtility (utility/).
 *
 * Legacy detail fields mobile reads (verified via field-binding audit):
 *   product_id, product_name, name, price, price_formated, description,
 *   image_1, images (CSV string), colors (CSV string),
 *   category_id, category_name, stock_status, delivery_time,
 *   custom_delivery_time, extra_msmt, require_extra_msmt,
 *   size_xs/s/m/l/xl/xxl/50..64/custom/normal (22 booleans),
 *   product (= legacy id duplicate), store (= legacy store id)
 *
 * Gaps (v3 doesn't currently emit these in detailShape):
 *   - category_id, category_name (v3 emits category_slug only)
 *   - delivery_time, custom_delivery_time, extra_msmt,
 *     require_extra_msmt (entity has the data but serializer doesn't
 *     surface it — could be added in M4 hardening if mobile UX
 *     regresses noticeably)
 *   - store (legacy integer store_id) — v3 emits vendor: {slug, name};
 *     no integer id is exposed
 *   - stock_status (string) — v3 emits in_stock (boolean); we coerce
 *     'in_stock' or 'out_of_stock' as a best-effort string
 *
 * For each gap, the transform emits a safe default (empty string,
 * false, or 0). The device-test checklist flags these gaps so a real
 * device run can classify them by UX impact.
 */
export function transformProductDetailResponse(data: unknown): unknown {
  if (!isRecord(data)) return {};

  // Synthesize the 22 size boolean flags from v3's sizes array.
  const sizeFlags = synthesizeSizeFlags(data['sizes']);

  return {
    // Legacy id pair — mobile reads BOTH product_id and product:
    product_id: asNumber(data['id']),
    product: asNumber(data['id']),

    // Display fields:
    product_name: asString(data['name']),
    name: asString(data['name']),
    description: asString(data['description']),

    // Pricing — flat number for arithmetic + formatted string for display:
    price: flatPrice(data['price']),
    price_formated: formatPrice(data['price']),
    sale_price: data['sale_price'] !== null ? flatPrice(data['sale_price']) : null,

    // Images — primary URL + CSV of all (legacy did `.split(',')` so
    // we surface a CSV string to keep that pattern working):
    image_1: flatImageUrl(data['primary_image']),
    images: imagesAsCsvString(data['images']),

    // Colors — CSV string (same rationale as images):
    colors: colorsAsCsvString(data['colors']),

    // Stock — coerce boolean to legacy string label:
    stock_status: data['in_stock'] === true ? 'in_stock' : 'out_of_stock',
    in_stock: data['in_stock'] === true,

    // Vendor — legacy mobile reads `single.store` as a numeric LEGACY
    // store id, used to resolve the store's published size guide via
    // GET /v3/vendors/by-legacy-id/{store}/size-chart. Use ONLY the
    // legacy_id (not the v3 id) — the size-chart route resolves by legacy
    // id, so a v3-native vendor with no legacy_id must stay 0 so the PDP
    // guard skips the fetch (a v3 id there would 404 the by-legacy-id route).
    store: isRecord(data['vendor'])
      ? asNumber(data['vendor']['legacy_id'], 0)
      : 0,
    vendor_slug: isRecord(data['vendor']) ? asString(data['vendor']['slug']) : '',
    store_name: vendorName(data['vendor']),

    // Category — v3 exposes only slug; legacy id/name are gaps:
    category_id: 0,
    category_name: '',
    category_slug: asString(data['category_slug']),

    // Delivery — v3 detailShape carries a `delivery_info` object
    // ({ time, custom_time, note }, migrated from the legacy delivery_time/
    // custom_delivery_time/delivery_note). Map back to the flat fields the
    // PDP renders. `time` is the legacy window ('1-3', '4-7', 'custom', …);
    // when it's 'custom', the PDP shows `custom_delivery_time` instead.
    delivery_time: isRecord(data['delivery_info']) ? asString(data['delivery_info']['time']) : '',
    custom_delivery_time: isRecord(data['delivery_info']) ? asString(data['delivery_info']['custom_time']) : '',
    extra_msmt: asString(data['measurement_instructions']),
    require_extra_msmt: data['requires_measurement'] === true,

    // Size flags — 22 booleans synthesized from v3's sizes array:
    ...sizeFlags,

    // Slug for callers that want to navigate by slug:
    slug: asString(data['slug']),
  };
}

/* ============================================================== *
 * Vendor-shape transform (read_vendor)
 * ============================================================== */

/**
 * v3 vendor publicShape -> legacy view_vendor shape.
 *
 * Legacy fields mobile reads on the vendor page:
 *   name, logo, cover, description, tagline, following
 *
 * v3 publicShape returns:
 *   {id, slug, name, description, logo_url, cover_image_url, is_verified}
 *
 * Gaps:
 *   - tagline: not in v3 (legacy-only field). Emit empty string.
 *   - following: not in v3 (it's user-relational and requires a
 *     separate per-user query — out of scope for this anonymous read).
 *     Emit false so the page shows the "Follow" button rather than
 *     "Unfollow"; minor UX regression flagged in device test.
 */
export function transformVendorResponse(data: unknown): unknown {
  if (!isRecord(data)) return {};
  return {
    // v3 numeric primary key — REQUIRED by the Follow/Unfollow buttons.
    // POST/DELETE /v3/me/following/{vendorId} resolves the vendor via a
    // v3-PK lookup (em->find), NOT the legacy store id the page is keyed
    // off. Surfacing it here lets the vendor page send the correct id and
    // stops the spurious "vendor not found" 404. 0 when absent so the
    // client's `id > 0` guard short-circuits cleanly.
    id: typeof data['id'] === 'number' ? data['id'] : Number(data['id'] ?? 0),
    name: asString(data['name']),
    logo: asString(data['logo_url']),
    cover: asString(data['cover_image_url']),
    description: asString(data['description']),
    tagline: '',         // not in v3
    following: false,    // not in v3 (anonymous read)
    // Pass-through for callers that want slug-based navigation:
    slug: asString(data['slug']),
    is_verified: data['is_verified'] === true,
  };
}

/**
 * v3 GET /v3/vendors list -> legacy vendor-picker shape. The styles-create
 * page (and any other vendor list consumer) binds `store.store_id` and
 * `store.store_name`; v3 publicShape uses `id`/`name`. We map each item and
 * keep the v3 fields too (pass-through) so slug/logo-aware callers still work.
 */
export function transformVendorListResponse(data: unknown): unknown {
  if (!Array.isArray(data)) return data;
  return data.map((v) => {
    if (!isRecord(v)) return v;
    return {
      ...v,
      store_id: v['id'],
      store_name: asString(v['name']),
      logo: asString(v['logo_url']),
    };
  });
}

/** Human "x ago" from an ISO timestamp, for review cards. */
function relativeTime(iso: string): string {
  if (!iso) return '';
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return '';
  const diff = Date.now() - then;
  if (diff < 60_000) return 'Just now';
  if (diff < 3_600_000) return `${Math.floor(diff / 60_000)}m ago`;
  if (diff < 86_400_000) return `${Math.floor(diff / 3_600_000)}h ago`;
  if (diff < 7 * 86_400_000) return `${Math.floor(diff / 86_400_000)}d ago`;
  return new Date(iso).toLocaleDateString();
}

/**
 * v3 review lists -> legacy review-card shape. The review pages assign
 * response.data directly and bind derived display fields. v3 publicShape
 * gives id/star/title/comment/product_name/reviewer/created_at; we add
 * the derived rating/author/authorInitial/relativeDate/aria. Used for
 * both store reviews and the user's own reviews (extra fields are inert
 * where a template doesn't read them).
 */
export function transformReviewDisplayResponse(data: unknown): unknown {
  if (!Array.isArray(data)) return data;
  return data.map((r) => {
    if (!isRecord(r)) return r;
    const reviewer = asString(r['reviewer']);
    const starNum = Number(r['star'] ?? 0);
    const star = Number.isFinite(starNum) ? starNum : 0;
    return {
      ...r,
      rating: star,
      author: reviewer,
      authorInitial: reviewer ? reviewer.charAt(0).toUpperCase() : '',
      relativeDate: relativeTime(asString(r['created_at'])),
      aria: `${star} out of 5 stars`,
    };
  });
}

/**
 * v3 GET /v3/me/measurements ({ measurements: [{category_id, values:{...}}] })
 * -> legacy read-measurement shape. The measurements + product pages read
 * response.data[0].<field> from the DEFAULT set with the measurement
 * fields flat at the top level, so: pick the default set (category_id
 * null), flatten its `values` to the top level, and return it as a
 * single-element array. v3 stores the same field keys (bust, armhole,
 * shoulder, length, hip, arm, ...) the pages read.
 */
export function transformMeasurementsReadResponse(data: unknown): unknown {
  if (!isRecord(data)) return data;
  const list = Array.isArray(data['measurements']) ? data['measurements'] : [];
  const def =
    list.find((m) => isRecord(m) && (m['category_id'] === null || m['category_id'] === undefined)) ??
    list[0];
  if (!isRecord(def)) return [];
  const values = isRecord(def['values']) ? def['values'] : {};
  return [
    {
      id: def['id'],
      category_id: def['category_id'] ?? null,
      notes: def['notes'] ?? null,
      ...values,
    },
  ];
}

/* ============================================================== *
 * M3.1.5.5 — List shape for vendor labels + styles
 * ============================================================== */

/**
 * v3 vendor-labels list -> legacy `categories` array shape used by
 * vendors.page. Mobile binds:
 *   `category.id` — number, matched against `product.collection`
 *                   in the products-by-labels filter
 *   `category.name` — display name
 *
 * v3 emits {id, slug, name, display_order}. We forward `id` (forced
 * to number) and `name`; slug + display_order pass through for any
 * future caller but the current consumer only needs id + name.
 *
 * Returns empty array if data isn't an array (defensive).
 */
export function transformVendorLabelsResponse(data: unknown): unknown {
  if (!Array.isArray(data)) return [];
  return data.map(legacyCategoryFromV3Label);
}

function legacyCategoryFromV3Label(item: unknown): Record<string, unknown> {
  if (!isRecord(item)) return {};
  return {
    // Primary bindings (vendors.page HTML):
    id: asNumber(item['id']),
    name: asString(item['name']),
    // Pass-through for slug-aware future callers:
    slug: asString(item['slug']),
    display_order: asNumberOrNull(item['display_order']),
  };
}

/**
 * v3 styles list -> legacy styles array shape used by styles.page.
 * Mobile binds:
 *   `style.id` — number
 *   `style.style_name` — display name (note the underscore — legacy
 *                        field, not `name`)
 *   `style.total_price` — string from v3 DECIMAL
 *   `style.products[0..2].image` — image URL strings
 *
 * v3 emits {id, slug, name, description, cover_image_url,
 *           style_type, total_price, products: [{id, slug, name,
 *           primary_image_url, price, display_order}, ...]}.
 *
 * Mapping:
 *   name → style_name (legacy field name)
 *   products[].primary_image_url → products[].image (legacy field)
 *   total_price passes through as-is (string DECIMAL from v3)
 *
 * Slug + description + cover_image_url + style_type pass through for
 * any future caller; mobile's current HTML doesn't bind them but
 * forwarding them is harmless and future-friendly.
 *
 * Returns empty array if data isn't an array (defensive).
 */
export function transformStylesListResponse(data: unknown): unknown {
  if (!Array.isArray(data)) return [];
  return data.map(legacyStyleFromV3Style);
}

/**
 * v3 single-style detail (GET /v3/styles/:slug) -> legacy Styles shape.
 *
 * Backs the style-view deep-link / hard-reload path: when router state is
 * wiped, the page re-fetches the style by slug and rebuilds it. v3 returns
 * a SINGLE style object under `data` (detailShape), so this reshapes one
 * object via the same mapper the list uses — keeping the legacy
 * {style_name, total_price, products[].{image, product_id, ...}} shape
 * (incl. legacy_product_id on each product) identical to the list path.
 *
 * Returns {} if data isn't an object (defensive — the page treats a
 * missing id as "not found" and stays on the loading/empty state).
 */
export function transformStyleDetailResponse(data: unknown): unknown {
  if (!isRecord(data)) return {};
  return legacyStyleFromV3Style(data);
}

function legacyStyleFromV3Style(item: unknown): Record<string, unknown> {
  if (!isRecord(item)) return {};
  const products = Array.isArray(item['products'])
    ? item['products'].map(legacyStyleProductFromV3Product)
    : [];
  return {
    // Primary bindings (styles.page HTML):
    id: asNumber(item['id']),
    style_name: asString(item['name']),
    total_price: asString(item['total_price']),
    products,
    // Pass-through:
    slug: asString(item['slug']),
    description: asString(item['description']),
    cover_image_url: asString(item['cover_image_url']),
    style_type: asString(item['style_type']),
  };
}

function legacyStyleProductFromV3Product(item: unknown): Record<string, unknown> {
  if (!isRecord(item)) return { image: '', product_id: 0 };
  return {
    // The style-view PDP tap opens the product via open_product(product_id),
    // and the PDP loads by v3 id (GET /v3/products/by-id/:id), so navigate
    // with the v3 id. Without this, product_id is undefined -> NaN ->
    // placeholder PDP.
    product_id: asNumber(item['id']),
    // styles.page card binds style.products[N].image:
    image: asString(item['primary_image_url']),
    // style-view summary + cards bind product_name / price:
    product_name: asString(item['name']),
    // Pass-through for any caller that wants to navigate to the
    // product detail page from a style:
    id: asNumber(item['id']),
    slug: asString(item['slug']),
    name: asString(item['name']),
    price: asString(item['price']),
  };
}

/* ============================================================== *
 * Featured-vendors transform (home "Trending stores")
 * ============================================================== */

/**
 * v3 GET /v3/featured-vendors -> legacy "trending stores" Store[] shape the
 * mobile home page binds: {store_id, store_name, store_desc, rating,
 * rating_count, products: [{product_id, product_name, image, price}]}.
 *
 * Mobile navigates by LEGACY ids: store_id opens the store + its reviews
 * (by-legacy-id vendor endpoints); product_id opens the single-product page
 * (by-legacy-id). The v3 featuredShape now surfaces store_id +
 * legacy_product_id + price for exactly this consumer (additive for web).
 *
 * Previously this endpoint was (incorrectly) routed to /v3/products and
 * mapped with transformProductListResponse, yielding a flat product list
 * with no `.products` array — so the store template rendered nothing.
 */
export function transformFeaturedVendorsResponse(data: unknown): unknown {
  if (!Array.isArray(data)) return [];
  return data.map((v) => {
    if (!isRecord(v)) return {};
    const products = Array.isArray(v['products']) ? v['products'] : [];
    return {
      store_id: asNumber(v['store_id']),
      store_name: asString(v['name']),
      store_desc: asString(v['description']),
      rating: v['rating'] ?? null,
      rating_count: asNumber(v['rating_count']),
      products: products.map((p) => {
        if (!isRecord(p)) return {};
        return {
          product_id: asNumber(p['id']),
          product_name: asString(p['name']),
          image: asString(p['image_url']),
          price: asNumber(p['price']),
          slug: asString(p['slug']),
        };
      }),
    };
  });
}

/* ============================================================== *
 * Registry — keyed by routeKey
 * ============================================================== */

export const CATALOG_RESPONSE_TRANSFORMS: Record<string, ResponseTransform> = {
  // List-shape endpoints
  'GET /mobile/new-arrivals': transformProductListResponse,
  'GET /mobile/new-arrivals-listing': transformProductListResponse,
  // Best-sellers route to /v3/products like new-arrivals (sort=best_seller);
  // they were flipped to target:'new' but the transform was never registered,
  // so the raw v3 shape (price object, primary_image object) leaked to the
  // legacy card bindings. Same mapper as new-arrivals.
  'GET /mobile/best-sellers': transformProductListResponse,
  'GET /mobile/best-sellers-listing': transformProductListResponse,
  // Featured = "Trending stores" — a vendor list with embedded products,
  // NOT a flat product list (see transformFeaturedVendorsResponse).
  'GET /mobile/featured': transformFeaturedVendorsResponse,
  // Paginated PUBLIC store directory (GET /v3/vendors). directoryShape now
  // carries the same legacy store_id + per-product legacy_product_id/price as
  // featuredShape, so the exact same transform reshapes it into the Store card.
  'GET /mobile/stores': transformFeaturedVendorsResponse,
  'GET /mobile/explore-listing': transformProductListResponse,
  'GET /mobile/category-listing': transformProductListResponse,
  'GET /mobile/vendors-products': transformProductListResponse,
  'GET /mobile/store-latest': transformProductListResponse,

  // Detail-shape endpoints
  'GET /mobile/single-product': transformProductDetailResponse,
  'GET /mobile/single-product-utility': transformProductDetailResponse,

  // Vendor-shape endpoints
  'GET /mobile/read-vendor': transformVendorResponse,
  'GET /vendors': transformVendorListResponse,
  'GET /vendors/:vendorId/reviews': transformReviewDisplayResponse,
  'GET /me/reviews': transformReviewDisplayResponse,
  'GET /me/measurements': transformMeasurementsReadResponse,

  // M3.1.5.5 catalog additions:
  'GET /mobile/search': transformProductListResponse,            // products list
  'GET /mobile/products-by-labels': transformProductListResponse, // products list
  'GET /mobile/store-labels': transformVendorLabelsResponse,      // labels list
  'GET /mobile/styles-list': transformStylesListResponse,         // styles list
  // "My Styles" tab — owner-scoped styles list (GET /v3/me/styles). Same
  // v3 styles shape as styles-list, so it MUST use the same transform to
  // produce the legacy {style_name, total_price, products[].image} shape.
  'GET /mobile/my-styles': transformStylesListResponse,           // styles list (owner-scoped)
  // Single style by slug (deep-link / hard-reload re-fetch). v3 returns one
  // style object under `data`; reshape it to the legacy Styles shape.
  'GET /mobile/style-detail': transformStyleDetailResponse,       // single style
};
