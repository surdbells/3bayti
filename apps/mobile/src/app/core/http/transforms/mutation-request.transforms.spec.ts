import {
  transformAddToCartRequest,
  transformIncreaseItemRequest,
  transformDecreaseItemRequest,
  transformRemoveCartItemRequest,
  transformMergeCartRequest,
  transformInitiatePaymentRequest,
  MUTATION_REQUEST_TRANSFORMS,
} from './mutation-request.transforms';

/**
 * Unit tests for M3.1.6i.2 mutation request transforms.
 *
 * These tests cover the body-reshape + path-param-extraction layer
 * that sits between mobile legacy call sites and v3 mutation
 * endpoints. Each transform is exercised against:
 *
 *   - the canonical legacy body shape (happy path)
 *   - the behaviour of dropped legacy fields (store, customer_name,
 *     product_name, etc. — these have no v3 equivalent and must be
 *     stripped or the v3 controller validates them away)
 *   - path-param extraction (item id moves from body to URL path
 *     for /cart/items/:id endpoints)
 *   - edge cases (missing fields, string-vs-number ids, empty arrays)
 *
 * Per project convention (M3.1.2 / M3.1.4 closeouts), mobile CI runs
 * type-check + build only; these specs are compile-checked but not
 * executed in CI. Run locally with `pnpm --filter @3bayti/mobile test`.
 */

describe('transformAddToCartRequest', () => {
  it('extracts product_id + quantity from legacy body', () => {
    const result = transformAddToCartRequest({
      id: 42,
      token: 'tok',
      product_id: 100,
      quantity: 2,
    });
    expect(result.body).toEqual({
      product_id: 100,
      quantity: 2,
    });
    expect(result.pathParams).toBeUndefined();
  });

  it('drops legacy-only fields (store, customer_*, product_name, price, etc.)', () => {
    const result = transformAddToCartRequest({
      id: 42,
      token: 'tok',
      store: 7,
      customer_name: 'Sodiq',
      customer_email: 'sodiq@example.com',
      product_id: 100,
      product_name: 'Abaya',
      product_desc: 'desc',
      product_image: 'img.jpg',
      price: '299.00',
      discount: '0.00',
      cart_code: 'PND',
      quantity: 1,
    });
    // Only product_id and quantity should remain.
    expect(result.body).toEqual({
      product_id: 100,
      quantity: 1,
    });
  });

  it('forwards variant fields (size, color)', () => {
    const result = transformAddToCartRequest({
      product_id: 100,
      quantity: 1,
      size: 'M',
      color: 'Red',
    });
    expect(result.body).toEqual({
      product_id: 100,
      quantity: 1,
      size: 'M',
      color: 'Red',
    });
  });

  it('forwards custom-tailoring fields when present', () => {
    const result = transformAddToCartRequest({
      product_id: 100,
      quantity: 1,
      is_custom: '1',
      measurement: 'std',
      extra_measurement: 'extra',
      note: 'urgent',
    });
    expect(result.body).toEqual({
      product_id: 100,
      quantity: 1,
      is_custom: true, // normalised from "1" to boolean true
      measurement: 'std',
      extra_measurement: 'extra',
      note: 'urgent',
    });
  });

  it('omits empty optional string fields rather than sending empty strings', () => {
    const result = transformAddToCartRequest({
      product_id: 100,
      quantity: 1,
      size: '',
      color: '',
      measurement: '',
    });
    const body = result.body as Record<string, unknown>;
    expect('size' in body).toBe(false);
    expect('color' in body).toBe(false);
    expect('measurement' in body).toBe(false);
  });

  it('defaults quantity to 1 if missing or zero', () => {
    const result1 = transformAddToCartRequest({ product_id: 100 });
    expect((result1.body as { quantity: number }).quantity).toBe(1);

    const result2 = transformAddToCartRequest({ product_id: 100, quantity: 0 });
    expect((result2.body as { quantity: number }).quantity).toBe(1);
  });

  it('coerces string quantity to number', () => {
    const result = transformAddToCartRequest({
      product_id: '100', // legacy sometimes sends strings
      quantity: '3',
    });
    expect(result.body).toEqual({
      product_id: 100,
      quantity: 3,
    });
  });

  it('normalises is_custom from various truthy/falsy inputs', () => {
    expect(
      (transformAddToCartRequest({ product_id: 1, is_custom: true }).body as Record<string, unknown>)['is_custom'],
    ).toBe(true);
    expect(
      (transformAddToCartRequest({ product_id: 1, is_custom: 'true' }).body as Record<string, unknown>)['is_custom'],
    ).toBe(true);
    expect(
      (transformAddToCartRequest({ product_id: 1, is_custom: 1 }).body as Record<string, unknown>)['is_custom'],
    ).toBe(true);
    expect(
      (transformAddToCartRequest({ product_id: 1, is_custom: false }).body as Record<string, unknown>)['is_custom'],
    ).toBe(false);
    expect(
      (transformAddToCartRequest({ product_id: 1, is_custom: '0' }).body as Record<string, unknown>)['is_custom'],
    ).toBe(false);
  });
});

