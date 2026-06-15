/**
 * Mutation request transforms — legacy mutation body → v3 mutation request.
 *
 * Why this file exists
 * ====================
 * The sibling `catalog-request.transforms.ts` handles POST→GET conversion:
 * mobile's catalog call sites are POST-with-body by legacy convention but
 * v3's catalog reads are GET-with-query, so a transform extracts body keys
 * into `{pathParams, queryParams}` and the adapter rebuilds the URL.
 *
 * Cart/order/checkout endpoints are different. They are mutations — POST,
 * PATCH, or DELETE on both sides. The verb doesn't change. What changes
 * is the body shape, and sometimes a body key needs to become a path
 * parameter (e.g. legacy `{item: 42, quantity: 3}` POSTed to
 * `customer/IncreaseItem` becomes `PATCH /v3/cart/items/42` with body
 * `{quantity: 3}` — the item id moves from body to URL path).
 *
 * A mutation transform therefore returns up to three things:
 *   - `pathParams` — for `:id` substitution in the route's newPath
 *   - `body` — the v3-shaped body to send in the request
 *   - `null` body — for endpoints with no body (e.g. DELETE)
 *
 * Compare to `BodyToRouteArgs` (catalog) which returns `{pathParams,
 * queryParams}` — no body because the result is a GET.
 *
 * Adapter integration
 * ===================
 * The mobile network adapter consults `MUTATION_REQUEST_TRANSFORMS`
 * on Path 1 (exact-method route match) when `target === 'new'`. If a
 * transform is registered for the routeKey, the adapter:
 *   1. Calls the transform with the legacy body
 *   2. Uses the returned `pathParams` to resolve `:id` etc. in newPath
 *   3. Sends the request with the returned `body`
 *
 * If no transform is registered, the adapter passes the (already-
 * translated, see translateRequestBody) body through unchanged. This
 * preserves backwards compatibility — endpoints whose v3 shape already
 * matches the legacy shape work without a transform.
 *
 * Retirement
 * ==========
 * Like `catalog-request.transforms.ts`, this file is a strangler-fig
 * compat layer. Once mobile is rebuilt against v3-native semantics, call
 * sites will send v3 bodies directly and this file can be deleted.
 *
 * Naming convention
 * =================
 * Each transform is `transform<Endpoint>Request` matching the registry
 * key for grep'ability. Endpoint name matches the GlobalComponent
 * constant where possible (e.g. `transformAddToCartRequest` for the
 * `addToCart` constant).
 */

import { asRecord } from './catalog-request.transforms';

/**
 * Output of a mutation transform.
 *
 * pathParams substitutes into `:id`-style placeholders in the route's
 * newPath. Values stringified by the adapter via resolveUrl. Path-param
 * values are always strings in URL paths.
 *
 * body is the v3-shaped request body. `null` means "no body" — used for
 * DELETE-style endpoints. `undefined`-keyed values are dropped during
 * serialisation by the HttpClient. Booleans are sent as JSON booleans,
 * not "true"/"false" strings (v3 controllers expect JSON types in body,
 * unlike query strings).
 */
export type MutationTransformOutput = {
  pathParams?: Record<string, string>;
  body: Record<string, unknown> | null;
};

export type MutationBodyToRequest = (body: unknown) => MutationTransformOutput;

/**
 * Pick a key from a record, returning undefined if absent or wrong type.
 * Shared helper for the per-endpoint transforms below.
 */
function pickString(src: Record<string, unknown>, key: string): string | undefined {
  const v = src[key];
  return typeof v === 'string' && v.length > 0 ? v : undefined;
}

function pickStringOrEmpty(src: Record<string, unknown>, key: string): string {
  const v = src[key];
  return typeof v === 'string' ? v : '';
}

function pickInt(src: Record<string, unknown>, key: string): number | undefined {
  const v = src[key];
  if (typeof v === 'number' && Number.isFinite(v)) return Math.trunc(v);
  if (typeof v === 'string' && v.trim() !== '') {
    const n = Number(v);
    if (Number.isFinite(n)) return Math.trunc(n);
  }
  return undefined;
}

