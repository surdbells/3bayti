/**
 * Cart domain types.
 *
 * Mirrors apps/api CartSerializer::listShape exactly. The API returns
 * monetary amounts as decimal STRINGS (e.g. "129.50") to avoid IEEE 754
 * rounding when totals are computed server-side; the client treats them
 * as opaque strings except where it needs to multiply (line subtotal
 * is precomputed by the API).
 *
 * Currency is part of every cart payload so the UI never has to guess
 *, useful when a multi-currency rollout lands.
 */

export interface CartItem {
  /** Cart line item id (server-assigned). */
  id: number;
  /** Product id. */
  product_id: number;
  /** Display name snapshot at add-time. */
  product_name: string;
  /** Primary image URL snapshot. May be empty string when product has no image. */
  product_image: string;
  /** Quantity in the cart. */
  quantity: number;
  /** Unit price snapshot, string decimal. */
  unit_price: string;
  /** quantity × unit_price, precomputed by the API. */
  line_subtotal: string;
  /** Selected size (variant axis). null when product has no size axis. */
  size: string | null;
  /** Selected colour (variant axis). null when product has no colour axis. */
  color: string | null;
  /** True when this is a made-to-measure / custom configuration. */
  is_custom: boolean;
  /** Saved measurement profile name (when is_custom). */
  measurement: string | null;
  /** Free-text extra measurement note (when is_custom). */
  extra_measurement: string | null;
  /** Customer note attached to the line. */
  note: string | null;
}

export interface Cart {
  /** Cart id. 0 means 'no cart yet' from the API's empty-cart convention. */
  id: number;
  /** Lifecycle status ('active', 'ordered', ...). UI only acts on 'active'. */
  status: string;
  /** ISO 4217 currency code, e.g. 'AED', 'USD'. */
  currency: string;
  /** Legacy cart code (default 'PND' until checkout assigns a real one). */
  cart_code: string;
  /** Sum of line_subtotals as a decimal string. */
  subtotal: string;
  /** Total quantity across all items. NOT items.length, multi-quantity lines count their qty. */
  item_count: number;
  /** Line items. */
  items: CartItem[];
}

/**
 * Input for POST /v3/cart/items.
 *
 * Mirrors AddCartItemInput exactly.
 */
export interface AddCartItemInput {
  product_id: number;
  quantity: number;
  size?: string | null;
  color?: string | null;
  is_custom?: boolean;
  measurement?: string | null;
  extra_measurement?: string | null;
  note?: string | null;
}

/**
 * Input for PATCH /v3/cart/items/:id.
 */
export interface UpdateCartItemInput {
  quantity: number;
}

/**
 * Input for POST /v3/cart/merge.
 *
 * Mirrors MergeAnonCartInput exactly.
 */
export interface MergeAnonCartInput {
  items: AddCartItemInput[];
}

/**
 * Cart quote response, POST /v3/cart/quote.
 *
 * Includes the server-authoritative resolved promo (if any) and the
 * line breakdown with shipping + tax. Used on the cart page and
 * checkout review.
 *
 * NOTE: shape here is a faithful reconstruction from the API's
 * QuoteCartController; if a field is added server-side it must also
 * land here.
 */
export interface CartQuoteLineBreakdown {
  subtotal: string;
  shipping: string;
  tax: string;
  promo_discount: string;
  total: string;
}

export interface CartQuoteResponse {
  promo_code: string | null;
  promo_valid: boolean;
  promo_message: string | null;
  breakdown: CartQuoteLineBreakdown;
}

/**
 * Local-storage shape for the guest cart.
 *
 * We keep the same item shape as the API so merge is a one-line
 * payload construction. The guest cart has no server id; the
 * 'items' array is the source of truth.
 */
export interface GuestCart {
  items: AddCartItemInput[];
  /** Currency for display purposes. Defaults to 'AED' until we resolve
   *  a user country. */
  currency: string;
}

/** Cart-related error codes from the API. */
export const CART_ERROR_CODES = {
  PRODUCT_NOT_FOUND: 'CART_PRODUCT_NOT_FOUND',
  ITEM_NOT_FOUND: 'CART_ITEM_NOT_FOUND',
  INVALID_QUANTITY: 'CART_INVALID_QUANTITY',
  OUT_OF_STOCK: 'CART_OUT_OF_STOCK',
  PROMO_INVALID: 'CART_PROMO_INVALID',
  PROMO_EXPIRED: 'CART_PROMO_EXPIRED',
} as const;

export type CartErrorCode = (typeof CART_ERROR_CODES)[keyof typeof CART_ERROR_CODES];
