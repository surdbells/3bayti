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
import { GiftCardPaymentService } from './gift-card-payment.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { GlobalComponent } from '../../global-component';
import { DIAL_CODES, DialCode } from '../../public/shared/dial-codes';

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
    AxBottomSheetComponent,
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
    recipient_email: '',
    recipient_phone: '',
    recipient_photo_url: null as string | null,
    scheduled_delivery_at: null as string | null,
  };

  // Dial-code picker (reuses the shared list; default UAE +971).
  readonly dialCodes: DialCode[] = DIAL_CODES;
  dialCodeOpen = false;
  dialCode = '+971';

  get selectedDial(): DialCode | undefined {
    return this.dialCodes.find(c => c.code === this.dialCode);
  }

  selectDial(c: DialCode) {
    this.dialCode = c.code;
    this.dialCodeOpen = false;
  }

  /**
   * Full international recipient phone: dial code + national digits, digits
   * only after the '+'. Empty string when no national digits entered (so the
   * field stays optional / backward-compatible).
   */
  get recipientPhoneFull(): string {
    const national = (this.form.recipient_phone || '').replace(/\D/g, '');
    if (national.length === 0) return '';
    return `${this.dialCode}${national}`;
  }

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

  ui_checking_out = false;

  // Auth token for authed gift-card calls (purchase / checkout / status /
  // photo upload). Loaded from the stored user in Preferences.
  private authToken = '';

  constructor(
    private router: Router,
    private navCtrl: NavController,
    private network: MobileNetworkAdapter,
    private notify: AxNotificationService,
    private i18n: I18nService,
    private giftCardPayment: GiftCardPaymentService,
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
        this.notify.error(this.i18n.t('gc_error_load_themes'));
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
      this.notify.error(this.i18n.t('gc_error_select_amount')); return;
    }
    const n = Number(amount);
    if (n < 100 || n > 10000) {
      this.notify.error(this.i18n.t('gc_error_amount_range')); return;
    }
    // Validation parity with web: recipient_name max 60, message max 200.
    if ((this.form.recipient_name || '').length > 60) {
      this.notify.error(this.i18n.t('gc_error_name_too_long')); return;
    }
    if ((this.form.recipient_message || '').length > 200) {
      this.notify.error(this.i18n.t('gc_error_message_too_long')); return;
    }
    // Recipient email/phone are OPTIONAL: empty = valid (buyer shares the code
    // manually). When provided, validate format so auto-delivery succeeds.
    const recipientEmail = (this.form.recipient_email || '').trim();
    if (recipientEmail && !GlobalComponent.validateEmail(recipientEmail)) {
      this.notify.error(this.i18n.t('gift_card_recipient_email_invalid')); return;
    }
    const recipientPhoneDigits = (this.form.recipient_phone || '').replace(/\D/g, '');
    if (recipientPhoneDigits.length > 0 && (recipientPhoneDigits.length < 6 || recipientPhoneDigits.length > 15)) {
      this.notify.error(this.i18n.t('gift_card_recipient_phone_invalid')); return;
    }
    // Scheduled delivery, if set, must be in the future.
    if (this.form.scheduled_delivery_at) {
      const when = new Date(this.form.scheduled_delivery_at).getTime();
      if (isNaN(when) || when <= Date.now()) {
        this.notify.error(this.i18n.t('gc_error_schedule_future')); return;
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
        this.notify.success(this.i18n.t('gc_photo_uploaded'));
      }
    } catch {
      this.notify.error(this.i18n.t('gc_error_photo_upload'));
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
      // Optional auto-delivery targets. Send null (not '') when blank so the
      // backend treats them as absent and nothing is auto-sent.
      recipient_email: (this.form.recipient_email || '').trim() || null,
      recipient_phone: this.recipientPhoneFull || null,
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
          this.notify.error(res?.message ?? this.i18n.t('gc_error_create_card'));
        }
      },
      error: (err: any) => {
        this.ui.purchasing = false;
        this.notify.error(err?.error?.message ?? this.i18n.t('gc_error_purchase_failed'));
      },
    });
  }

  // Step 2+: Initiate Noon payment, open the webview and poll for the result.
  // Delegated to the shared GiftCardPaymentService (reused by my-gift-cards).
  private initiateGiftCardPayment(giftCardId: number) {
    this.ui_checking_out = true;
    this.giftCardPayment.pay(giftCardId, this.authToken, {
      onPaid: () => {
        this.ui_checking_out = false;
        this.router.navigate(['/my-gift-cards']);
      },
      onFailed: () => {
        this.ui_checking_out = false;
        this.router.navigate(['/gift-cards']);
      },
      onGatewaySkipped: () => {
        this.ui_checking_out = false;
        this.router.navigate(['/my-gift-cards']);
      },
    });
  }
}
