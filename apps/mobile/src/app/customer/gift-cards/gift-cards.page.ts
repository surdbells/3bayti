import { Component, OnInit } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import {
  IonButton, IonButtons, IonContent, IonHeader, IonTitle,
  IonToolbar, IonSpinner, IonIcon, NavController,
} from '@ionic/angular/standalone';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { Preferences } from '@capacitor/preferences';
import { InAppBrowser } from '@capgo/inappbrowser';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { TranslatePipe } from '../../translate.pipe';
import { GlobalComponent } from '../../global-component';

export interface GiftCardTheme {
  label: string;
  primary_color: string;
  accent_color: string;
  supports_photo: boolean;
  arabic_label: string;
  presets: string[];
  min_denomination: string;
  max_denomination: string;
}

@Component({
  selector: 'app-gift-cards',
  templateUrl: './gift-cards.page.html',
  styleUrls: ['./gift-cards.page.scss'],
  standalone: true,
  imports: [
    CommonModule, FormsModule, TranslatePipe,
    IonHeader, IonToolbar, IonTitle, IonButtons, IonButton, IonContent,
    IonSpinner, IonIcon,
    AxLoaderComponent, AxIconComponent, AxTextFieldComponent,
  ],
})
export class GiftCardsPage implements OnInit {

  // ── State ──────────────────────────────────────────────────────────

  themes: Record<string, GiftCardTheme> = {};
  themeKeys: string[] = [];

  ui = {
    loading_themes: true,
    purchasing: false,
    step: 1 as 1 | 2 | 3,   // 1=pick theme, 2=personalise, 3=confirm
    show_custom_amount: false,
  };

  // Purchase form
  form = {
    theme: '',
    denomination: '',
    custom_amount: '',
    recipient_name: '',
    recipient_message: '',
    recipient_photo_url: null as string | null,
    scheduled_delivery_at: null as string | null,
  };

  // Preset denomination options (filtered for the selected theme)
  get presets(): string[] {
    return this.themes[this.form.theme]?.presets ?? [];
  }

  get selectedTheme(): GiftCardTheme | null {
    return this.themes[this.form.theme] ?? null;
  }

  get effectiveDenomination(): string {
    return this.ui.show_custom_amount ? this.form.custom_amount : this.form.denomination;
  }

  get isPhotoTheme(): boolean {
    return this.selectedTheme?.supports_photo === true;
  }

  private v3BaseUrl = 'https://api-v3.3bayti.ae';
  private readonly v3ReturnPrefix = 'https://api-v3.3bayti.ae/v3/checkout/return/';

  // Payment state — set after checkout/initiate succeeds
  paymentState: { orderReference: string; giftCardId: number } | null = null;
  ui_checking_out = false;

  // Auth token for authed gift-card calls (purchase / checkout / status /
  // photo upload). Loaded from the stored user in Preferences.
  private authToken = '';

  constructor(
    private router: Router,
    private navCtrl: NavController,
    private network: MobileNetworkAdapter,
    private notify: AxNotificationService,
  ) {}

  ngOnInit() {
    this.loadAuthToken();
    this.loadThemes();
  }

