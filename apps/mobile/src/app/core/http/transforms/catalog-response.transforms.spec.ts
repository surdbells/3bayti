import {
  transformProductListResponse,
  transformProductDetailResponse,
  transformVendorResponse,
  CATALOG_RESPONSE_TRANSFORMS,
} from './catalog-response.transforms';

/**
 * Unit tests for the M3.1.5d catalog response transforms.
 *
 * Per the M3.1.2 / M3.1.4 closeouts: mobile CI runs type-check + build
 * only. These tests compile-check the transforms against the v3
 * response shape but aren't executed in CI. They DO run locally with
 * `pnpm --filter @3bayti/mobile test`.
 *
 * Coverage strategy
 * =================
 * Each transform gets:
 *   - A happy path with a canonical v3 payload
 *   - Tests for the specific shape mappings mobile cares about
 *     (price flattening, image-URL flattening, vendor-name extraction,
 *     size-flag synthesis, CSV string building)
 *   - Defensive tests (null/missing/wrong-type inputs return safe
 *     defaults rather than throwing)
 *
 * The registry itself gets a structural test: every routeKey has a
 * function and that function handles an empty input without throwing.
 */

describe('transformProductListResponse', () => {
  it('maps a single v3 list-item to legacy card shape', () => {
    const v3 = [
      {
        id: 42,
        slug: 'silk-abaya',
        name: 'Silk Abaya',
        sku: null,
        price: { amount: 299, currency: 'AED' },
        sale_price: null,
        primary_image: { url: 'https://cdn/foo.jpg', alt: 'Silk Abaya', width: null, height: null },
        category_slug: 'abayas',
        vendor: { slug: 'almas-fashion', name: 'Almas Fashion' },
        rating: null,
        review_count: 0,
        in_stock: true,
        is_new: false,
        is_bestseller: false,
      },
    ];
    const result = transformProductListResponse(v3) as Array<Record<string, unknown>>;
    expect(result.length).toBe(1);

    const card = result[0];
    expect(card['product_id']).toBe(42);
    expect(card['product_name']).toBe('Silk Abaya');
    expect(card['image_1']).toBe('https://cdn/foo.jpg');
    expect(card['price']).toBe(299);
    expect(card['store_name']).toBe('Almas Fashion');
    expect(card['in_stock']).toBe(true);
    expect(card['slug']).toBe('silk-abaya');
  });

  it('returns empty array for non-array input', () => {
    expect(transformProductListResponse(null)).toEqual([]);
    expect(transformProductListResponse({})).toEqual([]);
    expect(transformProductListResponse('not an array')).toEqual([]);
  });

  it('synthesises the secondary image field for vendors.page consumer', () => {
    // vendors.page reads `vendor_products.image`, not `vendor_products.image_1`.
    // The transform emits both keys for compatibility.
    const v3 = [
      { id: 1, slug: 's', name: 'N', price: { amount: 10, currency: 'AED' }, primary_image: { url: 'x.jpg', alt: 'N' }, vendor: { slug: 'v', name: 'V' } },
    ];
    const card = (transformProductListResponse(v3) as Array<Record<string, unknown>>)[0];
    expect(card['image']).toBe('x.jpg');
    expect(card['image_1']).toBe('x.jpg');
  });

  it('handles missing primary_image gracefully (empty string)', () => {
    const v3 = [
      { id: 1, slug: 's', name: 'N', price: { amount: 10, currency: 'AED' }, primary_image: null, vendor: { slug: 'v', name: 'V' } },
    ];
    const card = (transformProductListResponse(v3) as Array<Record<string, unknown>>)[0];
    expect(card['image_1']).toBe('');
  });

  it('handles missing vendor gracefully (empty store_name)', () => {
    const v3 = [
      { id: 1, slug: 's', name: 'N', price: { amount: 10, currency: 'AED' }, primary_image: { url: 'x' }, vendor: null },
    ];
    const card = (transformProductListResponse(v3) as Array<Record<string, unknown>>)[0];
    expect(card['store_name']).toBe('');
  });
});

