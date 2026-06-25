import { Injectable } from '@angular/core';
import { InAppBrowser } from '@capgo/inappbrowser';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { I18nService } from '../../i18n.service';

/**
 * Shared gift-card payment flow.
 *
 * Encapsulates the Noon checkout sequence used by both the purchase page
 * (gift-cards.page) and the wallet (my-gift-cards.page → "Complete payment"):
 *
 *   1. POST /checkout/initiate { gift_card_purchase_id }  (idempotent — returns
 *      the cached checkout URL, so calling it again RESUMES an unpaid card)
 *   2. open the returned checkout_url in the in-app browser webview
 *   3. poll GET /checkout/status/:order_reference until paid/failed
 *
 * Callbacks let each caller decide where to navigate on the terminal states.
 */
@Injectable({ providedIn: 'root' })
export class GiftCardPaymentService {

  private readonly v3ReturnPrefix = 'https://api-v3.3bayti.ae/v3/checkout/return/';

  constructor(
    private network: MobileNetworkAdapter,
    private notify: AxNotificationService,
    private i18n: I18nService,
  ) {}

  /**
   * Start (or resume) payment for an already-created gift card.
   *
   * @param giftCardId  the pending_payment gift card purchase id
   * @param authToken   bearer token for the authed checkout calls
   * @param handlers    terminal-state callbacks (paid / failed / skipped)
   */
  pay(
    giftCardId: number,
    authToken: string,
    handlers: {
      onPaid: () => void;
      onFailed?: () => void;
      onGatewaySkipped?: () => void;
    },
  ): void {
    const initPayload = {
      gift_card_purchase_id: giftCardId,
      channel: 'MOBILE',
    };

    this.network.post_v3('POST /checkout/initiate', initPayload, { authToken }).subscribe({
      next: (res: any) => {
        // The MobileNetworkAdapter surfaces HTTP-level errors (422/4xx/5xx)
        // through the SUCCESS channel as { response_code, status: 'error',
        // message, data: null }. So an actionable failure — e.g. "No billing
        // address on file. Please add one before checking out." or "Your
        // billing address is incomplete (missing: …)" from
        // InitiateCheckoutController — arrives HERE, not in `error`. The old
        // code only looked at res.data.url (null on error) and showed the
        // generic "could not start payment" toast, masking the real reason.
        // Detect the error envelope and surface the server message instead.
        if (res?.status === 'error' || (res?.response_code && res.response_code !== 200 && res.response_code !== 201)) {
          this.notify.error(res?.message || this.i18n.t('gc_error_start_payment_add_address'));
          handlers.onFailed?.();
          return;
        }

        // Gateway skipped (denomination = 0 — shouldn't happen but handle it).
        if (res?.data?.gateway_skipped === true) {
          this.notify.success(this.i18n.t('gc_activated'));
          (handlers.onGatewaySkipped ?? handlers.onPaid)();
          return;
        }

        // transformInitiatePaymentResponse maps checkout_url -> url.
        const checkoutUrl = res?.data?.url ?? res?.data?.checkout_url;
        const orderReference = res?.data?.order_reference ?? '';

        if (!checkoutUrl || !orderReference) {
          this.notify.error(this.i18n.t('gc_error_start_payment'));
          return;
        }

        this.openPaymentWebview(checkoutUrl, orderReference, giftCardId, authToken, handlers);
      },
      error: (err: any) => {
        // Only transport-level errors (status 0) reach this channel. Still
        // try the structured error paths before falling back to the generic
        // "add a delivery address" hint, since that's the most common cause.
        const msg = err?.error?.error?.message
          ?? err?.error?.message
          ?? err?.message;
        this.notify.error(msg || this.i18n.t('gc_error_start_payment_add_address'));
        handlers.onFailed?.();
      },
    });
  }

  // Open Noon webview and listen for the return URL.
  private openPaymentWebview(
    url: string,
    orderReference: string,
    giftCardId: number,
    authToken: string,
    handlers: { onPaid: () => void; onFailed?: () => void; onGatewaySkipped?: () => void },
  ): void {
    let processed = false;
    let listenerHandle: any = null;
    const returnPrefix = this.v3ReturnPrefix;

    InAppBrowser.openWebView({ url, title: this.i18n.t('gc_webview_title') }).catch((err: any) => {
      console.error('openWebView failed', err);
      this.notify.error(this.i18n.t('gc_error_open_payment_page'));
    });

    InAppBrowser.addListener('urlChangeEvent', (info: any) => {
      try {
        if (processed) return;
        const urlStr: string = info?.url ?? '';
        if (!urlStr.startsWith(returnPrefix)) return;

        processed = true;
        try {
          if (typeof listenerHandle?.remove === 'function') listenerHandle.remove();
          else if (typeof listenerHandle === 'function') listenerHandle();
        } catch { /* ignore */ }

        const closeAndPoll = async () => {
          try { await InAppBrowser.close(); } catch { /* ignore */ }
          this.pollGiftCardPayment(orderReference, giftCardId, authToken, handlers);
        };
        closeAndPoll();
      } catch (err) {
        console.error('urlChangeEvent error', err);
      }
    }).then((handle: any) => {
      listenerHandle = handle;
    }).catch((err: any) => {
      console.error('addListener failed', err);
    });
  }

  // Poll checkout status until paid/failed, then fire the matching callback.
  private pollGiftCardPayment(
    orderReference: string,
    giftCardId: number,
    authToken: string,
    handlers: { onPaid: () => void; onFailed?: () => void; onGatewaySkipped?: () => void },
    attempts = 0,
  ): void {
    if (attempts > 12) { // 12 × 2.5s = 30s timeout
      this.notify.error(this.i18n.t('gc_error_payment_timeout'));
      handlers.onPaid();
      return;
    }

    setTimeout(() => {
      this.network.get_v3('GET /checkout/status/:order_reference', {
        authToken,
        pathParams: { order_reference: orderReference },
      }).subscribe({
        next: (res: any) => {
          const data = res?.data ?? res;
          if (data?.paid === true || data?.status === 'paid') {
            this.notify.success(this.i18n.t('gc_activated_emoji'));
            handlers.onPaid();
          } else if (data?.status === 'failed' || data?.status === 'cancelled') {
            this.notify.error(this.i18n.t('gc_error_payment_incomplete'));
            (handlers.onFailed ?? handlers.onPaid)();
          } else {
            // Not yet terminal — poll again.
            this.pollGiftCardPayment(orderReference, giftCardId, authToken, handlers, attempts + 1);
          }
        },
        error: () => {
          // Network error — retry.
          this.pollGiftCardPayment(orderReference, giftCardId, authToken, handlers, attempts + 1);
        },
      });
    }, 2500);
  }
}
