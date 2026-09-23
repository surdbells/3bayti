/**
 * Hand-off between the "pay your customization quote" action and the shared
 * Noon return page (P5), mirroring the gift-card handoff.
 *
 * Noon always 302s back to a fixed `/checkout/return?ref=…`, so the paid
 * branch of the return page consumes this stored reference to route back to
 * the customer's customization-requests page instead of the order-success
 * page.
 *
 * sessionStorage: tab-scoped, survives the same-origin round-trip from Noon,
 * clears with the tab.
 */
const KEY = 'bayti.customizationCheckoutRef';

/** Record the order reference of an in-flight customization payment. */
export function markCustomizationCheckout(orderReference: string): void {
  try {
    sessionStorage.setItem(KEY, orderReference);
  } catch {
    /* sessionStorage unavailable (private mode); non-fatal — the buyer still
       completes payment and sees the request move to Paid under My
       customization requests; only the convenience auto-redirect is skipped. */
  }
}

/** Read and clear the stored reference. Returns null when none is set. */
export function consumeCustomizationCheckoutRef(): string | null {
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
