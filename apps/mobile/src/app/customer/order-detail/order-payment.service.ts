import { Injectable } from '@angular/core';
import { InAppBrowser } from '@capgo/inappbrowser';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { I18nService } from '../../i18n.service';

/**
 * Shared "complete payment" flow for an existing pending_payment ORDER.
 *
 * Mirrors GiftCardPaymentService but resumes an order rather than a gift
 * card, the mobile equivalent of the web "retry payment":
 *
 *   1. POST /checkout/initiate { order_reference }  (server reuses the order's
 *      existing Noon session when one is on file, else initiates a fresh one -
 *      never creates a duplicate order)
 *   2. open the returned checkout_url in the in-app browser webview
 *   3. poll GET /checkout/status/:order_reference until paid/failed
 *
 * Terminal-state callbacks let the caller decide where to navigate.
 */
@Injectable({ providedIn: 'root' })
export class OrderPaymentService {

  private readonly v3ReturnPrefix = 'https://api-v3.3bayti.ae/v3/checkout/return/';

  constructor(
    private network: MobileNetworkAdapter,
    private notify: AxNotificationService,
    private i18n: I18nService,
  ) {}

  /**
   * Start (or resume) payment for an existing pending_payment order.
   *
   * @param orderReference the order's V3-… reference
   * @param authToken      bearer token for the authed checkout calls
   * @param handlers       terminal-state callbacks
   */
  pay(
    orderReference: string,
    authToken: string,
    handlers: {
      onPaid: () => void;
      onFailed?: () => void;
    },
  ): void {
    const initPayload = {
      order_reference: orderReference,
      channel: 'MOBILE',
    };

    this.network.post_v3('POST /checkout/initiate', initPayload, { authToken }).subscribe({
      next: (res: any) => {
        // MobileNetworkAdapter surfaces HTTP-level errors (4xx/5xx) through the
        // SUCCESS channel as { response_code, status: 'error', message }. Detect
        // the error envelope and show the server's actual message.
        if (res?.status === 'error' || (res?.response_code && res.response_code !== 200 && res.response_code !== 201)) {
          this.notify.error(res?.message || this.i18n.t('gc_error_start_payment'));
          handlers.onFailed?.();
          return;
        }

        // transformInitiatePaymentResponse maps checkout_url -> url.
        const checkoutUrl = res?.data?.url ?? res?.data?.checkout_url;
        const ref = res?.data?.order_reference ?? orderReference;

        if (!checkoutUrl || !ref) {
          this.notify.error(this.i18n.t('gc_error_start_payment'));
          handlers.onFailed?.();
          return;
        }

        this.openPaymentWebview(checkoutUrl, ref, authToken, handlers);
      },
      error: (err: any) => {
        // Only transport-level errors (status 0) reach this channel.
        const msg = err?.error?.error?.message ?? err?.error?.message ?? err?.message;
        this.notify.error(msg || this.i18n.t('gc_error_start_payment'));
        handlers.onFailed?.();
      },
    });
  }

  // Open the Noon webview and listen for the return URL.
  private openPaymentWebview(
    url: string,
    orderReference: string,
    authToken: string,
    handlers: { onPaid: () => void; onFailed?: () => void },
  ): void {
    let processed = false;
    let listenerHandle: any = null;
    const returnPrefix = this.v3ReturnPrefix;

    InAppBrowser.openWebView({ url, title: this.i18n.t('mgc_complete_payment') }).catch((err: any) => {
      console.error('openWebView failed', err);
      this.notify.error(this.i18n.t('gc_error_open_payment_page'));
    });

    InAppBrowser.addListener('urlChangeEvent', (info: any) => {
      try {
        if (processed) return;
        const urlStr: string = info?.url ?? '';

        // Two shapes surface once Noon finishes:
        //   v3 API:  https://api-v3.3bayti.ae/v3/checkout/return/{ref}
        //   Web:     {WEB_APP_URL}/checkout/return?ref={ref}
        // The API endpoint 302-redirects to the web page and the InAppBrowser
        // surfaces the FINAL committed URL, so match both. Whichever wins first.
        let parsed: URL | null = null;
        try { parsed = new URL(urlStr); } catch { parsed = null; }

        const matchesApiReturn = urlStr.startsWith(returnPrefix);
        const matchesWebReturn =
          !!parsed &&
          parsed.pathname.replace(/\/+$/, '') === '/checkout/return' &&
          parsed.searchParams.has('ref');

        if (!matchesApiReturn && !matchesWebReturn) return;

        processed = true;
        try {
          if (typeof listenerHandle?.remove === 'function') listenerHandle.remove();
          else if (typeof listenerHandle === 'function') listenerHandle();
        } catch { /* ignore */ }

        const closeAndPoll = async () => {
          try { await InAppBrowser.close(); } catch { /* ignore */ }
          this.pollOrderPayment(orderReference, authToken, handlers);
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
  private pollOrderPayment(
    orderReference: string,
    authToken: string,
    handlers: { onPaid: () => void; onFailed?: () => void },
    attempts = 0,
  ): void {
    if (attempts > 12) { // 12 × 2.5s = 30s timeout
      // Unknown outcome, the webhook may still land. Don't claim success or
      // failure; send the caller to refresh, showing an honest message.
      this.notify.info(this.i18n.t('text_payment_processing_check_later'));
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
            this.notify.success(this.i18n.t('order_pay_success'));
            handlers.onPaid();
          } else if (data?.status === 'failed' || data?.status === 'cancelled') {
            this.notify.error(this.i18n.t('order_pay_incomplete'));
            (handlers.onFailed ?? handlers.onPaid)();
          } else {
            this.pollOrderPayment(orderReference, authToken, handlers, attempts + 1);
          }
        },
        error: () => {
          this.pollOrderPayment(orderReference, authToken, handlers, attempts + 1);
        },
      });
    }, 2500);
  }
}
