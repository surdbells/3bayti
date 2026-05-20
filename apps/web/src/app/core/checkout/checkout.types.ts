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
}
