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
  dialSearch = '';

  // Inline validation errors for the compulsory recipient fields (step 2).
  // Empty string = no error. Shown under the field + block "Continue".
  errors = {
    recipient_email: '',
    recipient_phone: '',
  };

  // True while the native contacts picker is open (disables the button).
  pickingContact = false;

  get selectedDial(): DialCode | undefined {
    return this.dialCodes.find(c => c.code === this.dialCode);
  }

  filteredDialCodes(): DialCode[] {
    const q = this.dialSearch.trim().toLowerCase();
    if (!q) return this.dialCodes;
    return this.dialCodes.filter(c =>
      c.name.toLowerCase().includes(q) || c.code.includes(q)
    );
  }

  selectDial(c: DialCode) {
    this.dialCode = c.code;
    this.dialSearch = '';
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
    // Recipient email AND phone are now COMPULSORY: both are required so the
    // gift card is always auto-delivered. Validate format + show inline errors
    // that block submit.
    if (!this.validateRecipient()) { return; }
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

  // ── Recipient validation (email + phone compulsory) ────────────────

  /**
   * Validate the now-compulsory recipient email + phone. Sets inline
   * `errors.*` strings and returns true only when BOTH are valid. Called by
   * nextToConfirm() to block advancing to confirm.
   */
  validateRecipient(): boolean {
    this.errors.recipient_email = '';
    this.errors.recipient_phone = '';

    const email = (this.form.recipient_email || '').trim();
    if (!email) {
      this.errors.recipient_email = this.i18n.t('gift_card_recipient_email_required');
    } else if (!GlobalComponent.validateEmail(email)) {
      this.errors.recipient_email = this.i18n.t('gift_card_recipient_email_invalid');
    }

    const digits = (this.form.recipient_phone || '').replace(/\D/g, '');
    if (digits.length === 0) {
      this.errors.recipient_phone = this.i18n.t('gift_card_recipient_phone_required');
    } else if (digits.length < 6 || digits.length > 15) {
      this.errors.recipient_phone = this.i18n.t('gift_card_recipient_phone_invalid');
    }

    return !this.errors.recipient_email && !this.errors.recipient_phone;
  }

  /** Clear the email inline error as the user edits (re-validated on submit). */
  onRecipientEmailInput() { this.errors.recipient_email = ''; }
  /** Clear the phone inline error as the user edits. */
  onRecipientPhoneInput() { this.errors.recipient_phone = ''; }

  // ── Contacts picker ────────────────────────────────────────────────

  /**
   * Open the native contacts picker (Capacitor Contacts plugin) and fill the
   * recipient phone — plus name/email when the picked contact has them.
   *
   * Build-safe: the plugin is imported lazily and the whole call is wrapped in
   * try/catch so a missing native implementation (e.g. web preview, or before
   * `npx cap sync`) degrades gracefully instead of crashing the page.
   */
  async pickContact() {
    if (this.pickingContact) return;
    this.pickingContact = true;
    try {
      const { Contacts } = await import('@capacitor-community/contacts');

      // Request read permission first; bail with a toast if denied.
      const perm = await Contacts.requestPermissions();
      if (perm?.contacts !== 'granted') {
        this.notify.error(this.i18n.t('gc_contacts_permission_denied'));
        return;
      }

      const result: any = await Contacts.pickContact({
        projection: { name: true, phones: true, emails: true },
      });
      const contact = result?.contact;
      if (!contact) return; // user cancelled

      // Name (fill only when our field is empty so we don't clobber input).
      const display = contact.name?.display
        ?? [contact.name?.given, contact.name?.family].filter(Boolean).join(' ');
      if (display && !this.form.recipient_name) {
        this.form.recipient_name = display.slice(0, 60);
      }

      // Email (first available) — only when empty.
      const email = contact.emails?.find((e: any) => e?.address)?.address;
      if (email && !this.form.recipient_email) {
        this.form.recipient_email = email;
        this.errors.recipient_email = '';
      }

      // Phone: take the first number, split dial code from national digits.
      const rawPhone = contact.phones?.find((p: any) => p?.number)?.number;
      if (rawPhone) {
        this.applyPickedPhone(rawPhone);
        this.errors.recipient_phone = '';
      }
    } catch (err) {
      // Plugin missing / native error / user dismissed abnormally.
      this.notify.error(this.i18n.t('gc_contacts_pick_failed'));
    } finally {
      this.pickingContact = false;
    }
  }

  /**
   * Map a raw contact phone string onto our dial-code selector + national
   * digit field. If it starts with a '+' and matches a known dial code, we set
   * that code and keep the remaining digits; otherwise we keep the current
   * dial code and just fill the digits.
   */
  private applyPickedPhone(raw: string) {
    const trimmed = raw.trim();
    if (trimmed.startsWith('+')) {
      const digits = trimmed.replace(/[^\d]/g, '');
      // Longest matching dial code wins (e.g. +971 over +9).
      const match = this.dialCodes
        .filter(c => digits.startsWith(c.code.replace('+', '')))
        .sort((a, b) => b.code.length - a.code.length)[0];
      if (match) {
        this.dialCode = match.code;
        this.form.recipient_phone = digits.slice(match.code.replace('+', '').length);
        return;
      }
    }
    // Fallback: keep current dial code, strip non-digits.
    this.form.recipient_phone = trimmed.replace(/\D/g, '');
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