describe('transformIncreaseItemRequest', () => {
  it('moves item id from body to pathParams and forwards absolute quantity', () => {
    const result = transformIncreaseItemRequest({
      id: 42,
      token: 'tok',
      item: 555,
      quantity: 3,
    });
    expect(result.pathParams).toEqual({ id: '555' });
    expect(result.body).toEqual({ quantity: 3 });
  });

  it('coerces string item id', () => {
    const result = transformIncreaseItemRequest({
      item: '555',
      quantity: 1,
    });
    expect(result.pathParams).toEqual({ id: '555' });
  });

  it('defaults missing item id to "0" (server returns 404)', () => {
    const result = transformIncreaseItemRequest({ quantity: 1 });
    expect(result.pathParams).toEqual({ id: '0' });
  });
});

describe('transformDecreaseItemRequest', () => {
  it('moves item id from body to pathParams and forwards absolute quantity', () => {
    const result = transformDecreaseItemRequest({
      id: 42,
      token: 'tok',
      item: 555,
      quantity: 2,
    });
    expect(result.pathParams).toEqual({ id: '555' });
    expect(result.body).toEqual({ quantity: 2 });
  });

  it('forwards quantity=0 unchanged (server returns 422 "DELETE to remove")', () => {
    // Important: we do NOT silently coerce 0 → 1 here. Surfacing the
    // 422 to the page reveals the bug; coercion would mask it.
    const result = transformDecreaseItemRequest({ item: 555, quantity: 0 });
    expect(result.body).toEqual({ quantity: 0 });
  });
});

describe('transformRemoveCartItemRequest', () => {
  it('moves item id to pathParams and sends no body', () => {
    const result = transformRemoveCartItemRequest({
      id: 42,
      token: 'tok',
      item: 555,
    });
    expect(result.pathParams).toEqual({ id: '555' });
    expect(result.body).toBeNull();
  });
});

