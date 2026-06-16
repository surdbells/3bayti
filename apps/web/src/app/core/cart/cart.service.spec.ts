import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { CartService } from './cart.service';
import { AuthService } from '../auth/auth.service';
import type { Cart, CartItem, AddCartItemInput } from './cart.types';

/**
 * CartService test surface.
 *
 * Two stores to exercise:
 *   - Guest: localStorage-backed, synthesises Cart shape
 *   - Auth: server-backed via /v3/cart endpoints
 *
 * Merge handshake on the false → true auth transition.
 *
 * SSR construction (no localStorage access).
 */

const V3_BASE = 'https://api-v3.3bayti.ae';
const GUEST_KEY = 'bayti_guest_cart_v1';

/** Minimal stub matching AuthService's two consumed signals. */
class StubAuthService {
  private _authed = signal(false);
  isAuthenticated = this._authed.asReadonly();
  /* Test-only mutator. */
  setAuthenticated(v: boolean): void {
    this._authed.set(v);
  }
}

function makeServerCart(overrides: Partial<Cart> = {}): Cart {
  return {
    id: 42,
    status: 'active',
    currency: 'AED',
    cart_code: 'PND',
    subtotal: '129.00',
    item_count: 1,
    items: [
      {
        id: 1,
        product_id: 100,
        product_name: 'Test Item',
        product_image: 'https://example.com/test.jpg',
        quantity: 1,
        unit_price: '129.00',
        line_subtotal: '129.00',
        size: 'M',
        color: 'Black',
        is_custom: false,
        measurement: null,
        extra_measurement: null,
        note: null,
      },
    ],
    ...overrides,
  };
}

/** Build a resolved cart line (the transient cart from /v3/cart/resolve
 *  carries id 0 on every line; the service re-assigns synthetic ids). */
function resolvedLine(o: {
  product_id: number;
  quantity: number;
  unit_price: string;
  line_subtotal: string;
  name?: string;
  image?: string;
  size?: string | null;
  color?: string | null;
  is_custom?: boolean;
}): CartItem {
  return {
    id: 0,
    product_id: o.product_id,
    product_name: o.name ?? 'Item',
    product_image: o.image ?? '',
    quantity: o.quantity,
    unit_price: o.unit_price,
    line_subtotal: o.line_subtotal,
    size: o.size ?? null,
    color: o.color ?? null,
    is_custom: o.is_custom ?? false,
    measurement: null,
    extra_measurement: null,
    note: null,
  };
}

function setup(opts: { authed?: boolean } = {}): {
  service: CartService;
  controller: HttpTestingController;
  auth: StubAuthService;
} {
  const auth = new StubAuthService();
  if (opts.authed === true) auth.setAuthenticated(true);

  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: AuthService, useValue: auth },
    ],
  });

  const service = TestBed.inject(CartService);
  const controller = TestBed.inject(HttpTestingController);
  /* Flush the construction-time effect (signal first-read). For the
     authed=true path this fires the merge+refresh chain. Note that
     handleSignIn awaits microtasks internally — tests that want to
     consume the initial GET /v3/cart should `await Promise.resolve()`
     a couple times after setup() before calling expectOne. */
  TestBed.tick();
  return { service, controller, auth };
}

/** Drain enough microtasks for handleSignIn to reach its first
 *  request after the construction-time effect. The async chain has
 *  ~6 await boundaries (firstValueFrom → runWithLoading.finally →
 *  outer await → handleSignIn await → refresh body → http.get). */
async function drainMicrotasks(): Promise<void> {
  for (let i = 0; i < 8; i++) {
    await Promise.resolve();
  }
}

