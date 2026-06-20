/**
 * Mutation response transforms — v3 mutation response → mobile binding shape.
 *
 * Why this file exists
 * ====================
 * The sibling `catalog-response.transforms.ts` maps v3 catalog read
 * responses to the legacy shapes mobile catalog pages bind to. This
 * file does the same for mutation endpoints (cart, checkout, orders).
 *
 * Cart-specific challenge: legacy quirk
 * ======================================
 * The legacy cart endpoint returns a quirky envelope where:
 *   - response.data    = the cart items array
 *   - response.message = the bill summary { count, subtotal, ... }
 *
 * The adapter's `toLegacyEnvelope` hard-codes `message: ''`, so we
 * can't preserve the legacy shape verbatim — `response.message`
 * would always be empty for v3 calls.
 *
 * Approach: response transforms return a single object placed inside
 * `data`. For cart, that means `{items, bill}` — cart.page.ts is
 * updated (Phase B page changes) to read `response.data.items` and
 * `response.data.bill` instead of `response.data` and `response.message`.
 *
 * This is a minor mobile-side change but is exactly what M3.1.6i.2 is
 * for: rewriting mobile call sites to consume the v3 shape directly.
 *
 * Adapter integration
 * ===================
 * Mirroring CATALOG_RESPONSE_TRANSFORMS:
 *   1. After v3 response received, adapter calls `envelopeAndTransform`
 *   2. `envelopeAndTransform` wraps response in legacy envelope via
 *      `toLegacyEnvelope`, THEN consults this registry by routeKey
 *   3. If a transform is registered, it runs over `envelope.data`
 *      (the inner v3 shape, NOT the envelope itself) and replaces
 *      the data field
 *   4. If no transform, the envelope passes through with raw v3
 *      data (some endpoints' v3 shape already matches mobile)
 *
 * Note: this registry is keyed by the SAME routeKey strings as
 * MUTATION_REQUEST_TRANSFORMS, but they're separate registries
 * because:
 *   - Some endpoints have a request transform but no response
 *     transform (e.g. DELETE cart-item — request needs path-param
 *     extraction; response is trivially {})
 *   - Some have a response transform but no request transform
 *     (e.g. GET cart — no body to transform, but envelope needs
 *     {items, bill} wrap)
 *   - Coupling them would force fake passthrough functions on
 *     either side
 *
 * Retirement
 * ==========
 * Same as MUTATION_REQUEST_TRANSFORMS: strangler-fig compat layer.
 * Once mobile is rebuilt against v3-native shapes, page bindings
 * will read v3 envelopes directly and this file can be deleted.
 */

import type { ResponseTransform } from './catalog-response.transforms';

/**
 * Helper — defensive object access. Returns {} for non-objects.
 */
function asObj(v: unknown): Record<string, unknown> {
  return v !== null && typeof v === 'object' && !Array.isArray(v)
    ? (v as Record<string, unknown>)
    : {};
}

/**
 * Helper — defensive array access. Returns [] for non-arrays.
 */
function asArr(v: unknown): unknown[] {
  return Array.isArray(v) ? v : [];
}

/* ============================================================== *
 * Cart responses
 * ============================================================== */

/**
 * GET /v3/cart → cart.page.ts binding shape.
 *
 * v3 shape (under envelope.data, post-toLegacyEnvelope unwrap):
 *   {
 *     id: 123,
 *     status: 'active',
 *     items: [
 *       { id, product_id, product_name, product_image, vendor_id,
 *         vendor_name, quantity, unit_price, line_total,
 *         size, color, is_custom, measurement, extra_measurement,
 *         note, in_stock, available_quantity },
 *       ...
 *     ],
 *     subtotal: '299.00',
 *     item_count: 3,
 *     currency: 'AED'
 *   }
 *
 * Legacy mobile shape (cart.page.ts reads `response.data` and
 * `response.message` separately):
 *   response.data = [ {id, product_id, product_name, product_image,
 *                      quantity, price, size, color, ...}, ... ]
 *   response.message = { count: 3, subtotal: '299.00', ... }
 *
 * Output of this transform (placed inside envelope.data):
 *   {
 *     items: [...],   // legacy-shaped items for cart.page binding
 *     bill: { count, subtotal, ... }
 *   }
 *
 * cart.page.ts is updated alongside to read response.data.items
 * and response.data.bill (Phase B page integration).
 */