describe('transformMergeCartRequest', () => {
  it('passes through a well-formed items array', () => {
    const result = transformMergeCartRequest({
      items: [
        { product_id: 1, quantity: 2, size: 'M' },
        { product_id: 2, quantity: 1, color: 'Red' },
      ],
    });
    expect(result.body).toEqual({
      items: [
        { product_id: 1, quantity: 2, size: 'M' },
        { product_id: 2, quantity: 1, color: 'Red' },
      ],
    });
  });

  it('skips malformed items (missing product_id)', () => {
    const result = transformMergeCartRequest({
      items: [
        { product_id: 1, quantity: 2 },
        { quantity: 5 }, // no product_id — must be skipped
        { product_id: 'not-a-number' }, // invalid product_id
        { product_id: 3, quantity: 1 },
      ],
    });
    const items = (result.body as { items: Array<{ product_id: number }> }).items;
    expect(items.length).toBe(2);
    expect(items[0]?.product_id).toBe(1);
    expect(items[1]?.product_id).toBe(3);
  });

  it('defaults quantity to 1 when missing or invalid', () => {
    const result = transformMergeCartRequest({
      items: [
        { product_id: 1 }, // no qty
        { product_id: 2, quantity: 0 },
        { product_id: 3, quantity: -5 },
      ],
    });
    const items = (result.body as { items: Array<{ quantity: number }> }).items;
    expect(items[0]?.quantity).toBe(1);
    expect(items[1]?.quantity).toBe(1);
    expect(items[2]?.quantity).toBe(1);
  });

  it('handles missing or non-array items field', () => {
    expect(transformMergeCartRequest({}).body).toEqual({ items: [] });
    expect(transformMergeCartRequest({ items: 'not-an-array' }).body).toEqual({ items: [] });
    expect(transformMergeCartRequest({ items: null }).body).toEqual({ items: [] });
  });

  it('preserves custom-tailoring fields per item', () => {
    const result = transformMergeCartRequest({
      items: [
        {
          product_id: 1,
          quantity: 1,
          is_custom: true,
          measurement: 'std',
          note: 'rush',
        },
      ],
    });
    const items = (result.body as { items: Array<Record<string, unknown>> }).items;
    expect(items[0]).toEqual({
      product_id: 1,
      quantity: 1,
      is_custom: true,
      measurement: 'std',
      note: 'rush',
    });
  });
});

describe('transformInitiatePaymentRequest', () => {
  it('always sets channel to MOBILE', () => {
    const result = transformInitiatePaymentRequest({ id: 42, token: 'tok' });
    expect((result.body as Record<string, unknown>)['channel']).toBe('MOBILE');
  });

  it('stringifies delivery_fee from number', () => {
    const result = transformInitiatePaymentRequest({ delivery_fee: 25 });
    expect((result.body as Record<string, unknown>)['delivery_fee']).toBe('25');
  });

  it('accepts "delivery" as legacy alias for delivery_fee', () => {
    const result = transformInitiatePaymentRequest({ delivery: '20.00' });
    expect((result.body as Record<string, unknown>)['delivery_fee']).toBe('20.00');
  });

  it('forwards billing_address_id and shipping_address_id when present', () => {
    const result = transformInitiatePaymentRequest({
      billing_address_id: 7,
      shipping_address_id: 8,
    });
    expect((result.body as Record<string, unknown>)['billing_address_id']).toBe(7);
    expect((result.body as Record<string, unknown>)['shipping_address_id']).toBe(8);
  });

  it('omits optional fields when not present', () => {
    const result = transformInitiatePaymentRequest({});
    expect(result.body).toEqual({ channel: 'MOBILE' });
  });

  it('omits delivery_fee when empty string', () => {
    const result = transformInitiatePaymentRequest({ delivery_fee: '' });
    expect('delivery_fee' in (result.body as Record<string, unknown>)).toBe(false);
  });
});

describe('MUTATION_REQUEST_TRANSFORMS registry', () => {
  it('contains the expected mutation routeKeys', () => {
    expect(Object.keys(MUTATION_REQUEST_TRANSFORMS).sort()).toEqual([
      'DELETE /cart/items/:id',
      'PATCH /cart/items/:id',
      'POST /cart/items',
      'POST /cart/merge',
      'POST /checkout/initiate',
    ]);
  });

  it('every entry is a function', () => {
    for (const [key, transform] of Object.entries(MUTATION_REQUEST_TRANSFORMS)) {
      expect(typeof transform).toBe('function');
      expect(transform).toBeTruthy();
      // Smoke-test that calling with {} doesn't throw.
      expect(() => transform({})).not.toThrow();
    }
    expect(Object.keys(MUTATION_REQUEST_TRANSFORMS).length).toBeGreaterThan(0);
  });
});