describe('CartService', () => {
  beforeEach(() => {
    if (typeof localStorage !== 'undefined') localStorage.clear();
  });
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
    if (typeof localStorage !== 'undefined') localStorage.clear();
  });

  /* -----------------------------------------------------------------
     Guest cart — localStorage-backed
     ----------------------------------------------------------------- */
  describe('guest cart (localStorage)', () => {
    it('starts empty when localStorage has no entry', () => {
      const { service } = setup();
      expect(service.cart().items).toEqual([]);
      expect(service.itemCount()).toBe(0);
    });

    it('hydrates from localStorage on construction', () => {
      localStorage.setItem(
        GUEST_KEY,
        JSON.stringify({
          items: [{ product_id: 100, quantity: 2, size: 'M' }],
          currency: 'AED',
        }),
      );
      const { service } = setup();
      expect(service.cart().items).toHaveLength(1);
      expect(service.cart().items[0].product_id).toBe(100);
      expect(service.cart().items[0].quantity).toBe(2);
      expect(service.itemCount()).toBe(2);
    });

    it('handles corrupted localStorage as empty cart (defensive)', () => {
      localStorage.setItem(GUEST_KEY, '{not-valid-json');
      const { service } = setup();
      expect(service.cart().items).toEqual([]);
    });

    it('handles invalid shape in localStorage as empty cart', () => {
      localStorage.setItem(GUEST_KEY, JSON.stringify({ items: 'not-an-array' }));
      const { service } = setup();
      expect(service.cart().items).toEqual([]);
    });

    it('appends a new item on addItem', async () => {
      const { service } = setup();
      const input: AddCartItemInput = { product_id: 200, quantity: 1, size: 'L' };
      await service.addItem(input);

      expect(service.cart().items).toHaveLength(1);
      expect(service.cart().items[0].product_id).toBe(200);
      expect(service.cart().items[0].size).toBe('L');
      expect(service.itemCount()).toBe(1);

      /* localStorage was updated. */
      const stored = JSON.parse(localStorage.getItem(GUEST_KEY) ?? 'null');
      expect(stored.items).toEqual([{ product_id: 200, quantity: 1, size: 'L' }]);
    });

    it('dedupes when adding the same product+size+color (sums quantities)', async () => {
      const { service } = setup();
      await service.addItem({ product_id: 200, quantity: 1, size: 'L' });
      await service.addItem({ product_id: 200, quantity: 2, size: 'L' });

      expect(service.cart().items).toHaveLength(1);
      expect(service.cart().items[0].quantity).toBe(3);
      expect(service.itemCount()).toBe(3);
    });

    it('does NOT dedupe when sizes differ', async () => {
      const { service } = setup();
      await service.addItem({ product_id: 200, quantity: 1, size: 'L' });
      await service.addItem({ product_id: 200, quantity: 1, size: 'M' });

      expect(service.cart().items).toHaveLength(2);
      expect(service.itemCount()).toBe(2);
    });

    it('updateQty(synth_id, qty) updates the right line', async () => {
      const { service } = setup();
      await service.addItem({ product_id: 200, quantity: 1, size: 'L' });
      await service.addItem({ product_id: 201, quantity: 1, size: 'M' });

      /* Synthetic ids are idx + 1. The second line has id=2. */
      await service.updateQty(2, 5);
      expect(service.cart().items[1].quantity).toBe(5);
      expect(service.itemCount()).toBe(6);
    });

    it('updateQty(id, 0) is a remove', async () => {
      const { service } = setup();
      await service.addItem({ product_id: 200, quantity: 1 });
      await service.addItem({ product_id: 201, quantity: 1 });

      await service.updateQty(1, 0);
      expect(service.cart().items).toHaveLength(1);
      expect(service.cart().items[0].product_id).toBe(201);
    });

    it('updateQty throws for an out-of-range synthetic id', async () => {
      const { service } = setup();
      await service.addItem({ product_id: 200, quantity: 1 });
      await expect(service.updateQty(99, 3)).rejects.toThrow(/invalid item id/);
    });

    it('removeItem clears the line and updates totals', async () => {
      const { service } = setup();
      await service.addItem({ product_id: 200, quantity: 2 });
      await service.removeItem(1);
      expect(service.cart().items).toEqual([]);
      expect(service.itemCount()).toBe(0);
    });

    it('removeItem is idempotent for unknown ids', async () => {
      const { service } = setup();
      await service.addItem({ product_id: 200, quantity: 1 });
      await service.removeItem(999);
      expect(service.cart().items).toHaveLength(1);
    });

    it('refresh() on guest re-prices via POST /v3/cart/resolve (no GET /v3/cart)', async () => {
      const { service, controller } = setup();
      await service.addItem({ product_id: 200, quantity: 1 });

      const promise = service.refresh();

      /* The add (fire-and-forget) and refresh (awaited) each kick off a
         resolve; flush every pending one. */
      const reqs = controller.match(`${V3_BASE}/v3/cart/resolve`);
      expect(reqs.length).toBeGreaterThan(0);
      expect(reqs[0].request.method).toBe('POST');
      for (const r of reqs) {
        r.flush({
          cart: makeServerCart({
            items: [
              resolvedLine({
                product_id: 200,
                name: 'Widget',
                quantity: 1,
                unit_price: '50.00',
                line_subtotal: '50.00',
              }),
            ],
            subtotal: '50.00',
            item_count: 1,
          }),
          removed: [],
        });
      }

      const result = await promise;
      expect(result.items).toHaveLength(1);
      expect(result.items[0].product_name).toBe('Widget');
      /* Guests never hit the authenticated GET /v3/cart. */
      controller.expectNone(`${V3_BASE}/v3/cart`);
    });

    it('quoteWithPromo throws for guests (sign-in required)', async () => {
      const { service } = setup();
      await expect(service.quoteWithPromo('SAVE10')).rejects.toThrow(/sign in required/);
    });
  });

  /* -----------------------------------------------------------------
     Guest cart server resolve (POST /v3/cart/resolve)
     ----------------------------------------------------------------- */
  describe('guest cart server resolve', () => {
    it('prices the guest cart against the server (names, images, prices, subtotal)', async () => {
      localStorage.setItem(
        GUEST_KEY,
        JSON.stringify({ items: [{ product_id: 100, quantity: 2, size: 'M' }], currency: 'AED' }),
      );
      const { service, controller } = setup();

      const promise = service.refresh();
      const reqs = controller.match(`${V3_BASE}/v3/cart/resolve`);
      expect(reqs.length).toBeGreaterThan(0);
      expect(reqs[0].request.method).toBe('POST');
      expect(reqs[0].request.body).toEqual({
        items: [{ product_id: 100, quantity: 2, size: 'M' }],
      });
      for (const r of reqs) {
        r.flush({
          cart: makeServerCart({
            items: [
              resolvedLine({
                product_id: 100,
                name: 'Silk Abaya',
                image: 'https://cdn.test/abaya.jpg',
                quantity: 2,
                unit_price: '299.00',
                line_subtotal: '598.00',
                size: 'M',
              }),
            ],
            subtotal: '598.00',
            item_count: 2,
          }),
          removed: [],
        });
      }
      await promise;

      const cart = service.cart();
      expect(cart.items).toHaveLength(1);
      expect(cart.items[0].id).toBe(1); // synthetic id preserved for updateQty/remove
      expect(cart.items[0].product_name).toBe('Silk Abaya');
      expect(cart.items[0].product_image).toBe('https://cdn.test/abaya.jpg');
      expect(cart.items[0].unit_price).toBe('299.00');
      expect(cart.items[0].line_subtotal).toBe('598.00');
      expect(cart.subtotal).toBe('598.00');
      expect(cart.item_count).toBe(2);
    });

    it('falls back to the local synthesized cart when resolve fails (no throw)', async () => {
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
      const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
      localStorage.setItem(
        GUEST_KEY,
        JSON.stringify({ items: [{ product_id: 100, quantity: 1 }], currency: 'AED' }),
      );
      const { service, controller } = setup();

      const promise = service.refresh();
      for (const r of controller.match(`${V3_BASE}/v3/cart/resolve`)) {
        r.flush('boom', { status: 500, statusText: 'Server error' });
      }
      const result = await promise;

      /* Local line still shown; the failure is swallowed. */
      expect(result.items).toHaveLength(1);
      expect(result.items[0].product_id).toBe(100);
      expect(warn).toHaveBeenCalledWith(
        '[CartService] guest cart resolve failed; showing local cart',
        expect.anything(),
      );
      warn.mockRestore();
      error.mockRestore();
    });

    it('prunes products the server reports as removed from localStorage', async () => {
      localStorage.setItem(
        GUEST_KEY,
        JSON.stringify({
          items: [
            { product_id: 100, quantity: 1 },
            { product_id: 999, quantity: 1 },
          ],
          currency: 'AED',
        }),
      );
      const { service, controller } = setup();

      const promise = service.refresh();
      for (const r of controller.match(`${V3_BASE}/v3/cart/resolve`)) {
        r.flush({
          cart: makeServerCart({
            items: [
              resolvedLine({
                product_id: 100,
                name: 'Kept',
                quantity: 1,
                unit_price: '50.00',
                line_subtotal: '50.00',
              }),
            ],
            subtotal: '50.00',
            item_count: 1,
          }),
          removed: [999],
        });
      }
      await promise;

      expect(service.cart().items).toHaveLength(1);
      expect(service.cart().items[0].product_id).toBe(100);
      const stored = JSON.parse(localStorage.getItem(GUEST_KEY) ?? 'null');
      expect(stored.items).toEqual([{ product_id: 100, quantity: 1 }]);
    });

    it('reuses cached prices on the next synthesize (no flash to 0.00)', async () => {
      localStorage.setItem(
        GUEST_KEY,
        JSON.stringify({ items: [{ product_id: 100, quantity: 1, size: 'M' }], currency: 'AED' }),
      );
      const { service, controller } = setup();

      const p1 = service.refresh();
      for (const r of controller.match(`${V3_BASE}/v3/cart/resolve`)) {
        r.flush({
          cart: makeServerCart({
            items: [
              resolvedLine({
                product_id: 100,
                name: 'Silk Abaya',
                image: 'https://cdn.test/x.jpg',
                quantity: 1,
                unit_price: '299.00',
                line_subtotal: '299.00',
                size: 'M',
              }),
            ],
            subtotal: '299.00',
            item_count: 1,
          }),
          removed: [],
        });
      }
      await p1;

      /* updateQty re-synthesizes synchronously; the cached price must
         carry over instead of resetting to 0.00. */
      const synthesized = await service.updateQty(1, 3);
      expect(synthesized.items[0].product_name).toBe('Silk Abaya');
      expect(synthesized.items[0].unit_price).toBe('299.00');
      expect(synthesized.items[0].line_subtotal).toBe('897.00');
      expect(synthesized.subtotal).toBe('897.00');
    });

    it('ignores a superseded (older) resolve so the latest result wins', async () => {
      localStorage.setItem(
        GUEST_KEY,
        JSON.stringify({ items: [{ product_id: 100, quantity: 1 }], currency: 'AED' }),
      );
      const { service, controller } = setup();

      const p1 = service.refresh();
      const p2 = service.refresh();

      const reqs = controller.match(`${V3_BASE}/v3/cart/resolve`);
      expect(reqs.length).toBeGreaterThanOrEqual(2);
      const newest = reqs[reqs.length - 1];
      const older = reqs.slice(0, reqs.length - 1);

      /* Newest (latest seq) publishes the winning data... */
      newest.flush({
        cart: makeServerCart({
          items: [
            resolvedLine({
              product_id: 100,
              name: 'WINNER',
              quantity: 1,
              unit_price: '10.00',
              line_subtotal: '10.00',
            }),
          ],
          subtotal: '10.00',
          item_count: 1,
        }),
        removed: [],
      });
      /* ...older resolves complete afterwards and must NOT overwrite. */
      for (const r of older) {
        r.flush({
          cart: makeServerCart({
            items: [
              resolvedLine({
                product_id: 100,
                name: 'STALE',
                quantity: 1,
                unit_price: '99.00',
                line_subtotal: '99.00',
              }),
            ],
            subtotal: '99.00',
            item_count: 1,
          }),
          removed: [],
        });
      }

      await Promise.all([p1, p2]);
      expect(service.cart().items[0].product_name).toBe('WINNER');
      expect(service.cart().subtotal).toBe('10.00');
    });

    it('does not call the resolve endpoint for an empty guest cart', async () => {
      const { service, controller } = setup();
      await service.refresh();
      controller.expectNone(`${V3_BASE}/v3/cart/resolve`);
    });
  });

  /* -----------------------------------------------------------------
     Authenticated cart — API-backed
     ----------------------------------------------------------------- */
  describe('authenticated cart (API)', () => {
    it('addItem POSTs /v3/cart/items and updates the cart signal', async () => {
      const { service, controller } = setup({ authed: true });

      /* The constructor-time effect fires a refresh; consume it. */
      await drainMicrotasks();
      const initialRefresh = controller.expectOne(`${V3_BASE}/v3/cart`);
      initialRefresh.flush(makeServerCart({ items: [], item_count: 0, subtotal: '0.00' }));
      await Promise.resolve();

      const promise = service.addItem({ product_id: 100, quantity: 1, size: 'M' });
      const req = controller.expectOne(`${V3_BASE}/v3/cart/items`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ product_id: 100, quantity: 1, size: 'M' });
      req.flush(makeServerCart());

      await promise;
      expect(service.cart().id).toBe(42);
      expect(service.cart().items).toHaveLength(1);
    });

    it('updateQty PATCHes /v3/cart/items/:id with the new quantity', async () => {
      const { service, controller } = setup({ authed: true });
      await drainMicrotasks();
      controller.expectOne(`${V3_BASE}/v3/cart`).flush(makeServerCart());
      await Promise.resolve();

      const promise = service.updateQty(1, 3);
      const req = controller.expectOne(`${V3_BASE}/v3/cart/items/1`);
      expect(req.request.method).toBe('PATCH');
      expect(req.request.body).toEqual({ quantity: 3 });
      req.flush(makeServerCart({
        items: [{ ...makeServerCart().items[0], quantity: 3, line_subtotal: '387.00' }],
        item_count: 3,
        subtotal: '387.00',
      }));

      await promise;
      expect(service.cart().items[0].quantity).toBe(3);
      expect(service.itemCount()).toBe(3);
    });

    it('removeItem DELETEs then refreshes via GET /v3/cart', async () => {
      const { service, controller } = setup({ authed: true });
      await drainMicrotasks();
      controller.expectOne(`${V3_BASE}/v3/cart`).flush(makeServerCart());
      await Promise.resolve();

      const promise = service.removeItem(1);

      const del = controller.expectOne(`${V3_BASE}/v3/cart/items/1`);
      expect(del.request.method).toBe('DELETE');
      del.flush(null, { status: 204, statusText: 'No Content' });

      /* The DELETE response triggers the chained refresh; let the
         microtask queue drain so the GET registers. */
      await Promise.resolve();

      const refresh = controller.expectOne(`${V3_BASE}/v3/cart`);
      refresh.flush(makeServerCart({ items: [], item_count: 0, subtotal: '0.00' }));

      await promise;
      expect(service.cart().items).toEqual([]);
    });

    it('refresh() GETs /v3/cart and updates signal', async () => {
      const { service, controller } = setup({ authed: true });
      /* Constructor refresh. */
      await drainMicrotasks();
      controller.expectOne(`${V3_BASE}/v3/cart`).flush(makeServerCart({ items: [], item_count: 0 }));
      await Promise.resolve();

      const promise = service.refresh();
      const req = controller.expectOne(`${V3_BASE}/v3/cart`);
      req.flush(makeServerCart({ item_count: 5, subtotal: '645.00' }));
      await promise;
      expect(service.itemCount()).toBe(5);
      expect(service.subtotal()).toBe('645.00');
    });

    it('quoteWithPromo POSTs to /v3/cart/quote with promo_code', async () => {
      const { service, controller } = setup({ authed: true });
      await drainMicrotasks();
      controller.expectOne(`${V3_BASE}/v3/cart`).flush(makeServerCart());
      await Promise.resolve();

      const promise = service.quoteWithPromo('SAVE10');
      const req = controller.expectOne(`${V3_BASE}/v3/cart/quote`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ promo_code: 'SAVE10' });
      req.flush({
        cart: makeServerCart({ subtotal: '129.00' }),
        promo_code: 'SAVE10',
        promo_valid: true,
        promo_message: null,
        breakdown: {
          subtotal: '129.00',
          shipping: '0.00',
          tax: '0.00',
          promo_discount: '12.90',
          total: '116.10',
        },
      });

      const result = await promise;
      expect(result.promo_valid).toBe(true);
      expect(result.breakdown.total).toBe('116.10');
    });

    it('isLoading is true during a mutation and false after', async () => {
      const { service, controller } = setup({ authed: true });
      await drainMicrotasks();
      controller.expectOne(`${V3_BASE}/v3/cart`).flush(makeServerCart({ items: [], item_count: 0 }));
      /* Drain the construction-refresh's finally microtask. */
      await Promise.resolve();
      await Promise.resolve();

      expect(service.isLoading()).toBe(false);
      const promise = service.addItem({ product_id: 100, quantity: 1 });
      expect(service.isLoading()).toBe(true);
      controller.expectOne(`${V3_BASE}/v3/cart/items`).flush(makeServerCart());
      await promise;
      expect(service.isLoading()).toBe(false);
    });
  });

  /* -----------------------------------------------------------------
     Auth transition merge handshake
     ----------------------------------------------------------------- */
  describe('sign-in merge handshake', () => {
    it('merges guest items via POST /v3/cart/merge and clears localStorage', async () => {
      /* Pre-seed the guest cart. */
      localStorage.setItem(
        GUEST_KEY,
        JSON.stringify({
          items: [{ product_id: 100, quantity: 2, size: 'M' }],
          currency: 'AED',
        }),
      );
      const { service, controller, auth } = setup();
      expect(service.itemCount()).toBe(2);

      /* Flip auth state → effect should fire merge + refresh. */
      auth.setAuthenticated(true);
      TestBed.tick();
      await drainMicrotasks();

      const merge = controller.expectOne(`${V3_BASE}/v3/cart/merge`);
      expect(merge.request.method).toBe('POST');
      expect(merge.request.body).toEqual({
        items: [{ product_id: 100, quantity: 2, size: 'M' }],
      });
      merge.flush(makeServerCart({ items: [], item_count: 0 }));

      /* The merge's finally awaits a microtask chain before refresh fires. */
      await drainMicrotasks();

      /* Then refresh is called. */
      const refresh = controller.expectOne(`${V3_BASE}/v3/cart`);
      refresh.flush(makeServerCart({ item_count: 2, subtotal: '258.00' }));

      /* Drain microtasks for the async chain. */
      await drainMicrotasks();

      expect(localStorage.getItem(GUEST_KEY)).toBeNull();
      expect(service.itemCount()).toBe(2);
    });

    it('skips merge when guest cart is empty; just refreshes', async () => {
      const { service, controller, auth } = setup();

      auth.setAuthenticated(true);
      TestBed.tick();
      await drainMicrotasks();

      controller.expectNone(`${V3_BASE}/v3/cart/merge`);
      const refresh = controller.expectOne(`${V3_BASE}/v3/cart`);
      refresh.flush(makeServerCart({ item_count: 1, subtotal: '129.00' }));

      await Promise.resolve();
      expect(service.itemCount()).toBe(1);
    });

    it('falls through to refresh when merge fails (network error)', async () => {
      localStorage.setItem(
        GUEST_KEY,
        JSON.stringify({ items: [{ product_id: 100, quantity: 1 }], currency: 'AED' }),
      );
      const { service, controller, auth } = setup();
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

      auth.setAuthenticated(true);
      TestBed.tick();
      await drainMicrotasks();

      const merge = controller.expectOne(`${V3_BASE}/v3/cart/merge`);
      merge.flush('boom', { status: 500, statusText: 'Server error' });

      await drainMicrotasks();

      const refresh = controller.expectOne(`${V3_BASE}/v3/cart`);
      refresh.flush(makeServerCart({ item_count: 0, items: [] }));

      await drainMicrotasks();

      expect(warnSpy).toHaveBeenCalledWith(
        '[CartService] merge failed; falling back to refresh',
        expect.anything(),
      );
      warnSpy.mockRestore();
      /* Guest cart NOT cleared since merge failed. */
      expect(localStorage.getItem(GUEST_KEY)).not.toBeNull();
    });

    it('drops to empty guest cart on logout (does not seed from server cart)', async () => {
      const { service, controller, auth } = setup();

      /* Sign in, server cart has items. */
      auth.setAuthenticated(true);
      TestBed.tick();
      await drainMicrotasks();
      controller.expectOne(`${V3_BASE}/v3/cart`).flush(makeServerCart({ item_count: 3, subtotal: '387.00' }));
      await Promise.resolve();
      expect(service.itemCount()).toBe(3);

      /* Sign out → should reset to empty (no localStorage to read). */
      auth.setAuthenticated(false);
      TestBed.tick();
      expect(service.itemCount()).toBe(0);
    });

    it('handles repeated sign-in without re-merging an already-cleared guest cart', async () => {
      localStorage.setItem(
        GUEST_KEY,
        JSON.stringify({ items: [{ product_id: 100, quantity: 1 }], currency: 'AED' }),
      );
      const { auth, controller } = setup();
      auth.setAuthenticated(true);
      TestBed.tick();
      await drainMicrotasks();

      controller.expectOne(`${V3_BASE}/v3/cart/merge`).flush(makeServerCart({ items: [], item_count: 0 }));
      await drainMicrotasks();
      controller.expectOne(`${V3_BASE}/v3/cart`).flush(makeServerCart({ items: [], item_count: 0 }));
      await drainMicrotasks();

      /* First merge cleared localStorage. Sign out + back in:
         mergedThisSession resets but the guest cart is now empty, so
         handleSignIn skips merge and goes straight to refresh. This is
         the intended behaviour — no stale-payload replay. */
      auth.setAuthenticated(false);
      TestBed.tick();
      auth.setAuthenticated(true);
      TestBed.tick();
      await drainMicrotasks();

      controller.expectNone(`${V3_BASE}/v3/cart/merge`);
      controller.expectOne(`${V3_BASE}/v3/cart`).flush(makeServerCart());
    });
  });
});
