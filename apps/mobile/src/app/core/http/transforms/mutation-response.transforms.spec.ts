import {
  transformCartListResponse,
  transformAddCartResponse,
  transformCartItemMutationResponse,
  transformMergeCartResponse,
  transformInitiatePaymentResponse,
  transformCheckoutStatusResponse,
  transformOrderListResponse,
  transformOrderDetailResponse,
  MUTATION_RESPONSE_TRANSFORMS,
} from './mutation-response.transforms';

/**
 * Unit tests for M3.1.6i.2 mutation response transforms.
 *
 * These map v3 envelope.data shapes to the binding shapes mobile
 * pages consume after Phase B page integration. Coverage:
 *
 *   - Cart list/add/mutate/merge: verify v3 item shape correctly
 *     mapped to mobile's expected fields (including `store` alias,
 *     `price` and `unit_price` both forwarded, bill subtotal)
 *   - Checkout initiate: url + order_reference extraction
 *   - Checkout status: pass-through with type safety
 *   - Order list/detail: `date` alias for created_at; pagination
 *     preserved
 *   - Defensive behaviour: empty/missing/null inputs don't throw
 */

describe('transformCartListResponse', () => {
  it('wraps v3 cart shape into {items, bill, cart_id, status}', () => {
    const result = transformCartListResponse({
      id: 123,
      status: 'active',
      items: [
        {
          id: 1,
          product_id: 100,
          product_name: 'Abaya',
          product_image: 'img.jpg',
          vendor_id: 7,
          vendor_name: 'Bayti Test',
          quantity: 2,
          unit_price: '299.00',
          line_total: '598.00',
          size: 'M',
          color: 'Red',
          is_custom: false,
        },
      ],
      subtotal: '598.00',
      item_count: 1,
      currency: 'AED',
    }) as { items: Array<Record<string, unknown>>; bill: Record<string, unknown>; cart_id: number };

    expect(result.cart_id).toBe(123);
    expect(result.items.length).toBe(1);
    expect(result.items[0]?.['id']).toBe(1);
    expect(result.items[0]?.['product_name']).toBe('Abaya');
    expect(result.items[0]?.['store']).toBe(7); // 'store' alias for vendor_id
    expect(result.items[0]?.['price']).toBe('299.00'); // legacy 'price' alias
    expect(result.items[0]?.['unit_price']).toBe('299.00'); // v3 native
    expect(result.bill).toEqual({
      count: 1,
      subtotal: '598.00',
      currency: 'AED',
    });
  });

  it('handles empty cart', () => {
    const result = transformCartListResponse({
      id: 0,
      status: 'active',
      items: [],
      subtotal: '0.00',
      item_count: 0,
      currency: 'AED',
    }) as { items: unknown[]; bill: Record<string, unknown> };

    expect(result.items).toEqual([]);
    expect(result.bill['count']).toBe(0);
    expect(result.bill['subtotal']).toBe('0.00');
  });

  it('is defensive against null/empty/malformed inputs', () => {
    expect(() => transformCartListResponse(null)).not.toThrow();
    expect(() => transformCartListResponse(undefined)).not.toThrow();
    expect(() => transformCartListResponse('string')).not.toThrow();
    expect(() => transformCartListResponse({})).not.toThrow();
    expect(() => transformCartListResponse({ items: 'not-an-array' })).not.toThrow();
  });

  it('forwards default values for missing item fields', () => {
    const result = transformCartListResponse({
      items: [{ id: 5 }], // bare minimum
    }) as { items: Array<Record<string, unknown>> };

    expect(result.items[0]?.['id']).toBe(5);
    expect(result.items[0]?.['product_name']).toBe('');
    expect(result.items[0]?.['quantity']).toBe(0);
    expect(result.items[0]?.['price']).toBe('0.00');
  });
});

describe('transformAddCartResponse', () => {
  it('wraps cart in {success, count, cart}', () => {
    const result = transformAddCartResponse({
      id: 123,
      items: [{ id: 1, product_id: 100, quantity: 2, unit_price: '299.00' }],
      item_count: 1,
      subtotal: '598.00',
    }) as { success: boolean; count: number; cart: Record<string, unknown> };

    expect(result.success).toBe(true);
    expect(result.count).toBe(1);
    expect(result.cart).toBeDefined();
    expect((result.cart['bill'] as Record<string, unknown>)['count']).toBe(1);
  });
});

describe('transformCartItemMutationResponse', () => {
  it('returns {success, count} for PATCH/DELETE results', () => {
    const result = transformCartItemMutationResponse({
      id: 123,
      items: [],
      item_count: 0,
      subtotal: '0.00',
    }) as { success: boolean; count: number };

    expect(result.success).toBe(true);
    expect(result.count).toBe(0);
  });

  it('extracts non-zero count', () => {
    const result = transformCartItemMutationResponse({ item_count: 5 }) as { count: number };
    expect(result.count).toBe(5);
  });
});

describe('transformMergeCartResponse', () => {
  it('wraps cart + skipped in {success, count, skipped, cart}', () => {
    const result = transformMergeCartResponse({
      cart: {
        id: 123,
        items: [{ id: 1, product_id: 100, quantity: 1 }],
        item_count: 1,
        subtotal: '299.00',
      },
      skipped: [
        { product_id: 999, reason: 'product no longer available' },
      ],
    }) as { success: boolean; count: number; skipped: unknown[]; cart: unknown };

    expect(result.success).toBe(true);
    expect(result.count).toBe(1);
    expect(result.skipped.length).toBe(1);
    expect(result.cart).toBeDefined();
  });

  it('handles empty skipped array', () => {
    const result = transformMergeCartResponse({
      cart: { id: 123, items: [], item_count: 0 },
      skipped: [],
    }) as { skipped: unknown[] };
    expect(result.skipped).toEqual([]);
  });

  it('handles missing skipped field', () => {
    const result = transformMergeCartResponse({
      cart: { id: 123, items: [], item_count: 0 },
    }) as { skipped: unknown[] };
    expect(result.skipped).toEqual([]);
  });
});