function pickBool(src: Record<string, unknown>, key: string): boolean {
  const v = src[key];
  if (typeof v === 'boolean') return v;
  if (typeof v === 'number') return v !== 0;
  if (typeof v === 'string') return v === 'true' || v === '1';
  return false;
}

/* ============================================================== *
 * Cart mutations
 * ============================================================== */

/**
 * `addToCart` — POST /customer/addToCart → POST /v3/cart/items.
 *
 * Legacy body (~17 keys, many unused server-side after the v3 redesign):
 *   id, token (handled by translateRequestBody)
 *   store, customer_name, customer_email, product_name, product_desc,
 *   product_image, price, discount, cart_code  (DROP — v3 derives these
 *                                                from product_id +
 *                                                authenticated user)
 *   product_id  (KEEP)
 *   quantity    (KEEP)
 *   size, color (KEEP — variant fields)
 *   is_custom, measurement, extra_measurement, note  (KEEP — custom-tailoring)
 *
 * v3 body:
 *   { product_id, quantity, size?, color?, is_custom?, measurement?,
 *     extra_measurement?, note? }
 *
 * v3 derives store/vendor_id from the product, customer name/email from
 * the JWT, price from the product (snapshotted server-side at add time),
 * and ignores cart_code entirely (server-side cart ownership via JWT).
 */
export function transformAddToCartRequest(body: unknown): MutationTransformOutput {
  const b = asRecord(body);
  const out: Record<string, unknown> = {};

  const productId = pickInt(b, 'product_id');
  if (productId !== undefined) {
    out['product_id'] = productId;
  }

  const quantity = pickInt(b, 'quantity');
  // Quantity is required by v3; if missing or 0, send 1 as a safe default.
  // Page-level validation should have caught the 0 case before this; this
  // is defence-in-depth.
  out['quantity'] = quantity !== undefined && quantity > 0 ? quantity : 1;

  // Optional variant + custom-tailoring fields. Only forward non-empty
  // values to keep v3 bodies minimal; empty strings would be rejected
  // by some validation rules.
  const size = pickString(b, 'size');
  if (size !== undefined) out['size'] = size;

  const color = pickString(b, 'color');
  if (color !== undefined) out['color'] = color;

  // is_custom may come as string ("1"), number (1), or boolean. Normalise.
  // Always forward — server defaults to false, but explicit is clearer.
  if ('is_custom' in b) {
    out['is_custom'] = pickBool(b, 'is_custom');
  }

  const measurement = pickString(b, 'measurement');
  if (measurement !== undefined) out['measurement'] = measurement;

  const extraMeasurement = pickString(b, 'extra_measurement');
  if (extraMeasurement !== undefined) out['extra_measurement'] = extraMeasurement;

  const note = pickString(b, 'note');
  if (note !== undefined) out['note'] = note;

  return { body: out };
}

/**
 * `IncreaseItem` — POST /customer/IncreaseItem → PATCH /v3/cart/items/{id}.
 *
 * Legacy body: { id, token, item: <itemId>, quantity: <new_qty> }
 * v3 path:     PATCH /v3/cart/items/{item}
 * v3 body:     { quantity: <new_qty> }
 *
 * Note: the page already sets quantity = current + 1 (delta done client-
 * side), so the value forwarded is the ABSOLUTE target quantity, which
 * matches v3's semantics (PATCH /v3/cart/items/{id} sets absolute qty).
 */
export function transformIncreaseItemRequest(body: unknown): MutationTransformOutput {
  const b = asRecord(body);

  const itemId = pickInt(b, 'item');
  const quantity = pickInt(b, 'quantity');

  return {
    pathParams: { id: String(itemId ?? 0) },
    body: { quantity: quantity !== undefined && quantity > 0 ? quantity : 1 },
  };
}

