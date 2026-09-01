/**
 * Order domain types, mirror apps/api OrderSerializer.
 *
 * The API returns monetary amounts as decimal STRINGS to avoid IEEE 754
 * rounding. ISO 8601 timestamps for dates (created_at, paid_at).
 *
 * Lifecycle statuses (from apps/api Domain\Order\Order):
 *   pending_payment | paid | preparing | shipped | delivered |
 *   cancelled | refunded | partially_refunded
 *
 * Only the customer-visible subset is enumerated below. The string
 * type for `status` accepts any value the API might send.
 */

/**
 * Order address snapshot, mirrors apps/api OrderSerializer::addressShape
 * (Http/Serializers/OrderSerializer.php). The API captures the address at
 * checkout time onto the order, exposing these exact keys. Every field is
 * nullable because the snapshot columns are nullable on the entity.
 *
 * NOTE: an earlier version of this type used invented field names
 * (recipient_name / street_address / emirate / area) that never matched
 * the payload, so the order-detail address rendered blank (` , , `).
 * Keep this aligned with addressShape().
 */
export interface OrderAddress {
  first_name: string | null;
  last_name: string | null;
  phone: string | null;
  email: string | null;
  street: string | null;
  city: string | null;
  state_province: string | null;
  country_code: string | null;
  postal_code: string | null;
}

export interface OrderItem {
  id: number;
  product_id: number;
  vendor_id: number;
  product_name: string;
  product_image: string | null;
  quantity: number;
  unit_price: string;
  subtotal: string;
  size: string | null;
  color: string | null;
  is_custom: boolean;
  measurement: string | null;
  extra_measurement: string | null;
  note: string | null;
  /** Per-item status (mostly mirrors order status but can diverge
   *  on partial fulfilment). */
  item_status: string;
  /** Store/vendor numeric id for multi-vendor cart display. */
  store: number;
}

export interface AppliedPromo {
  code: string;
  /** 'percent' | 'fixed', historical snapshot. */
  type: string;
  /** Snapshot value (percent or fixed amount). */
  value: string;
  /** Server-computed discount amount, frozen at redemption time. */
  discount_amount: string;
}

/** Compact return-request summary attached to orders by listShape
 *  when the controller passes a returns list. */
export interface ReturnSummary {
  id: number;
  status: string;
  reason: string | null;
  requested_at: string;
  item_count: number;
}

export interface OrderListItem {
  id: number;
  order_reference: string;
  status: string;
  /** ISO 8601 timestamp. */
  date: string;
  subtotal: string;
  delivery_fee: string;
  discount: string;
  total: string;
  currency: string;
  paid_at: string | null;
  items: OrderItem[];
  applied_promo: AppliedPromo | null;
  /** Optional, only present when the API was asked for returns. */
  returns?: ReturnSummary[];
}

/** Detail extends list shape with billing + shipping address snapshots.
 *  Either address may be null (the entity's snapshot columns are nullable
 *  and addressShape() returns null for an absent address). */
export interface Order extends OrderListItem {
  billing_address: OrderAddress | null;
  shipping_address: OrderAddress | null;
  /** Slowest-store delivery range for the whole order; null for gift cards. */
  delivery_estimate?: { min_days: number; max_days: number } | null;
}

/**
 * A single customer-visible event from GET /v3/orders/:id/timeline
 * (GetOrderTimelineController). The controller whitelists customer-safe
 * event types and collapses internal actors to a generic { type }.
 */
export interface OrderTimelineEvent {
  id: string;
  /** Machine event type, e.g. 'order.paid' / 'return.refunded'. */
  type: string;
  /** ISO 8601 timestamp, or null when the event carries no time. */
  occurred_at: string | null;
  /** Generic actor, 'customer' | 'store' | 'system'. */
  actor: { type: string };
  /** Human-readable one-line summary rendered as the step label. */
  summary: string;
}

/** GET /v3/orders/:id/timeline response body, `{ events, total }`. */
export interface OrderTimelineResponse {
  events: OrderTimelineEvent[];
  total: number;
}

/** Lifecycle statuses we surface as customer-friendly labels. The
 *  raw string from the API may be more granular; map unknowns to
 *  the closest customer-facing label. */