describe('transformProductDetailResponse', () => {
  const sampleV3Detail = {
    id: 42,
    slug: 'silk-abaya',
    name: 'Silk Abaya',
    sku: null,
    price: { amount: 299, currency: 'AED' },
    sale_price: { amount: 249, currency: 'AED' },
    primary_image: { url: 'https://cdn/p.jpg', alt: 'Silk Abaya', width: null, height: null },
    category_slug: 'abayas',
    vendor: { slug: 'almas-fashion', name: 'Almas Fashion' },
    rating: 4.5,
    review_count: 10,
    in_stock: true,
    is_new: false,
    is_bestseller: false,
    description: 'A luxurious silk abaya...',
    images: [
      { url: 'https://cdn/p.jpg', alt: 'p', width: null, height: null },
      { url: 'https://cdn/p2.jpg', alt: 'p', width: null, height: null },
    ],
    sizes: [
      { label: 'S', in_stock: true },
      { label: 'M', in_stock: true },
      { label: 'L', in_stock: false },
    ],
    colors: [
      { label: 'Black', hex_code: null, in_stock: true },
      { label: 'Navy', hex_code: null, in_stock: true },
    ],
    fabric: null,
    care_instructions: null,
    materials: [],
    related_products: [],
    recent_reviews: [],
  };

  it('maps the full detail shape with all critical legacy fields', () => {
    const r = transformProductDetailResponse(sampleV3Detail) as Record<string, unknown>;

    // Duplicate id fields:
    expect(r['product_id']).toBe(42);
    expect(r['product']).toBe(42);

    // Names:
    expect(r['product_name']).toBe('Silk Abaya');
    expect(r['name']).toBe('Silk Abaya');

    // Description:
    expect(r['description']).toBe('A luxurious silk abaya...');

    // Pricing — flat number AND formatted string:
    expect(r['price']).toBe(299);
    expect(r['price_formated']).toBe('AED 299');
    expect(r['sale_price']).toBe(249);

    // Slug + canonical image:
    expect(r['slug']).toBe('silk-abaya');
    expect(r['image_1']).toBe('https://cdn/p.jpg');

    // Images CSV (legacy .split(',') compat):
    expect(r['images']).toBe('https://cdn/p.jpg,https://cdn/p2.jpg');

    // Colors CSV:
    expect(r['colors']).toBe('Black,Navy');

    // Stock:
    expect(r['stock_status']).toBe('in_stock');
    expect(r['in_stock']).toBe(true);

    // Vendor:
    expect(r['store']).toBe(0); // M3.1.5 gap — flagged
    expect(r['store_name']).toBe('Almas Fashion');
    expect(r['vendor_slug']).toBe('almas-fashion');

    // Category gaps:
    expect(r['category_id']).toBe(0);
    expect(r['category_name']).toBe('');
    expect(r['category_slug']).toBe('abayas');

    // Measurement/delivery gaps:
    expect(r['delivery_time']).toBe('');
    expect(r['custom_delivery_time']).toBe('');
    expect(r['extra_msmt']).toBe('');
    expect(r['require_extra_msmt']).toBe(false);
  });

  it('synthesises all 22 legacy size flags from v3 sizes array', () => {
    const r = transformProductDetailResponse(sampleV3Detail) as Record<string, unknown>;

    // Present in v3 sizes -> true:
    expect(r['size_s']).toBe(true);
    expect(r['size_m']).toBe(true);

    // Present in v3 sizes but in_stock=false -> false:
    expect(r['size_l']).toBe(false);

    // Absent from v3 sizes -> false:
    expect(r['size_xs']).toBe(false);
    expect(r['size_xl']).toBe(false);
    expect(r['size_xxl']).toBe(false);
    expect(r['size_50']).toBe(false);
    expect(r['size_64']).toBe(false);
    expect(r['size_custom']).toBe(false);

    // Legacy-only field with no v3 equivalent:
    expect(r['size_normal']).toBe(false);
  });

  it('formats decimal prices to two decimals', () => {
    const r = transformProductDetailResponse({
      ...sampleV3Detail,
      price: { amount: 299.5, currency: 'AED' },
    }) as Record<string, unknown>;
    expect(r['price_formated']).toBe('AED 299.50');
    expect(r['price']).toBe(299.5);
  });

  it('returns empty object for non-record input', () => {
    expect(transformProductDetailResponse(null)).toEqual({});
    expect(transformProductDetailResponse([])).toEqual({});
    expect(transformProductDetailResponse('not an object')).toEqual({});
  });

  it('handles null sale_price by emitting null', () => {
    const r = transformProductDetailResponse({
      ...sampleV3Detail,
      sale_price: null,
    }) as Record<string, unknown>;
    expect(r['sale_price']).toBeNull();
  });

  it('handles empty sizes array by emitting all flags false', () => {
    const r = transformProductDetailResponse({
      ...sampleV3Detail,
      sizes: [],
    }) as Record<string, unknown>;
    expect(r['size_s']).toBe(false);
    expect(r['size_m']).toBe(false);
    expect(r['size_50']).toBe(false);
  });
});