  private async loadAuthToken() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret?.value) {
      try { this.authToken = JSON.parse(ret.value)?.token ?? ''; } catch { /* ignore */ }
    }
  }

  loadThemes() {
    this.ui.loading_themes = true;
    // PUBLIC endpoint — no authToken. res.data is a Record<theme, themeMeta>.
    this.network.get_v3('GET /gift-cards/themes').subscribe({
      next: (res: any) => {
        this.ui.loading_themes = false;
        if (res?.data) {
          this.themes = res.data;
          this.themeKeys = Object.keys(res.data);
        }
      },
      error: () => {
        this.ui.loading_themes = false;
        this.notify.error('Failed to load themes. Please try again.');
      },
    });
  }

  // ── Step navigation ────────────────────────────────────────────────

  selectTheme(key: string) {
    this.form.theme = key;
    this.form.denomination = '';
    this.form.custom_amount = '';
    this.ui.show_custom_amount = false;
    this.ui.step = 2;
  }

  selectDenomination(preset: string) {
    this.form.denomination = preset;
    this.ui.show_custom_amount = false;
  }

  toggleCustomAmount() {
    this.ui.show_custom_amount = !this.ui.show_custom_amount;
    if (this.ui.show_custom_amount) this.form.denomination = '';
  }

  nextToConfirm() {
    const amount = this.effectiveDenomination;
    if (!amount || isNaN(Number(amount))) {
      this.notify.error('Please select or enter an amount.'); return;
    }
    const n = Number(amount);
    if (n < 100 || n > 10000) {
      this.notify.error('Amount must be between AED 100 and AED 10,000.'); return;
    }
    // Validation parity with web: recipient_name max 60, message max 200.
    if ((this.form.recipient_name || '').length > 60) {
      this.notify.error('Recipient name must be 60 characters or fewer.'); return;
    }
    if ((this.form.recipient_message || '').length > 200) {
      this.notify.error('Personal message must be 200 characters or fewer.'); return;
    }
    // Scheduled delivery, if set, must be in the future.
    if (this.form.scheduled_delivery_at) {
      const when = new Date(this.form.scheduled_delivery_at).getTime();
      if (isNaN(when) || when <= Date.now()) {
        this.notify.error('Scheduled delivery date must be in the future.'); return;
      }
    }
    this.ui.step = 3;
  }

  goBack() {
    if (this.ui.step > 1) { this.ui.step = (this.ui.step - 1) as 1 | 2 | 3; }
    else { this.navCtrl.back(); }
  }

  // ── Photo upload (luxury theme only) ──────────────────────────────

  async onPhotoSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    const token = this.authToken;
    const form = new FormData();
    form.append('image', file, file.name);

    // Simple fetch for the upload since we don't have HttpClient injected here.
    // Authed endpoint — set Authorization: Bearer <token>.
    try {
      const resp = await fetch(`${this.v3BaseUrl}/v3/gift-cards/photo`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
        body: form,
      });
      const data = await resp.json();
      if (data?.data?.url) {
        this.form.recipient_photo_url = data.data.url;
        this.notify.success('Photo uploaded!');
      }
    } catch {
      this.notify.error('Photo upload failed. Please try again.');
    }
  }

  removePhoto() {
    this.form.recipient_photo_url = null;
  }

  // ── Submit ─────────────────────────────────────────────────────────

  purchaseCard() {
    if (this.ui.purchasing) return;
    this.ui.purchasing = true;

    const amount = Number(this.effectiveDenomination);
    const denominationStr = amount.toFixed(2);

    const payload: any = {
      denomination: denominationStr,
      theme: this.form.theme,
      recipient_name: (this.form.recipient_name || '').trim() || null,
      recipient_message: (this.form.recipient_message || '').trim() || null,
      recipient_photo_url: this.form.recipient_photo_url,
      scheduled_delivery_at: this.form.scheduled_delivery_at,
    };

    // Step 1: Create the pending_payment gift card
    this.network.post_v3('POST /gift-cards/purchase', payload, { authToken: this.authToken }).subscribe({
      next: (res: any) => {
        this.ui.purchasing = false;
        if (res?.data?.id) {
          this.initiateGiftCardPayment(res.data.id);
        } else {
          this.notify.error(res?.message ?? 'Could not create gift card. Please try again.');
        }
      },
      error: (err: any) => {
        this.ui.purchasing = false;
        this.notify.error(err?.error?.message ?? 'Purchase failed. Please try again.');
      },
    });
  }

  // Step 2: Initiate Noon payment for the gift card
  private initiateGiftCardPayment(giftCardId: number) {
    this.ui_checking_out = true;

    const initPayload = {
      gift_card_purchase_id: giftCardId,
      channel: 'MOBILE',
    };

    this.network.post_v3('POST /checkout/initiate', initPayload, { authToken: this.authToken }).subscribe({
      next: (res: any) => {
        this.ui_checking_out = false;

        // Gateway skipped (card denomination = 0 — shouldn't happen but handle it)
        if (res?.data?.gateway_skipped === true) {
          this.notify.success('Gift card activated!');
          this.router.navigate(['/my-gift-cards']);
          return;
        }

        // transformInitiatePaymentResponse maps checkout_url -> url.
        const checkoutUrl = res?.data?.url ?? res?.data?.checkout_url;
        const orderReference = res?.data?.order_reference ?? '';

        if (!checkoutUrl || !orderReference) {
          this.notify.error('Could not start payment. Please try again.');
          return;
        }

        this.paymentState = { orderReference, giftCardId };
        this.openPaymentWebview(checkoutUrl, orderReference, giftCardId);
      },
      error: (err: any) => {
        this.ui_checking_out = false;
        this.notify.error(err?.error?.message ?? 'Could not start payment. Please try again.');
      },
    });
  }

  // Step 3: Open Noon webview and listen for return URL
  private openPaymentWebview(url: string, orderReference: string, giftCardId: number) {
    let processed = false;
    let listenerHandle: any = null;
    const returnPrefix = this.v3ReturnPrefix;

    InAppBrowser.openWebView({ url, title: 'Pay for Gift Card' }).catch((err: any) => {
      console.error('openWebView failed', err);
      this.notify.error('Could not open payment page. Please try again.');
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
          this.pollGiftCardPayment(orderReference, giftCardId);
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

  // Step 4: Poll checkout status until paid/failed, then navigate
  private pollGiftCardPayment(orderReference: string, giftCardId: number, attempts = 0) {
    if (attempts > 12) { // 12 × 2.5s = 30s timeout
      this.notify.error('Payment confirmation timed out. Check My Gift Cards to see your card status.');
      this.router.navigate(['/my-gift-cards']);
      return;
    }

    setTimeout(() => {
      this.network.get_v3('GET /checkout/status/:order_reference', {
        authToken: this.authToken,
        pathParams: { order_reference: orderReference },
      }).subscribe({
        next: (res: any) => {
          const data = res?.data ?? res;
          if (data?.paid === true || data?.status === 'paid') {
            this.notify.success('Gift card activated! 🎁');
            this.router.navigate(['/my-gift-cards']);
          } else if (data?.status === 'failed' || data?.status === 'cancelled') {
            this.notify.error('Payment was not completed. Your gift card has not been charged.');
            this.router.navigate(['/gift-cards']);
          } else {
            // Not yet terminal — poll again
            this.pollGiftCardPayment(orderReference, giftCardId, attempts + 1);
          }
        },
        error: () => {
          // Network error — retry
          this.pollGiftCardPayment(orderReference, giftCardId, attempts + 1);
        },
      });
    }, 2500);
  }
}