/**
 * `DecreaseItem` — POST /customer/decreaseItem → PATCH /v3/cart/items/{id}.
 *
 * Identical shape transformation to IncreaseItem; semantically the page
 * has done `quantity = current - 1` before calling. v3 receives an
 * absolute quantity and sets it.
 *
 * Edge case: if the page calls DecreaseItem when current quantity is 1,
 * the new value is 0. v3 rejects quantity=0 with a 422 + "DELETE to
 * remove" message — that's correct behaviour; the page should call
 * removeItem instead. We forward the 0 (v3 returns the proper error)
 * rather than silently coercing — surfacing the bug at v3 is better
 * than masking it here.
 */
export function transformDecreaseItemRequest(body: unknown): MutationTransformOutput {
  const b = asRecord(body);

  const itemId = pickInt(b, 'item');
  const quantity = pickInt(b, 'quantity');

  return {
    pathParams: { id: String(itemId ?? 0) },
    body: { quantity: quantity ?? 0 },
  };
}

/**
 * `removeFromCart` — POST /customer/removeFromCart → DELETE /v3/cart/items/{id}.
 *
 * Legacy body: { id, token, item: <itemId> }
 * v3 method:   DELETE /v3/cart/items/{item}
 * v3 body:     null (no body for DELETE)
 *
 * Note: legacy uses POST; v3 uses DELETE. The routing table has
 * 'DELETE /cart/items/:id' as the routeKey, so when the page calls
 * post_request the adapter's exact-method lookup (Path 1) will NOT
 * match, and the cross-verb path (Path 2) is only POST→GET today.
 * Therefore the page's call site must be updated alongside this
 * transform to call delete_request instead of post_request. Phase B
 * handles the page-level integration.
 */
export function transformRemoveCartItemRequest(body: unknown): MutationTransformOutput {
  const b = asRecord(body);

  const itemId = pickInt(b, 'item');

  return {
    pathParams: { id: String(itemId ?? 0) },
    body: null,
  };
}

/**
 * `cartMerge` — POST /v3/cart/merge (no legacy equivalent).
 *
 * Called by the post-sign-in flow with the device-local cart's items.
 * v3 body shape:
 *   { items: [{ product_id, quantity, size?, color?, is_custom?,
 *               measurement?, extra_measurement?, note? }, ...] }
 *
 * The LocalCartService stores items in the same shape, so this transform
 * is mostly a passthrough that validates the shape and drops unexpected
 * keys.
 */
export function transformMergeCartRequest(body: unknown): MutationTransformOutput {
  const b = asRecord(body);

  const rawItems = Array.isArray(b['items']) ? b['items'] : [];
  const items: Array<Record<string, unknown>> = [];

  for (const raw of rawItems) {
    if (raw === null || typeof raw !== 'object' || Array.isArray(raw)) continue;
    const item = raw as Record<string, unknown>;

    const productId = pickInt(item, 'product_id');
    if (productId === undefined) continue; // skip malformed entries

    const quantity = pickInt(item, 'quantity');
    const cleaned: Record<string, unknown> = {
      product_id: productId,
      quantity: quantity !== undefined && quantity > 0 ? quantity : 1,
    };

    const size = pickString(item, 'size');
    if (size !== undefined) cleaned['size'] = size;

    const color = pickString(item, 'color');
    if (color !== undefined) cleaned['color'] = color;

    if ('is_custom' in item) cleaned['is_custom'] = pickBool(item, 'is_custom');

    const measurement = pickString(item, 'measurement');
    if (measurement !== undefined) cleaned['measurement'] = measurement;

    const extraMeasurement = pickString(item, 'extra_measurement');
    if (extraMeasurement !== undefined) cleaned['extra_measurement'] = extraMeasurement;

    const note = pickString(item, 'note');
    if (note !== undefined) cleaned['note'] = note;

    items.push(cleaned);
  }

  return { body: { items } };
}

/* ============================================================== *
 * Checkout
 * ============================================================== */

/**
 * `initiatePayment` — POST /customer/payment/initiate_payment
 *                  → POST /v3/checkout/initiate.
 *
 * Legacy body is large (mirrors the page's order-summary state). v3
 * derives nearly everything server-side from the cart + addresses-of-
 * record, so the v3 body is minimal:
 *
 *   { channel: 'MOBILE' (always for this app),
 *     delivery_fee?, discount?  (server enforces but accepts overrides),
 *     billing_address_id?, shipping_address_id? (optional overrides) }
 *
 * All money values in v3 are decimal strings ("99.50"). Mobile may send
 * them as numbers; the transform stringifies.
 */
