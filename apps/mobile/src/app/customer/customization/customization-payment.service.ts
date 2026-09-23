import { Injectable } from '@angular/core';
import { InAppBrowser } from '@capgo/inappbrowser';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { I18nService } from '../../i18n.service';

/**
 * Pay for an accepted bespoke-customization quote (P5).
 *
 * Mirrors GiftCardPaymentService exactly — the accepted quote is charged via
 * the same Noon checkout as a gift card (an item-less synthetic order):
 *   1. POST /checkout/initiate { customization_request_id }
 *   2. open the returned checkout_url in the in-app webview
 *   3. poll GET /checkout/status/:order_reference until paid/failed
 */
@Injectable({ providedIn: 'root' })
export class CustomizationPaymentService {
  private readonly v3ReturnPrefix = 'https://api-v3.3bayti.ae/v3/checkout/return/';

  constructor(
    private network: MobileNetworkAdapter,
    private notify: AxNotificationService,
    private i18n: I18nService,
  ) {}

  /**
   * Start payment for an accepted customization request.
   *
   * @param requestId  the accepted customization request id
   * @param authToken  bearer token for the authed checkout calls
   * @param handlers   terminal-state callbacks
   */
  pay(
    requestId: number,
    authToken: string,
    handlers: { onPaid: () => void; onFailed?: () => void },
  ): void {
    const initPayload = { customization_request_id: requestId, channel: 'MOBILE' };

    this.network.post_v3('POST /checkout/initiate', initPayload, { authToken }).subscribe({
      next: (res: any) => {
        // The adapter surfaces HTTP errors through the SUCCESS channel.
        if (res?.status === 'error' || (res?.response_code && res.response_code !== 200 && res.response_code !== 201)) {
          this.notify.error(res?.message || this.i18n.t('cust_error_start_payment'));
          handlers.onFailed?.();
          return;
        }

        const checkoutUrl = res?.data?.url ?? res?.data?.checkout_url;
        const orderReference = res?.data?.order_reference ?? '';
        if (!checkoutUrl || !orderReference) {
          this.notify.error(this.i18n.t('cust_error_start_payment'));
          handlers.onFailed?.();
          return;
        }

        this.openPaymentWebview(checkoutUrl, orderReference, authToken, handlers);
      },
      error: (err: any) => {
        const msg = err?.error?.error?.message ?? err?.error?.message ?? err?.message;
        this.notify.error(msg || this.i18n.t('cust_error_start_payment'));
        handlers.onFailed?.();
      },
    });
  }

  private openPaymentWebview(
    url: string,
    orderReference: string,
    authToken: string,
    handlers: { onPaid: () => void; onFailed?: () => void },
  ): void {
    let processed = false;
    let listenerHandle: any = null;
    const returnPrefix = this.v3ReturnPrefix;

    InAppBrowser.openWebView({ url, title: this.i18n.t('cust_webview_title') }).catch((err: any) => {
      console.error('openWebView failed', err);
      this.notify.error(this.i18n.t('cust_error_open_payment_page'));
    });

    InAppBrowser.addListener('urlChangeEvent', (info: any) => {
      try {
        if (processed) return;
        const urlStr: string = info?.url ?? '';

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
          this.pollPayment(orderReference, authToken, handlers);
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

  private pollPayment(
    orderReference: string,
    authToken: string,
    handlers: { onPaid: () => void; onFailed?: () => void },
    attempts = 0,
  ): void {
    if (attempts > 12) {
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
            this.notify.success(this.i18n.t('cust_paid'));
            handlers.onPaid();
          } else if (data?.status === 'failed' || data?.status === 'cancelled') {
            this.notify.error(this.i18n.t('cust_error_payment_incomplete'));
            (handlers.onFailed ?? handlers.onPaid)();
          } else {
            this.pollPayment(orderReference, authToken, handlers, attempts + 1);
          }
        },
        error: () => {
          this.pollPayment(orderReference, authToken, handlers, attempts + 1);
        },
      });
    }, 2500);
  }
}