export function transformCartListResponse(data: unknown): unknown {
  const outer = asObj(data);
  // v3 GET /v3/cart wraps the payload as { cart: {...} } and has no top-level
  // `data` key, so the adapter's envelope leaves it nested under `cart`.
  // Unwrap it; tolerate a flat shape too (POST /cart/items returns the cart
  // directly in some paths).
  const cartVal = outer['cart'];
  const v3 = cartVal != null && typeof cartVal === 'object' && !Array.isArray(cartVal)
    ? asObj(cartVal)
    : outer;
  const v3Items = asArr(v3['items']);
  const subtotal = typeof v3['subtotal'] === 'string' ? v3['subtotal'] : '0.00';
  const itemCount = typeof v3['item_count'] === 'number' ? v3['item_count'] : 0;
  const currency = typeof v3['currency'] === 'string' ? v3['currency'] : 'AED';

  // Map each v3 cart item to mobile's expected shape. Field names mostly
  // align, but legacy uses `price` while v3 uses `unit_price`; we forward
  // both for compatibility (some templates may read either).
  const items = v3Items.map((raw) => {
    const it = asObj(raw);
    return {
      id: it['id'] ?? 0,
      product_id: it['product_id'] ?? 0,
      product_name: it['product_name'] ?? '',
      product_image: it['product_image'] ?? '',
      vendor_id: it['vendor_id'] ?? 0,
      // Legacy 'store' alias — some templates read item.store directly.
      // Mirrors the convention M3.1.6e shipped for order items.
      store: it['vendor_id'] ?? 0,
      vendor_name: it['vendor_name'] ?? '',
      quantity: it['quantity'] ?? 0,
      // Both keys forwarded for template compat:
      price: it['unit_price'] ?? '0.00',
      unit_price: it['unit_price'] ?? '0.00',
      // cart.page.html binds `cart.price_formatted`; v3 has no formatted
      // field (unit_price is already a 2dp string).
      price_formatted: typeof it['unit_price'] === 'string' ? it['unit_price'] : '0.00',
      // v3 item key is `line_subtotal`, not `line_total`.
      line_total: it['line_subtotal'] ?? it['line_total'] ?? '0.00',
      size: it['size'] ?? '',
      color: it['color'] ?? '',
      is_custom: it['is_custom'] ?? false,
      measurement: it['measurement'] ?? '',
      extra_measurement: it['extra_measurement'] ?? '',
      note: it['note'] ?? '',
      in_stock: it['in_stock'] ?? true,
      available_quantity: it['available_quantity'] ?? 0,
    };
  });

  return {
    items,
    bill: {
      count: itemCount,
      subtotal,
      // The cart-page summary binds f_subtotal / f_delivery / f_discount /
      // total. Pre-checkout the cart carries no delivery/discount (those are
      // computed on the Order at checkout), so total == subtotal here and the
      // breakdown lines read 0.00 until checkout populates them.
      f_subtotal: subtotal,
      f_delivery: '0.00',
      f_discount: '0.00',
      total: subtotal,
      f_total: subtotal,
      currency,
    },
    cart_id: v3['id'] ?? 0,
    status: v3['status'] ?? 'active',
  };
}

/**
 * POST /v3/cart/items → product.page.ts addToCart success binding.
 *
 * v3 returns the full updated cart. Mobile only needs to know the new
 * item count for the badge + a success state for the toast.
 *
 * Output of this transform:
 *   {
 *     success: true,
 *     count: <total items>,
 *     cart: { items, bill }  // same shape as transformCartListResponse
 *   }
 *
 * Updated product.page.ts reads response.data.success and
 * response.data.count; the full cart is available for any other
 * consumer (e.g. cart badge service) that wants it.
 */
export function transformAddCartResponse(data: unknown): unknown {
  const cartShape = transformCartListResponse(data);
  const itemCount = (asObj((cartShape as Record<string, unknown>)['bill']))['count'] ?? 0;

  return {
    success: true,
    count: itemCount,
    cart: cartShape,
  };
}

