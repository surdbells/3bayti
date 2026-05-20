/**
 * Order domain types — mirror apps/api OrderSerializer.
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

export interface OrderAddress {
  id: number;
  recipient_name: string;
  recipient_phone: string;
  emirate: string;
  area: string;
  street_address: string | null;
  building_details: string | null;
  postal_code: string | null;
  label: string | null;
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
  /** 'percent' | 'fixed' — historical snapshot. */
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
  /** Optional — only present when the API was asked for returns. */
  returns?: ReturnSummary[];
}

/** Detail extends list shape with billing + shipping address snapshots. */
export interface Order extends OrderListItem {
  billing_address: OrderAddress;
  shipping_address: OrderAddress;
}

/** Lifecycle statuses we surface as customer-friendly labels. The
 *  raw string from the API may be more granular; map unknowns to
 *  the closest customer-facing label. */
export const ORDER_STATUS_LABELS: Record<string, string> = {
  pending_payment: 'orders.status.pendingPayment',
  paid: 'orders.status.paid',
  preparing: 'orders.status.preparing',
  shipped: 'orders.status.shipped',
  delivered: 'orders.status.delivered',
  cancelled: 'orders.status.cancelled',
  refunded: 'orders.status.refunded',
  partially_refunded: 'orders.status.partiallyRefunded',
};

/** Pagination params for GET /v3/orders. */
export interface OrderListParams {
  limit?: number;
  offset?: number;
}

/** Paginated list response. The API returns a bare array currently;
 *  if pagination metadata lands we'll evolve this. */
export type OrderListResponse = OrderListItem[];
