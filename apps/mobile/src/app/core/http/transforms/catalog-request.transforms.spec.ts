import {
  transformNewArrivalsRequest,
  transformNewArrivalsListingRequest,
  transformFeaturedRequest,
  transformExploreListingRequest,
  transformCategoryListingRequest,
  transformSingleProductRequest,
  transformSingleProductUtilityRequest,
  transformVendorsProductsListingRequest,
  transformReadVendorRequest,
  transformStoreLatestRequest,
  asRecord,
  stripLegacyAuthFields,
  CATALOG_REQUEST_TRANSFORMS,
} from './catalog-request.transforms';

/**
 * Unit tests for the M3.1.5c catalog request transforms.
 *
 * Per the M3.1.2 / M3.1.4 closeouts: mobile CI runs type-check + build
 * only, not Jasmine. These tests are compile-checked but not executed
 * in CI. They DO run locally with `pnpm --filter @3bayti/mobile test`
 * when the runner is set up (M4 hardening).
 *
 * Coverage strategy
 * =================
 * Each transform gets:
 *   - The "happy path" with a canonical legacy body
 *   - Behaviour when the legacy auth fields (id, token) are dropped
 *   - Edge cases specific to that transform (e.g. category: 0 sentinel,
 *     missing optional fields, string-vs-number id)
 *
 * The registry itself gets a structural test: every routeKey in the
 * registry resolves to a function (no nulls or missing entries).
 */

describe('asRecord', () => {
  it('returns the input unchanged when it is a plain object', () => {
    expect(asRecord({ a: 1 })).toEqual({ a: 1 });
  });
  it('returns an empty object for null/undefined/primitives/arrays', () => {
    expect(asRecord(null)).toEqual({});
    expect(asRecord(undefined)).toEqual({});
    expect(asRecord('string')).toEqual({});
    expect(asRecord(123)).toEqual({});
    expect(asRecord([1, 2, 3])).toEqual({});
  });
});

describe('stripLegacyAuthFields', () => {
  it('removes id and token, preserves other keys', () => {
    const result = stripLegacyAuthFields({ id: 1, token: 'abc', limit: 10, offset: 0 });
    expect(result).toEqual({ limit: 10, offset: 0 });
  });
});

describe('transformNewArrivalsRequest', () => {
  it('produces sort=newest with default limit', () => {
    const result = transformNewArrivalsRequest({ id: 1, token: 'abc' });
    expect(result.queryParams['sort']).toBe('newest');
    expect(result.queryParams['limit']).toBe(10);
  });
  it('drops id and token from output', () => {
    const result = transformNewArrivalsRequest({ id: 1, token: 'abc' });
    expect(result.queryParams['id']).toBeUndefined();
    expect(result.queryParams['token']).toBeUndefined();
  });
});

describe('transformNewArrivalsListingRequest', () => {
  it('forwards limit + offset from the body', () => {
    const result = transformNewArrivalsListingRequest({
      id: 1, token: 't', limit: 20, offset: 40,
    });
    expect(result.queryParams['limit']).toBe(20);
    expect(result.queryParams['offset']).toBe(40);
    expect(result.queryParams['sort']).toBe('newest');
  });
  it('uses defaults when limit/offset are missing', () => {
    const result = transformNewArrivalsListingRequest({ id: 1, token: 't' });
    expect(result.queryParams['limit']).toBe(10);
    expect(result.queryParams['offset']).toBe(0);
  });
  it('forwards maxPrice as max_price when present', () => {
    const result = transformNewArrivalsListingRequest({
      id: 1, token: 't', limit: 10, offset: 0, maxPrice: 5000,
    });
    expect(result.queryParams['max_price']).toBe(5000);
  });
  it('omits max_price when maxPrice is absent', () => {
    const result = transformNewArrivalsListingRequest({
      id: 1, token: 't', limit: 10, offset: 0,
    });
    expect(result.queryParams['max_price']).toBeUndefined();
  });
});

describe('transformFeaturedRequest', () => {
  it('sets featured=true plus limit/offset', () => {
    const result = transformFeaturedRequest({
      id: 1, token: 't', limit: 5, offset: 0,
    });
    expect(result.queryParams['featured']).toBe(true);
    expect(result.queryParams['limit']).toBe(5);
    expect(result.queryParams['offset']).toBe(0);
  });
});

describe('transformExploreListingRequest', () => {
  it('forwards limit + offset, no other filters', () => {
    const result = transformExploreListingRequest({
      id: 1, token: 't', limit: 10, offset: 20,
    });
    expect(result.queryParams['limit']).toBe(10);
    expect(result.queryParams['offset']).toBe(20);
    // Importantly: no sort, no featured filter — explore is "all
    // products" in whatever v3's default order is.
    expect(result.queryParams['sort']).toBeUndefined();
    expect(result.queryParams['featured']).toBeUndefined();
  });
});