/**
 * PATCH /v3/cart/items/{id} and DELETE /v3/cart/items/{id} →
 * cart.page.ts mutation-success binding.
 *
 * v3 returns the updated cart. Mobile's cart.page.ts currently
 * calls load_cart() after each mutation to refresh — that pattern
 * stays, so this transform just signals success without forcing
 * the page to handle the full cart inline.
 *
 * Output:
 *   { success: true, count: <total items> }
 */
export function transformCartItemMutationResponse(data: unknown): unknown {
  const v3 = asObj(data);
  const itemCount = typeof v3['item_count'] === 'number' ? v3['item_count'] : 0;
  return {
    success: true,
    count: itemCount,
  };
}

/**
 * POST /v3/cart/merge → sign-in flow merge-success binding.
 *
 * v3 returns:
 *   {
 *     cart: { ... full cart shape ... },
 *     skipped: [ { product_id, reason }, ... ]
 *   }
 *
 * Output:
 *   {
 *     success: true,
 *     count: <total items after merge>,
 *     skipped: [...],
 *     cart: { items, bill }
 *   }
 *
 * Sign-in flow reads response.data.skipped to show a one-line
 * notice if any items were dropped (deleted products, etc.).
 */
export function transformMergeCartResponse(data: unknown): unknown {
  const v3 = asObj(data);
  const cart = v3['cart'];
  const skipped = asArr(v3['skipped']);
  const cartShape = transformCartListResponse(cart);
  const itemCount = (asObj((cartShape as Record<string, unknown>)['bill']))['count'] ?? 0;

  return {
    success: true,
    count: itemCount,
    skipped,
    cart: cartShape,
  };
}

/* ============================================================== *
 * Checkout responses
 * ============================================================== */

/**
 * POST /v3/checkout/initiate → checkout.page.ts initiate-payment
 * success binding.
 *
 * v3 shape:
 *   {
 *     checkout_url: 'https://...',
 *     order_reference: 'V3-...',
 *     provider_order_ref: '123456789012',
 *     order_id: 42,
 *     idempotent: false  (true if returning cached URL for same cart)
 *   }
 *
 * Legacy mobile shape (after webview redirect, mobile reads URL
 * params; pre-redirect mobile reads response.data.url):
 *   response.data = { url: '...', order_id: 42 }
 *
 * Output:
 *   {
 *     url: <checkout_url>,                          // for webview open
 *     order_reference: <v3-only field>,             // for status polling
 *     order_id: <numeric id>,
 *     provider_order_ref: <Noon id>,
 *     idempotent: <boolean>,
 *   }
 *
 * Updated checkout.page.ts reads response.data.url to open webview
 * (same as legacy) AND response.data.order_reference for the
 * subsequent status-poll loop.
 */
export function transformInitiatePaymentResponse(data: unknown): unknown {
  const v3 = asObj(data);
  return {
    url: v3['checkout_url'] ?? '',
    order_reference: v3['order_reference'] ?? '',
    order_id: v3['order_id'] ?? 0,
    provider_order_ref: v3['provider_order_ref'] ?? '',
    idempotent: v3['idempotent'] === true,
  };
}

/**
 * GET /v3/checkout/status/{ref} → CheckoutStatusPollService binding.
 *
 * v3 shape:
 *   {
 *     order_reference, order_id, status, terminal, paid,
 *     total, currency, paid_at
 *   }
 *
 * Forwarded unchanged — these are exactly the fields the poll
 * service needs. Included in the registry to document the routing
 * + future-proof if the v3 shape evolves.
 */
export function transformCheckoutStatusResponse(data: unknown): unknown {
  // Passthrough — v3 shape is already the binding shape.
  const v3 = asObj(data);
  return {
    order_reference: v3['order_reference'] ?? '',
    order_id: v3['order_id'] ?? 0,
    status: v3['status'] ?? 'unknown',
    terminal: v3['terminal'] === true,
    paid: v3['paid'] === true,
    total: v3['total'] ?? '0.00',
    currency: v3['currency'] ?? 'AED',
    paid_at: v3['paid_at'] ?? null,
  };
}

