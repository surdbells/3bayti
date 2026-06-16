/**
 * Hand-off between the gift-card purchase page and the shared Noon
 * return page (Phase E2).
 *
 * Noon always 302s back to a fixed `/checkout/return?ref=…` regardless
 * of what was bought, so the gift-card flow cannot use its own return
 * route. Instead the purchase page records the `order_reference` it just
 * initiated; the return page consumes it on the *paid* branch and routes
 * to `/account/gift-cards` instead of the order-success page.
 *
 * sessionStorage (not localStorage): scoped to the tab, survives the
 * same-origin round-trip back from Noon, and clears itself with the tab.
 */
const KEY = 'bayti.giftCardCheckoutRef';

/** Record the order reference of an in-flight gift-card purchase. */
export function markGiftCardCheckout(orderReference: string): void {
  try {
    sessionStorage.setItem(KEY, orderReference);
  } catch {
    /* sessionStorage unavailable (private mode) — non-fatal: the buyer
       still completes payment and finds the card under My Gift Cards;
       only the convenience auto-redirect is skipped. */
  }
}

/** Read and clear the stored reference. Returns null when none is set. */
export function consumeGiftCardCheckoutRef(): string | null {
  try {
    const ref = sessionStorage.getItem(KEY);
    if (ref !== null) {
      sessionStorage.removeItem(KEY);
    }
    return ref;
  } catch {
    return null;
  }
}
