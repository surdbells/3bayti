import { Observable, of, throwError } from 'rxjs';

import { CartMergeService, type MergeResult } from './cart-merge.service';
import { LocalCartService, type LocalCartItem } from './local-cart.service';
import { NetworkService } from '../../service/network.service';

/**
 * Unit tests for M3.1.6i.2-E CartMergeService.
 *
 * Mocks both LocalCartService and NetworkService so each test
 * exercises a specific orchestration scenario:
 *   - Empty local cart → no-op, no network call
 *   - Non-empty + server success → POST, clear, return success
 *   - Non-empty + server failure (4xx/5xx) → POST, NO clear,
 *     return failure with error
 *   - Non-empty + skipped items → return skipped[] for UI surface
 *   - LocalCartService throws → captured, sign-in unblocked
 */

// Mock LocalCartService: configurable list result, tracks clear calls.
class MockLocalCart {
  items: LocalCartItem[] = [];
  cleared = false;
  listShouldThrow = false;

  async list(): Promise<LocalCartItem[]> {
    if (this.listShouldThrow) throw new Error('IDB unavailable');
    return this.items;
  }

  async clear(): Promise<void> {
    this.cleared = true;
  }

  // Stub methods not used by CartMergeService — present for TS interface match.
  async add(_item: LocalCartItem): Promise<void> { /* noop */ }
  async updateQuantity(_id: number, _qty: number): Promise<void> { /* noop */ }
  async remove(_id: number): Promise<void> { /* noop */ }
  async count(): Promise<number> { return this.items.length; }
  async isEmpty(): Promise<boolean> { return this.items.length === 0; }
  async subtotal(): Promise<string> { return '0.00'; }
}

// Mock NetworkService: configurable response observable, tracks call.
class MockNetwork {
  lastBody: unknown = null;
  lastUrl: string = '';
  response: Observable<any> = of({ response_code: 200, status: 'success', data: { success: true, skipped: [] } });

  post_request(body: unknown, url: string): Observable<any> {
    this.lastBody = body;
    this.lastUrl = url;
    return this.response;
  }

  get_request(_url: string): Observable<any> {
    return throwError(() => new Error('get_request not used by merge'));
  }
}

describe('CartMergeService.mergeIfAny', () => {
  let local: MockLocalCart;
  let net: MockNetwork;
  let service: CartMergeService;

  beforeEach(() => {
    local = new MockLocalCart();
    net = new MockNetwork();
    service = new CartMergeService(
      net as unknown as NetworkService,
      net as unknown as never, // MobileNetworkAdapter — same MockNetwork double works
      local as unknown as LocalCartService,
    );
  });

  const sampleItem: LocalCartItem = {
    product_id: 100,
    quantity: 2,
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
    vendor_name: 'Bayti',
  };

  it('returns attempted=false and skips network when local cart is empty', async () => {
    local.items = [];

    const result: MergeResult = await service.mergeIfAny({ id: 1, token: 'tok' });

    expect(result.attempted).toBe(false);
    expect(result.success).toBe(true);
    expect(result.skipped).toEqual([]);
    expect(net.lastBody).toBeNull(); // network never called
    expect(local.cleared).toBe(false); // nothing to clear
  });

  it('POSTs items and clears local cart on success', async () => {
    local.items = [sampleItem];
    net.response = of({
      response_code: 200,
      status: 'success',
      data: { success: true, count: 2, skipped: [] },
    });

    const result = await service.mergeIfAny({ id: 1, token: 'tok' });

    expect(result.attempted).toBe(true);
    expect(result.success).toBe(true);
    expect(local.cleared).toBe(true);

    // Network body includes auth + items
    const sentBody = net.lastBody as { id: number; token: string; items: unknown[] };
    expect(sentBody.id).toBe(1);
    expect(sentBody.token).toBe('tok');
    expect(sentBody.items.length).toBe(1);
    expect(net.lastUrl).toContain('v3/cart/merge');
  });

  it('surfaces skipped[] from server response', async () => {
    local.items = [sampleItem];
    net.response = of({
      response_code: 200,
      status: 'success',
      data: {
        success: true,
        count: 0,
        skipped: [{ product_id: 100, reason: 'product no longer available' }],
      },
    });

    const result = await service.mergeIfAny({ id: 1, token: 'tok' });

    expect(result.success).toBe(true);
    expect(result.skipped.length).toBe(1);
    expect(result.skipped[0]?.product_id).toBe(100);
    expect(local.cleared).toBe(true); // still cleared — server accepted
  });

  it('does NOT clear local cart on network failure', async () => {
    local.items = [sampleItem];
    net.response = throwError(() => new Error('connection refused'));

    const result = await service.mergeIfAny({ id: 1, token: 'tok' });

    expect(result.attempted).toBe(true);
    expect(result.success).toBe(false);
    expect(result.error).toBeTruthy();
    expect(local.cleared).toBe(false); // intact for retry
  });

  it('does NOT clear local cart on server-side merge failure', async () => {
    local.items = [sampleItem];
    net.response = of({
      response_code: 500,
      status: 'error',
      data: null,
    });

    const result = await service.mergeIfAny({ id: 1, token: 'tok' });

    expect(result.success).toBe(false);
    expect(local.cleared).toBe(false);
  });

  it('returns attempted=false when local-cart read throws (sign-in unblocked)', async () => {
    local.listShouldThrow = true;

    const result = await service.mergeIfAny({ id: 1, token: 'tok' });

    // attempted=false signals login flow to proceed without trying again
    expect(result.attempted).toBe(false);
    expect(result.success).toBe(false);
    expect(result.error).toBeTruthy();
    expect(net.lastBody).toBeNull();
  });

  it('detects v3 success via data.success flag', async () => {
    local.items = [sampleItem];
    // v3-shape success indicator: data.success === true
    net.response = of({
      response_code: 200,
      status: 'success',
      data: { success: true, count: 2, skipped: [] },
    });

    const result = await service.mergeIfAny({ id: 1, token: 'tok' });
    expect(result.success).toBe(true);
  });

  it('handles missing data field in response gracefully', async () => {
    local.items = [sampleItem];
    net.response = of({ response_code: 200, status: 'success' }); // no data

    const result = await service.mergeIfAny({ id: 1, token: 'tok' });

    // Either way is acceptable — success path uses (status==='success'),
    // missing data means skipped[] defaults to []
    expect(result.success).toBe(true);
    expect(result.skipped).toEqual([]);
  });
});
