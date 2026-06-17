/**
 * Checkout domain types — the in-progress state held across the
 * three /checkout/* routes (address → review → payment).
 *
 * Persistence
 * -----------
 * State lives in sessionStorage so a refresh mid-flow doesn't reset
 * progress, but a new tab starts fresh (sessionStorage is per-tab).
 * On checkout completion or explicit cancel, the state is cleared.
 *
 * Why sessionStorage and not just a service singleton
 * ---------------------------------------------------
 * Refresh-safety. The checkout flow involves a redirect to Noon and
 * back; intermediate refreshes are legitimate (e.g. a user reloads
 * /checkout/review after picking up a discount code from email).
 * Without persistence the user re-picks their address every refresh.
 */

export interface CheckoutState {
  /** Selected shipping address id (required at /checkout/address). */
  shipping_address_id: number | null;
  /** Selected billing address id (defaults to shipping address). */
  billing_address_id: number | null;
  /** Promo code carried from /cart or entered at review. */
  promo_code: string | null;
}

export const EMPTY_CHECKOUT_STATE: CheckoutState = {
  shipping_address_id: null,
  billing_address_id: null,
  promo_code: null,
};

/**
 * Server response from POST /v3/checkout/initiate.
 */
export interface InitiateCheckoutResponse {
  checkout_url: string;
  order_reference: string;
  provider_order_ref: string;
  order_id: number;
}

/**
 * Request body for POST /v3/checkout/initiate.
 *
 * Channel is hardcoded to 'web' from the web client. Mobile passes
 * 'mobile'.
 */
export interface InitiateCheckoutInput {
  channel: 'web';
  delivery_fee: string;
  discount: string;
  promo_code?: string | null;
  billing_address_id?: number | null;
  shipping_address_id?: number | null;
  /**
   * Gift-card purchase flow (Phase E2). When set, the server creates a
   * single-line synthetic order to charge the buyer for the gift-card
   * denomination via Noon (cart is bypassed). Mutually exclusive with
   * cart-based fields above.
   */
  gift_card_purchase_id?: number | null;
  /**
   * Apply an existing gift card to a NORMAL cart order (Phase E5). The
   * server debits the card's balance against the order total and charges
   * only the remainder via Noon.
   */
  gift_card_code?: string | null;
}

/**
 * Server response from GET /v3/checkout/status/{order_reference}.
 *
 * Mirrors apps/api GetCheckoutStatusController. Reads ONLY local
 * Order state (never calls Noon's GET_ORDER — Noon bans aggressive
 * polling). The webhook receiver is the authoritative state-of-record
 * by the time we poll; until it arrives, status stays
 * 'pending_payment' and terminal=false, telling us to keep polling.
 */
export interface CheckoutStatusResponse {
  order_reference: string;
  order_id: number;
  /** One of OrderStatus (pending_payment | paid | fulfilling |
   *  shipped | delivered | cancelled | refunded | failed). */
  status: string;
  /** True once the order reached a state that won't change via the
   *  payment flow (paid/fulfilling/shipped/delivered/cancelled/
   *  refunded/failed). Stop polling when true. */
  terminal: boolean;
  /** True for paid + any post-paid fulfilment state. The success
   *  signal for the return page. */
  paid: boolean;
  total: string;
  currency: string;
  paid_at: string | null;
}