describe('transformInitiatePaymentResponse', () => {
  it('extracts url + order_reference', () => {
    const result = transformInitiatePaymentResponse({
      checkout_url: 'https://api-test.noonpayments.com/checkout/abc',
      order_reference: 'V3-1700000000000-abcd',
      provider_order_ref: '123456789012',
      order_id: 42,
      idempotent: false,
    }) as Record<string, unknown>;

    expect(result['url']).toBe('https://api-test.noonpayments.com/checkout/abc');
    expect(result['order_reference']).toBe('V3-1700000000000-abcd');
    expect(result['order_id']).toBe(42);
    expect(result['provider_order_ref']).toBe('123456789012');
    expect(result['idempotent']).toBe(false);
  });

  it('preserves idempotent: true for cached cart', () => {
    const result = transformInitiatePaymentResponse({
      checkout_url: 'https://...',
      order_reference: 'V3-...',
      order_id: 1,
      idempotent: true,
    }) as Record<string, unknown>;
    expect(result['idempotent']).toBe(true);
  });

  it('defaults missing fields safely', () => {
    const result = transformInitiatePaymentResponse({}) as Record<string, unknown>;
    expect(result['url']).toBe('');
    expect(result['order_reference']).toBe('');
    expect(result['idempotent']).toBe(false);
  });
});

describe('transformCheckoutStatusResponse', () => {
  it('passes through pending status with terminal=false', () => {
    const result = transformCheckoutStatusResponse({
      order_reference: 'V3-...',
      order_id: 42,
      status: 'pending_payment',
      terminal: false,
      paid: false,
      total: '299.00',
      currency: 'AED',
      paid_at: null,
    }) as Record<string, unknown>;

    expect(result['status']).toBe('pending_payment');
    expect(result['terminal']).toBe(false);
    expect(result['paid']).toBe(false);
    expect(result['paid_at']).toBeNull();
  });

  it('preserves terminal=true for paid status', () => {
    const result = transformCheckoutStatusResponse({
      status: 'paid',
      terminal: true,
      paid: true,
      paid_at: '2026-05-15T10:00:00Z',
    }) as Record<string, unknown>;

    expect(result['terminal']).toBe(true);
    expect(result['paid']).toBe(true);
    expect(result['paid_at']).toBe('2026-05-15T10:00:00Z');
  });

  it('coerces missing terminal/paid to false (not undefined)', () => {
    const result = transformCheckoutStatusResponse({}) as Record<string, unknown>;
    expect(result['terminal']).toBe(false);
    expect(result['paid']).toBe(false);
  });
});

describe('transformOrderListResponse', () => {
  it('forwards orders with `date` alias for created_at', () => {
    const result = transformOrderListResponse({
      orders: [
        {
          id: 100,
          order_reference: 'V3-001',
          status: 'paid',
          total: '299.00',
          created_at: '2026-05-15T10:00:00Z',
          items: [
            { id: 1, store: 7, product_name: 'X' },
          ],
        },
      ],
      pagination: { limit: 10, offset: 0, count: 1, total: 1 },
    }) as { orders: Array<Record<string, unknown>>; pagination: Record<string, unknown> };

    expect(result.orders.length).toBe(1);
    expect(result.orders[0]?.['date']).toBe('2026-05-15T10:00:00Z');
    expect(result.orders[0]?.['created_at']).toBe('2026-05-15T10:00:00Z');
    expect(result.pagination).toEqual({ limit: 10, offset: 0, count: 1, total: 1 });
  });

  it('handles empty orders array with synthetic pagination', () => {
    const result = transformOrderListResponse({ orders: [] }) as {
      orders: unknown[];
      pagination: Record<string, unknown>;
    };

    expect(result.orders).toEqual([]);
    expect(result.pagination['count']).toBe(0);
  });

  it('is defensive against malformed inputs', () => {
    expect(() => transformOrderListResponse(null)).not.toThrow();
    expect(() => transformOrderListResponse({})).not.toThrow();
  });
});

describe('transformOrderDetailResponse', () => {
  it('adds `date` alias for created_at', () => {
    const result = transformOrderDetailResponse({
      id: 100,
      order_reference: 'V3-001',
      created_at: '2026-05-15T10:00:00Z',
      status: 'paid',
    }) as Record<string, unknown>;

    expect(result['date']).toBe('2026-05-15T10:00:00Z');
    expect(result['created_at']).toBe('2026-05-15T10:00:00Z');
    expect(result['id']).toBe(100);
  });
});

describe('MUTATION_RESPONSE_TRANSFORMS registry', () => {
  it('contains the expected routeKeys', () => {
    expect(Object.keys(MUTATION_RESPONSE_TRANSFORMS).sort()).toEqual([
      'DELETE /cart/items/:id',
      'GET /cart',
      'GET /checkout/status/:order_reference',
      'GET /orders',
      'GET /orders/:id',
      'PATCH /cart/items/:id',
      'POST /cart/items',
      'POST /cart/merge',
      'POST /checkout/initiate',
    ]);
  });

  it('every entry is a function and tolerates null input', () => {
    for (const [, transform] of Object.entries(MUTATION_RESPONSE_TRANSFORMS)) {
      expect(typeof transform).toBe('function');
      // Defensive: must not throw on null
      expect(() => transform(null)).not.toThrow();
      expect(() => transform({})).not.toThrow();
    }
  });
});
