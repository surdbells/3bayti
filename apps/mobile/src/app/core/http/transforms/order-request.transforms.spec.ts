import {
  transformReadOrdersListingRequest,
  AUTHED_GET_REQUEST_TRANSFORMS,
} from './order-request.transforms';

/**
 * Unit tests for M3.1.6i.2 order-list POST-to-GET conversion.
 *
 * Mobile sends:
 *   POST /customer/read_orders_listing
 *   Body: { id, token, limit, offset }
 *
 * v3 expects:
 *   GET /v3/orders?limit=X&offset=Y
 *   Authorization: Bearer <token>
 *
 * The transform extracts limit + offset into queryParams.
 * Auth header extraction is handled by the adapter via
 * translateRequestBody, not this transform.
 *
 * v3 clamps limit to [1, 50]; the transform clamps client-side for
 * faster feedback. offset is clamped to [0, 1,000,000] as a sanity
 * upper bound — an offset that high would mean ~100,000 pages of
 * orders, far beyond any real user scenario.
 */

describe('transformReadOrdersListingRequest', () => {
  it('extracts limit and offset from legacy body', () => {
    const result = transformReadOrdersListingRequest({
      id: 42,
      token: 'tok',
      limit: 25,
      offset: 50,
    });
    expect(result.queryParams).toEqual({
      limit: 25,
      offset: 50,
    });
  });

  it('defaults missing limit to 10 and offset to 0', () => {
    const result = transformReadOrdersListingRequest({
      id: 42,
      token: 'tok',
    });
    expect(result.queryParams).toEqual({
      limit: 10,
      offset: 0,
    });
  });

  it('clamps limit > 50 to 50', () => {
    const result = transformReadOrdersListingRequest({ limit: 999 });
    expect((result.queryParams as Record<string, number>)['limit']).toBe(50);
  });

  it('clamps limit < 1 to 1', () => {
    const result = transformReadOrdersListingRequest({ limit: 0 });
    expect((result.queryParams as Record<string, number>)['limit']).toBe(1);
  });

  it('clamps negative offset to 0', () => {
    const result = transformReadOrdersListingRequest({ offset: -5 });
    expect((result.queryParams as Record<string, number>)['offset']).toBe(0);
  });

  it('coerces string values to numbers', () => {
    const result = transformReadOrdersListingRequest({
      limit: '20',
      offset: '40',
    });
    expect(result.queryParams).toEqual({
      limit: 20,
      offset: 40,
    });
  });

  it('handles non-numeric input gracefully', () => {
    const result = transformReadOrdersListingRequest({
      limit: 'not-a-number',
      offset: null,
    });
    expect(result.queryParams).toEqual({
      limit: 10, // default
      offset: 0,  // default
    });
  });

  it('handles missing / null / non-object body', () => {
    expect(() => transformReadOrdersListingRequest(null)).not.toThrow();
    expect(() => transformReadOrdersListingRequest(undefined)).not.toThrow();
    expect(() => transformReadOrdersListingRequest('string')).not.toThrow();

    // All defaults applied
    expect(transformReadOrdersListingRequest(null).queryParams).toEqual({
      limit: 10,
      offset: 0,
    });
  });

  it('returns no pathParams (orders list takes no path args)', () => {
    const result = transformReadOrdersListingRequest({});
    expect((result as { pathParams?: unknown }).pathParams).toBeUndefined();
  });
});

describe('AUTHED_GET_REQUEST_TRANSFORMS registry', () => {
  it('contains the orders list transform', () => {
    expect(AUTHED_GET_REQUEST_TRANSFORMS['GET /orders']).toBe(
      transformReadOrdersListingRequest,
    );
  });

  it('every entry is a function and tolerates null input', () => {
    for (const [, transform] of Object.entries(AUTHED_GET_REQUEST_TRANSFORMS)) {
      expect(typeof transform).toBe('function');
      expect(() => transform(null)).not.toThrow();
      expect(() => transform({})).not.toThrow();
    }
  });
});