describe('transformVendorResponse', () => {
  it('maps v3 publicShape to legacy view_vendor shape', () => {
    const v3 = {
      id: 7,
      slug: 'almas-fashion',
      name: 'Almas Fashion',
      description: 'Premium modest wear',
      logo_url: 'https://cdn/logo.jpg',
      cover_image_url: 'https://cdn/cover.jpg',
      is_verified: true,
    };
    const r = transformVendorResponse(v3) as Record<string, unknown>;

    expect(r['name']).toBe('Almas Fashion');
    expect(r['logo']).toBe('https://cdn/logo.jpg');
    expect(r['cover']).toBe('https://cdn/cover.jpg');
    expect(r['description']).toBe('Premium modest wear');
    expect(r['slug']).toBe('almas-fashion');
    expect(r['is_verified']).toBe(true);

    // Documented gaps — emit safe defaults:
    expect(r['tagline']).toBe('');
    expect(r['following']).toBe(false);
  });

  it('returns empty object for non-record input', () => {
    expect(transformVendorResponse(null)).toEqual({});
    expect(transformVendorResponse('string')).toEqual({});
  });

  it('handles missing logo/cover by emitting empty strings', () => {
    const r = transformVendorResponse({
      slug: 's', name: 'N', description: '',
      logo_url: null, cover_image_url: null,
      is_verified: false,
    }) as Record<string, unknown>;
    expect(r['logo']).toBe('');
    expect(r['cover']).toBe('');
  });
});

describe('CATALOG_RESPONSE_TRANSFORMS registry', () => {
  it('contains exactly 10 entries (one per mobile catalog endpoint)', () => {
    expect(Object.keys(CATALOG_RESPONSE_TRANSFORMS).length).toBe(10);
  });

  it('every entry is callable and safely handles minimal input', () => {
    for (const [key, fn] of Object.entries(CATALOG_RESPONSE_TRANSFORMS)) {
      expect(typeof fn).toBe('function');
      // Smoke-test: each transform handles empty input without throwing.
      // Returned shape varies (array for list, object for detail/vendor)
      // so we only assert "doesn't throw".
      expect(() => fn({})).not.toThrow();
      expect(() => fn(null)).not.toThrow();
      expect(() => fn([])).not.toThrow();
      void key;
    }
  });

  it('list endpoints share transformProductListResponse', () => {
    const listEndpoints = [
      'GET /mobile/new-arrivals',
      'GET /mobile/new-arrivals-listing',
      'GET /mobile/featured',
      'GET /mobile/explore-listing',
      'GET /mobile/category-listing',
      'GET /mobile/vendors-products',
      'GET /mobile/store-latest',
    ];
    for (const key of listEndpoints) {
      expect(CATALOG_RESPONSE_TRANSFORMS[key]).toBe(transformProductListResponse);
    }
  });

  it('detail endpoints share transformProductDetailResponse', () => {
    expect(CATALOG_RESPONSE_TRANSFORMS['GET /mobile/single-product']).toBe(transformProductDetailResponse);
    expect(CATALOG_RESPONSE_TRANSFORMS['GET /mobile/single-product-utility']).toBe(transformProductDetailResponse);
  });

  it('vendor endpoint uses transformVendorResponse', () => {
    expect(CATALOG_RESPONSE_TRANSFORMS['GET /mobile/read-vendor']).toBe(transformVendorResponse);
  });
});
