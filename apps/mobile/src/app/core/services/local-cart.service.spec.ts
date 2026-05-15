import { LocalCartService, _internals, type LocalCartItem } from './local-cart.service';

/**
 * Unit tests for M3.1.6i.2-E LocalCartService.
 *
 * Scope: pure helpers + API-surface verification.
 *
 * Full IndexedDB integration tests would require fake-indexeddb or
 * a real browser. This project's mobile CI runs type-check + build
 * only (no jasmine runner), so we focus on the parts that are
 * exercisable without a real IDB instance:
 *   - isSameLine variant-match logic (the dedup key for cart merging)
 *   - Constants are correctly exported (DB name, version, max qty)
 *   - Service class shape matches expectations (public methods exist
 *     with the right signatures, so TypeScript users get correct
 *     autocomplete + type-checking)
 *
 * IndexedDB integration is verified via M3.1.6i.2-E device test (see
 * the eventual m3.1.6i.2-completion.md): manual cart-add as guest,
 * sign in, verify items appear in server cart.
 */

describe('LocalCartService constants', () => {
  it('exposes DB_NAME with the expected namespace', () => {
    expect(_internals.DB_NAME).toBe('3bayti_local_cart');
  });

  it('exposes DB_VERSION at v1 for the first schema', () => {
    expect(_internals.DB_VERSION).toBe(1);
  });

  it('exposes STORE_ITEMS as the items object-store name', () => {
    expect(_internals.STORE_ITEMS).toBe('items');
  });

  it('caps quantity per line at 999 (matches v3 server-side)', () => {
    expect(_internals.MAX_QTY_PER_LINE).toBe(999);
  });
});

describe('isSameLine (variant-match dedup key)', () => {
  // Build a baseline item for variation tests.
  const base: LocalCartItem = {
    product_id: 100,
    quantity: 1,
    size: 'M',
    color: 'Red',
    is_custom: false,
    measurement: '',
    extra_measurement: '',
    note: '',
    product_name: 'Abaya',
    product_image: 'img.jpg',
    unit_price: '299.00',
    vendor_id: 7,
    vendor_name: 'Bayti Test',
  };

  it('matches identical items', () => {
    expect(_internals.isSameLine(base, { ...base })).toBe(true);
  });

  it('does NOT match different product_id', () => {
    expect(_internals.isSameLine(base, { ...base, product_id: 200 })).toBe(false);
  });

  it('does NOT match different size', () => {
    expect(_internals.isSameLine(base, { ...base, size: 'L' })).toBe(false);
  });

  it('does NOT match different color', () => {
    expect(_internals.isSameLine(base, { ...base, color: 'Blue' })).toBe(false);
  });

  it('does NOT match different is_custom flag', () => {
    expect(_internals.isSameLine(base, { ...base, is_custom: true })).toBe(false);
  });

  it('does NOT match different measurement', () => {
    expect(_internals.isSameLine(base, { ...base, measurement: 'XL' })).toBe(false);
  });

  it('does NOT match different extra_measurement', () => {
    expect(_internals.isSameLine(base, { ...base, extra_measurement: 'note' })).toBe(false);
  });

  it('DOES match when only note differs (note is not in dedup key)', () => {
    // Matches v3 server-side behavior: notes don't fragment cart lines.
    expect(_internals.isSameLine(base, { ...base, note: 'urgent' })).toBe(true);
  });

  it('DOES match when only quantity differs (qty not in dedup key)', () => {
    // Quantity is merged after dedup, not part of dedup.
    expect(_internals.isSameLine(base, { ...base, quantity: 99 })).toBe(true);
  });
});

describe('LocalCartService API surface', () => {
  it('exposes the expected public methods', () => {
    const service = new LocalCartService();
    expect(typeof service.list).toBe('function');
    expect(typeof service.add).toBe('function');
    expect(typeof service.updateQuantity).toBe('function');
    expect(typeof service.remove).toBe('function');
    expect(typeof service.clear).toBe('function');
    expect(typeof service.count).toBe('function');
    expect(typeof service.isEmpty).toBe('function');
    expect(typeof service.subtotal).toBe('function');
  });
});