describe('transformCategoryListingRequest', () => {
  it('maps category to category_id when category is non-zero', () => {
    const result = transformCategoryListingRequest({
      id: 1, token: 't', category: 5, name: 'Abayas', limit: 10, offset: 0,
    });
    expect(result.queryParams['category_id']).toBe(5);
    expect(result.queryParams['limit']).toBe(10);
    expect(result.queryParams['offset']).toBe(0);
    // `name` is display-only metadata — should be dropped.
    expect(result.queryParams['name']).toBeUndefined();
  });
  it('omits category_id when category is 0 (all-products sentinel)', () => {
    const result = transformCategoryListingRequest({
      id: 1, token: 't', category: 0, name: '', limit: 10, offset: 0,
    });
    expect(result.queryParams['category_id']).toBeUndefined();
  });
  it('forwards maxPrice as max_price', () => {
    const result = transformCategoryListingRequest({
      id: 1, token: 't', category: 5, limit: 10, offset: 0, maxPrice: 3000,
    });
    expect(result.queryParams['max_price']).toBe(3000);
  });
});

describe('transformSingleProductRequest', () => {
  it('moves product id into pathParams.id', () => {
    const result = transformSingleProductRequest({
      id: 1, token: 't', product: 42, product_name: 'Silk Abaya',
    });
    expect(result.pathParams['id']).toBe('42');
  });
  it('accepts stringified product id', () => {
    const result = transformSingleProductRequest({
      id: 1, token: 't', product: '42',
    });
    expect(result.pathParams['id']).toBe('42');
  });
  it('falls back to "0" for missing product (v3 will 404)', () => {
    const result = transformSingleProductRequest({ id: 1, token: 't' });
    expect(result.pathParams['id']).toBe('0');
  });
});

describe('transformSingleProductUtilityRequest', () => {
  it('moves product id into pathParams.id', () => {
    const result = transformSingleProductUtilityRequest({ product: 99 });
    expect(result.pathParams['id']).toBe('99');
  });
  it('works with an empty body', () => {
    const result = transformSingleProductUtilityRequest({});
    expect(result.pathParams['id']).toBe('0');
  });
});

describe('transformVendorsProductsListingRequest', () => {
  it('moves storeId (camelCase) into pathParams.id', () => {
    const result = transformVendorsProductsListingRequest({
      id: 1, token: 't', storeId: 7,
    });
    expect(result.pathParams['id']).toBe('7');
    // No limit/offset supplied → no queryParams field at all
    expect(result.queryParams).toBeUndefined();
  });
  it('forwards limit + offset when present', () => {
    const result = transformVendorsProductsListingRequest({
      id: 1, token: 't', storeId: 7, limit: 50, offset: 100,
    });
    expect(result.queryParams).toEqual({ limit: 50, offset: 100 });
  });
});

describe('transformReadVendorRequest', () => {
  it('moves store_id (snake_case) into pathParams.id', () => {
    const result = transformReadVendorRequest({
      id: 1, token: 't', store_id: 12,
    });
    expect(result.pathParams['id']).toBe('12');
  });
});

describe('transformStoreLatestRequest', () => {
  it('extracts store_id into pathParams, drops label and store_name', () => {
    const result = transformStoreLatestRequest({
      id: 1, token: 't', label: 4, store_id: 8, store_name: 'Almas',
    });
    expect(result.pathParams['id']).toBe('8');
    // We don't expose what the result type DOESN'T include — but if a
    // future change accidentally lifted store_name into the URL, this
    // test would catch the addition of an extra path param.
    expect(Object.keys(result.pathParams)).toEqual(['id']);
  });
});

describe('CATALOG_REQUEST_TRANSFORMS registry', () => {
  it('contains exactly 10 entries (one per active mobile catalog endpoint)', () => {
    expect(Object.keys(CATALOG_REQUEST_TRANSFORMS).length).toBe(10);
  });
  it('every entry is a callable function', () => {
    for (const [key, fn] of Object.entries(CATALOG_REQUEST_TRANSFORMS)) {
      expect(typeof fn).toBe('function');
      // Smoke-test: each transform handles an empty body without throwing.
      // The returned shape varies per transform but the call must succeed.
      const result = fn({});
      expect(result).toBeDefined();
      // For type safety: result should have at least one of pathParams/queryParams.
      const hasOne = result.pathParams !== undefined || result.queryParams !== undefined;
      if (!hasOne) {
        fail(`Transform ${key} returned neither pathParams nor queryParams`);
      }
    }
  });
});