export function transformInitiatePaymentRequest(body: unknown): MutationTransformOutput {
  const b = asRecord(body);
  const out: Record<string, unknown> = {
    channel: 'MOBILE',
  };

  // Delivery fee — legacy uses 'delivery' or 'delivery_fee'. Stringify.
  const deliveryRaw = b['delivery_fee'] ?? b['delivery'];
  if (deliveryRaw !== undefined && deliveryRaw !== null && deliveryRaw !== '') {
    out['delivery_fee'] = String(deliveryRaw);
  }

  // Discount — similar.
  const discountRaw = b['discount'];
  if (discountRaw !== undefined && discountRaw !== null && discountRaw !== '') {
    out['discount'] = String(discountRaw);
  }

  // Address overrides — legacy may pass these from a "change address at
  // checkout" UI. v3 accepts them and validates ownership.
  const billingAddressId = pickInt(b, 'billing_address_id');
  if (billingAddressId !== undefined) out['billing_address_id'] = billingAddressId;

  const shippingAddressId = pickInt(b, 'shipping_address_id');
  if (shippingAddressId !== undefined) out['shipping_address_id'] = shippingAddressId;

  return { body: out };
}

/* ============================================================== *
 * Orders (read-only — no body transforms needed)
 * ============================================================== */
// Order list/detail use GET — body transforms aren't applicable. The
// query-param transform for the list (limit, offset) lives in
// order-request.transforms.ts and is consumed by tryConvertPostToGet
// (Path 2 of the adapter), not Path 1.

/* ============================================================== *
 * Registry
 * ============================================================== */

/**
 * Map of routeKey -> mutation request transform.
 *
 * Adapter consults this on Path 1 (exact-method match) when
 * target === 'new'. Absence means "pass body through unchanged"
 * (after translateRequestBody has stripped legacy id/token).
 *
 * Stringly-keyed by `METHOD logical-path` to match the routing table
 * in feature-flags.ts. Use the LOGICAL path (with `:id` placeholders),
 * not a substituted URL.
 */
/**
 * `follow` — POST /customer/follow → POST /v3/me/following/{vendorId}.
 *
 * Legacy body: { id, token, store_id: <vendorId>, store_name }
 * v3 path:     POST /v3/me/following/{store_id}
 * v3 body:     {} (vendor from path, user from token — controller reads
 *              nothing from the body)
 */
export function transformFollowVendorRequest(body: unknown): MutationTransformOutput {
  const b = asRecord(body);
  const vendorId = pickInt(b, 'store_id');
  return {
    pathParams: { vendorId: String(vendorId ?? 0) },
    body: {},
  };
}

/**
 * `unfollow` — POST /customer/unfollow → DELETE /v3/me/following/{vendorId}.
 * Same id extraction; DELETE carries no body.
 */
export function transformUnfollowVendorRequest(body: unknown): MutationTransformOutput {
  const b = asRecord(body);
  const vendorId = pickInt(b, 'store_id');
  return {
    pathParams: { vendorId: String(vendorId ?? 0) },
    body: null,
  };
}

/**
 * `create_ticket` — POST /customer/create_ticket → POST /v3/me/tickets.
 *
 * Legacy body: { id, token, store: <vendorId>, subject, message }
 * v3 body:     { subject, message, vendor_id }  (the controller reads
 *              body ?? message for the text; user from token)
 */
export function transformCreateTicketRequest(body: unknown): MutationTransformOutput {
  const b = asRecord(body);
  const out: Record<string, unknown> = {
    subject: String(b['subject'] ?? ''),
    message: String(b['message'] ?? ''),
  };
  const store = pickInt(b, 'store');
  if (store !== undefined && store > 0) {
    out['vendor_id'] = store;
  }
  return { body: out };
}