/* ============================================================== *
 * Order responses
 * ============================================================== */

/**
 * GET /v3/orders → my-orders.page.ts binding.
 *
 * v3 shape:
 *   {
 *     orders: [ { id, order_reference, status, total, currency,
 *                 created_at, items: [...], ... }, ... ],
 *     pagination: { limit, offset, count, total }
 *   }
 *
 * Legacy mobile shape (my-orders.page.ts):
 *   response.data = [ { id, date, status, total, items: [
 *                        { store, product_image, product_name,
 *                          price, quantity, status }, ... ] }, ... ]
 *
 * v3 was designed to align with mobile: order items already have
 * `store` alias for `vendor_id` (M3.1.6e). This transform mostly
 * just unwraps the {orders, pagination} object into an array.
 */
export function transformOrderListResponse(data: unknown): unknown {
  const v3 = asObj(data);
  const orders = asArr(v3['orders']);

  // Map each order — v3 fields mostly align with mobile expectations,
  // but legacy uses `date` while v3 uses `created_at`; forward both.
  const mapped = orders.map((raw) => {
    const o = asObj(raw);
    return {
      ...o,
      // Legacy compat: 'date' alias for created_at
      date: o['created_at'] ?? o['date'] ?? '',
    };
  });

  return {
    // Mobile expects `data` to be the array directly. We're already
    // inside `data` here (toLegacyEnvelope unwrapped), so return
    // the array directly — but wrap with pagination for callers that
    // want it (my-orders.page handles infinite scroll).
    orders: mapped,
    pagination: v3['pagination'] ?? { limit: 10, offset: 0, count: mapped.length, total: mapped.length },
  };
}

/**
 * GET /v3/orders/{id} → order detail binding.
 *
 * v3 shape includes full addresses + items. Mostly aligned with
 * legacy order detail page; forward with `date` alias for created_at
 * consistency with the list view.
 */
export function transformOrderDetailResponse(data: unknown): unknown {
  const o = asObj(data);
  return {
    ...o,
    date: o['created_at'] ?? o['date'] ?? '',
  };
}

/* ============================================================== *
 * Registry
 * ============================================================== */

/**
 * v3 POST /v3/me/tickets/{id}/messages -> legacy created-message shape.
 * The mobile ticket-messages page reads `message.message`; v3 emits
 * `body`. Self-contained (no shared helpers) to avoid import coupling.
 */
export function transformTicketMessageResponse(data: unknown): unknown {
  if (data === null || typeof data !== 'object' || Array.isArray(data)) {
    return data;
  }
  const m = data as Record<string, unknown>;
  return { ...m, message: typeof m['body'] === 'string' ? m['body'] : String(m['body'] ?? '') };
}

/**
 * Map of routeKey -> response transform.
 *
 * The adapter's envelopeAndTransform consults this registry. Absence
 * means raw v3 data is forwarded (some endpoints don't need reshape).
 */
export const MUTATION_RESPONSE_TRANSFORMS: Record<string, ResponseTransform> = {
  // Cart
  'GET /cart': transformCartListResponse,
  'POST /cart/items': transformAddCartResponse,
  'PATCH /cart/items/:id': transformCartItemMutationResponse,
  'DELETE /cart/items/:id': transformCartItemMutationResponse,
  'POST /cart/merge': transformMergeCartResponse,

  // Checkout
  'POST /checkout/initiate': transformInitiatePaymentResponse,
  'GET /checkout/status/:order_reference': transformCheckoutStatusResponse,

  // Orders
  'GET /orders': transformOrderListResponse,
  'GET /orders/:id': transformOrderDetailResponse,

  // Support tickets (Group B / B2)
  'POST /me/tickets/:id/messages': transformTicketMessageResponse,
};

/**
 * Export individual transforms for direct testing.
 */
export const _internalTransforms = {
  transformCartListResponse,
  transformAddCartResponse,
  transformCartItemMutationResponse,
  transformMergeCartResponse,
  transformInitiatePaymentResponse,
  transformCheckoutStatusResponse,
  transformOrderListResponse,
  transformOrderDetailResponse,
};