/**
 * Status → i18n key map.
 *
 * Source of truth: apps/api Domain\Order\Order::STATUS_* constants.
 * The eight real statuses the API can emit are:
 *   pending_payment, paid, fulfilling, shipped, delivered,
 *   cancelled, refunded, failed
 *
 * (Earlier Y.2 work mistakenly listed 'preparing' and
 * 'partially_refunded', which the API never sends, and omitted
 * 'fulfilling' and 'failed'. Reconciled in Y.3-D.)
 *
 * Unknown statuses fall back to the raw string at the call site.
 */
export const ORDER_STATUS_LABELS: Record<string, string> = {
  pending_payment: 'orders.status.pendingPayment',
  paid: 'orders.status.paid',
  fulfilling: 'orders.status.fulfilling',
  shipped: 'orders.status.shipped',
  delivered: 'orders.status.delivered',
  cancelled: 'orders.status.cancelled',
  refunded: 'orders.status.refunded',
  failed: 'orders.status.failed',
};

/** Pagination params for GET /v3/orders. */
export interface OrderListParams {
  limit?: number;
  offset?: number;
}

/** Pagination metadata echoed back by GET /v3/orders. */
export interface OrderListPagination {
  limit: number;
  offset: number;
  /** Number of items in this page. */
  count: number;
  /** Total number of orders for the user across all pages. */
  total: number;
}

/** Paginated list response. The API returns an OBJECT body
 *  `{ orders: OrderListItem[], pagination: {...} }`, NOT a bare array.
 *  Read `response.orders` for the page and `response.pagination` for
 *  the page metadata. */
export interface OrderListResponse {
  orders: OrderListItem[];
  pagination: OrderListPagination;
}

/** Detail response. GET /v3/orders/:id wraps the order in `{ order }`. */
export interface OrderDetailResponse {
  order: Order;
}

/** Summary of a completed cancellation, returned alongside the updated
 *  order by POST /v3/orders/:id/cancel. */
export interface OrderCancellation {
  was_already_cancelled: boolean;
  refund_issued: boolean;
  refund_amount: string | null;
}

/** Cancel response. POST /v3/orders/:id/cancel wraps the updated order
 *  in `{ order, cancellation }`. */
export interface OrderCancelResponse {
  order: Order;
  cancellation: OrderCancellation;
}

/** Customer return-request reasons, mirrors apps/api ALL_REASONS. */
export type ReturnReason =
  | 'defective'
  | 'wrong_item'
  | 'damaged_in_transit'
  | 'not_as_described'
  | 'changed_mind'
  | 'size_issue'
  | 'other';

export const RETURN_REASONS: ReturnReason[] = [
  'defective',
  'wrong_item',
  'damaged_in_transit',
  'not_as_described',
  'changed_mind',
  'size_issue',
  'other',
];

/** Translation keys for each reason. */
export const RETURN_REASON_LABELS: Record<ReturnReason, string> = {
  defective: 'orders.returns.reason.defective',
  wrong_item: 'orders.returns.reason.wrongItem',
  damaged_in_transit: 'orders.returns.reason.damagedInTransit',
  not_as_described: 'orders.returns.reason.notAsDescribed',
  changed_mind: 'orders.returns.reason.changedMind',
  size_issue: 'orders.returns.reason.sizeIssue',
  other: 'orders.returns.reason.other',
};

/** Submission payload for POST /v3/orders/:id/returns. */
export interface SubmitReturnInput {
  reason: ReturnReason;
  /** Required when reason='other', optional otherwise. */
  customer_notes: string | null;
  /** OrderItem ids to return. Must be non-empty per server-side validation. */
  order_item_ids: number[];
  /** 0-5 image files (jpeg/png/webp, max 5 MB each). */
  photos: File[];
}

/** API response shape for a created return request (post-submission).
 *  Lightweight, the Y.2 surface only needs to know whether it
 *  succeeded; Y.5 may surface a full detail page. */
export interface ReturnRequestResponse {
  id: number;
  status: string;
  reason: ReturnReason;
  requested_at: string;
  item_count: number;
}

/** Maximum file size for a single photo per server-side validation. */
export const RETURN_PHOTO_MAX_BYTES = 5 * 1024 * 1024;

/** Maximum number of photos allowed in a single submission. */
export const RETURN_PHOTO_MAX_COUNT = 5;

/** Accepted MIME types for return photos per server-side validation. */
export const RETURN_PHOTO_ACCEPT = 'image/jpeg,image/png,image/webp';