/**
 * `send-ticket-message` — POST /customer/send-ticket-message
 *                       → POST /v3/me/tickets/{id}/messages.
 *
 * Legacy body: { id, token, ticket: <ticketId>, userId, message }
 * v3 path:     POST /v3/me/tickets/{ticket}/messages
 * v3 body:     { message }
 */
export function transformSendTicketMessageRequest(body: unknown): MutationTransformOutput {
  const b = asRecord(body);
  const ticketId = pickInt(b, 'ticket');
  return {
    pathParams: { id: String(ticketId ?? 0) },
    body: { message: String(b['message'] ?? '') },
  };
}

/**
 * `add-review` — POST /customer/add-review → POST /v3/vendors/{vendorId}/reviews.
 * Store review: legacy body { id, token, store_id, star, title, comment }.
 * v3 path takes the vendor id; body carries star/title/comment.
 */
export function transformAddVendorReviewRequest(body: unknown): MutationTransformOutput {
  const b = asRecord(body);
  const vendorId = pickInt(b, 'store_id');
  const star = pickInt(b, 'star') ?? pickInt(b, 'rating') ?? 0;
  const out: Record<string, unknown> = { star };
  if (typeof b['title'] === 'string') out['title'] = b['title'];
  if (typeof b['comment'] === 'string') out['comment'] = b['comment'];
  return { pathParams: { vendorId: String(vendorId ?? 0) }, body: out };
}

/**
 * `helpful` — POST /customer/helpful → POST /v3/reviews/{id}/helpful.
 * Legacy body { id, token, reviewId } → review id in path, empty body.
 */
export function transformMarkHelpfulRequest(body: unknown): MutationTransformOutput {
  const b = asRecord(body);
  const reviewId = pickInt(b, 'reviewId');
  return { pathParams: { id: String(reviewId ?? 0) }, body: {} };
}

/**
 * `delete-review` — POST /customer/settings/delete-review
 *                 → DELETE /v3/me/reviews/{id}.
 * Legacy body { id, token, review } → review id in path, no body.
 */
export function transformDeleteReviewRequest(body: unknown): MutationTransformOutput {
  const b = asRecord(body);
  const reviewId = pickInt(b, 'review');
  return { pathParams: { id: String(reviewId ?? 0) }, body: null };
}

export const MUTATION_REQUEST_TRANSFORMS: Record<string, MutationBodyToRequest> = {
  // Cart mutations
  'POST /cart/items': transformAddToCartRequest,
  'PATCH /cart/items/:id': transformIncreaseItemRequest,
  // DecreaseItem also maps to PATCH /cart/items/:id — same routeKey.
  // The page passes the absolute new qty either way, so one entry
  // serves both legacy directions. (If DecreaseItem ever needs
  // different transform logic, route it through a discriminator
  // before this registry lookup.)
  'DELETE /cart/items/:id': transformRemoveCartItemRequest,
  'POST /cart/merge': transformMergeCartRequest,

  // Checkout
  'POST /checkout/initiate': transformInitiatePaymentRequest,

  // Follow (vendor id moves from legacy body.store_id to the v3 path)
  'POST /following/:vendorId': transformFollowVendorRequest,
  'DELETE /following/:vendorId': transformUnfollowVendorRequest,

  // Support tickets (Group B / B2)
  'POST /me/tickets': transformCreateTicketRequest,
  'POST /me/tickets/:id/messages': transformSendTicketMessageRequest,

  // Reviews (Group B / B1)
  'POST /vendors/:vendorId/reviews': transformAddVendorReviewRequest,
  'POST /reviews/:id/helpful': transformMarkHelpfulRequest,
  'DELETE /me/reviews/:id': transformDeleteReviewRequest,
};

/**
 * Export the individual transforms for direct testing without
 * going through the registry.
 */
export const _internalTransforms = {
  transformAddToCartRequest,
  transformIncreaseItemRequest,
  transformDecreaseItemRequest,
  transformRemoveCartItemRequest,
  transformMergeCartRequest,
  transformInitiatePaymentRequest,
};
