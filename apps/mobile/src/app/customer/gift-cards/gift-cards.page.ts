import { Component, OnInit } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import {
  IonButton, IonButtons, IonContent, IonHeader, IonTitle,
  IonToolbar, IonSpinner, NavController,
} from '@ionic/angular/standalone';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { apiErrorMessage } from '../../core/http/api-error';
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

/** A normalised contact row for the in-app fallback picker. */
export interface PickableContact {
  name: string;
  phone: string | null;
  email: string | null;
}

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
    IonSpinner,
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

  // True while the contacts picker is busy (disables the button).
  pickingContact = false;

  // In-app contacts picker (fallback when the native ACTION_PICK activity
  // result flow is unavailable/unreliable). Populated from Contacts.getContacts.
  contactsSheetOpen = false;
  contactsSearch = '';
  contactsList: PickableContact[] = [];

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
      error: (err: any) => {
        this.ui.loading_themes = false;
        this.notify.error(apiErrorMessage(err, this.i18n.t('gc_error_load_themes')));
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
    // Scheduled delivery, if set, must be in the future. Checked BEFORE the
    // recipient rules because scheduling is what makes a contact mandatory.
    if (this.form.scheduled_delivery_at) {
      const when = new Date(this.form.scheduled_delivery_at).getTime();
      if (isNaN(when) || when <= Date.now()) {
        this.notify.error(this.i18n.t('gc_error_schedule_future')); return;
      }
    }
    // Recipient email/phone are OPTIONAL — leave both blank and the buyer
    // simply shares the code themselves. They only become required when the
    // card is SCHEDULED for a later date (there'd be no one to deliver to).
    // Anything entered is still format-validated.
    if (!this.validateRecipient()) { return; }
    this.ui.step = 3;
  }

  goBack() {
    if (this.ui.step > 1) { this.ui.step = (this.ui.step - 1) as 1 | 2 | 3; }
    else { this.navCtrl.back(); }
  }

  // ── Recipient validation (email + phone compulsory) ────────────────

  /** True when the card is queued for a future delivery date. */
  get isScheduled(): boolean {
    return !!this.form.scheduled_delivery_at;
  }

  /**
   * True once the buyer has named/contacted a recipient — which makes the card
   * a GIFT (spendable by that recipient once they redeem it), not a top-up the
   * buyer can spend from their own balance. Drives the hint under the
   * recipient fields so the rule isn't a surprise at checkout.
   */
  get hasRecipientDetails(): boolean {
    return !!(
      (this.form.recipient_name || '').trim() ||
      (this.form.recipient_email || '').trim() ||
      (this.form.recipient_phone || '').replace(/\D/g, '')
    );
  }

  /**
   * Validate the OPTIONAL recipient email + phone.
   *
   * Both are optional by default — a buyer who leaves them blank just shares
   * the code themselves. Whatever IS entered must be well-formed. When the
   * card is SCHEDULED for a later date, at least one contact becomes required
   * (mirrors the server rule in PurchaseGiftCardController): without one there
   * is nobody to deliver to when the date arrives.
   *
   * Sets inline `errors.*` strings; returns true when the form may advance.
   */
  validateRecipient(): boolean {
    this.errors.recipient_email = '';
    this.errors.recipient_phone = '';

    const email  = (this.form.recipient_email || '').trim();
    const digits = (this.form.recipient_phone || '').replace(/\D/g, '');

    // Format checks apply only to values that were actually provided.
    if (email && !GlobalComponent.validateEmail(email)) {
      this.errors.recipient_email = this.i18n.t('gift_card_recipient_email_invalid');
    }
    if (digits.length > 0 && (digits.length < 6 || digits.length > 15)) {
      this.errors.recipient_phone = this.i18n.t('gift_card_recipient_phone_invalid');
    }

    // Scheduling requires at least one delivery channel.
    if (this.isScheduled && !email && digits.length === 0) {
      this.errors.recipient_email = this.i18n.t('gift_card_recipient_required_when_scheduled');
    }

    return !this.errors.recipient_email && !this.errors.recipient_phone;
  }

  /** Clear the email inline error as the user edits (re-validated on submit). */
  onRecipientEmailInput() { this.errors.recipient_email = ''; }
  /** Clear the phone inline error as the user edits. */
  onRecipientPhoneInput() { this.errors.recipient_phone = ''; }

  // ── Contacts picker ────────────────────────────────────────────────

  /**
   * Open the contacts picker and fill the recipient phone (+ name/email when
   * available).
   *
   * We render OUR OWN in-app picker: `Contacts.getContacts()` reads the address
   * book once (a direct ContentResolver/CNContactStore read — no Intent) and we
   * show it in a searchable ax-bottom-sheet; tapping a row fills the recipient
   * fields. This deliberately AVOIDS the native `pickContact()` ACTION_PICK
   * flow, which on Android pops a "Complete action with" app-chooser AND
   * resolves with `{ contacts: [...] }` (NOT `{ contact }`) — that shape
   * mismatch silently dropped the selection so the number never populated. The
   * in-app list opens straight to the contacts and fills reliably via our code.
   *
   * Manual entry remains the true last resort: if the plugin is missing (web
   * preview / before `npx cap sync`), unavailable, or permission is denied, we
   * show the existing toast and the user types the number.
   *
   * Build-safe: the plugin is imported lazily and every native call is guarded
   * so failures degrade gracefully instead of crashing the page.
   */
  async pickContact() {
    if (this.pickingContact) return;
    this.pickingContact = true;
    try {
      const { CapacitorContacts: Contacts } = await import('@capgo/capacitor-contacts');

      // Ensure read permission. Check first, only prompt when not yet granted,
      // and treat the iOS 'limited' state as usable.
      const granted = await this.ensureContactsPermission(Contacts);
      if (!granted) {
        this.notify.error(this.i18n.t('gc_contacts_permission_denied'));
        return;
      }

      // Open our in-app contact list directly — no native chooser, reliable fill.
      await this.openInAppContactsPicker(Contacts);
    } catch {
      // Plugin missing / not synced — genuine manual-entry fallback.
      this.notify.error(this.i18n.t('gc_contacts_pick_failed'));
    } finally {
      this.pickingContact = false;
    }
  }

  /**
   * Resolve contacts read permission. Returns true when usable ('granted' or
   * iOS 'limited'). Only prompts when the current state is not already granted.
   */
  private async ensureContactsPermission(Contacts: any): Promise<boolean> {
    const usable = (s: any) => s === 'granted' || s === 'limited';
    let status: any;
    try {
      status = await Contacts.checkPermissions();
    } catch {
      status = null;
    }
    if (usable(status?.readContacts)) return true;
    const req = await Contacts.requestPermissions();
    return usable(req?.readContacts);
  }

  /**
   * Read the address book and open the in-app picker sheet. Contacts are
   * normalised to {name, phone, email} rows (only those with a phone OR email
   * are kept — the rest can't be used as delivery targets).
   */
  private async openInAppContactsPicker(Contacts: any) {
    const res: any = await Contacts.getContacts({
      fields: ['fullName', 'givenName', 'familyName', 'phoneNumbers', 'emailAddresses'],
    });
    const raw: any[] = res?.contacts ?? [];

    this.contactsList = raw
      .map((c) => this.normaliseContact(this.fromCapgoContact(c)))
      .filter((c) => !!c.phone || !!c.email)
      .sort((a, b) => a.name.localeCompare(b.name));

    if (this.contactsList.length === 0) {
      this.notify.error(this.i18n.t('gc_contacts_pick_failed'));
      return;
    }

    this.contactsSearch = '';
    this.contactsSheetOpen = true;
  }

  /**
   * Map a @capgo/capacitor-contacts Contact onto the shape the rest of this
   * page already consumes ({ name:{display}, phones:[{number}], emails:[{address}] }).
   * Capgo's plugin (Capacitor-8 compatible — it replaces the Cap-7-only
   * @capacitor-community/contacts) exposes fullName + phoneNumbers[].value +
   * emailAddresses[].value instead.
   */
  private fromCapgoContact(contact: any): any {
    if (!contact) return contact;
    const display = contact.fullName
      ?? [contact.givenName, contact.familyName].filter(Boolean).join(' ');
    return {
      name: { display },
      phones: (contact.phoneNumbers ?? []).map((p: any) => ({ number: p?.value })),
      emails: (contact.emailAddresses ?? []).map((e: any) => ({ address: e?.value })),
    };
  }

  /** Normalise a raw plugin contact payload to a simple pickable row. */
  private normaliseContact(contact: any): PickableContact {
    const name = (contact?.name?.display
      ?? [contact?.name?.given, contact?.name?.family].filter(Boolean).join(' ')
      ?? '').trim();
    const phone = contact?.phones?.find((p: any) => p?.number)?.number ?? null;
    const email = contact?.emails?.find((e: any) => e?.address)?.address ?? null;
    return { name: name || (phone || email || ''), phone, email };
  }

  /** Filtered contacts for the in-app picker search box. */
  filteredContacts(): PickableContact[] {
    const q = this.contactsSearch.trim().toLowerCase();
    if (!q) return this.contactsList;
    return this.contactsList.filter((c) =>
      c.name.toLowerCase().includes(q)
      || (c.phone || '').includes(q)
      || (c.email || '').toLowerCase().includes(q)
    );
  }

  /** Choose a contact from the in-app picker sheet, then close it. */
  selectContact(c: PickableContact) {
    this.applyPickedContact({
      name: { display: c.name },
      phones: c.phone ? [{ number: c.phone }] : [],
      emails: c.email ? [{ address: c.email }] : [],
    });
    this.contactsSheetOpen = false;
    this.contactsSearch = '';
  }

  /**
   * Apply a picked contact (native or in-app shape) onto the form. Only fills
   * fields the user has left empty so we never clobber manual input.
   */
  private applyPickedContact(contact: any) {
    const display = contact?.name?.display
      ?? [contact?.name?.given, contact?.name?.family].filter(Boolean).join(' ');
    if (display && !this.form.recipient_name) {
      this.form.recipient_name = display.slice(0, 60);
    }

    const email = contact?.emails?.find((e: any) => e?.address)?.address;
    if (email && !this.form.recipient_email) {
      this.form.recipient_email = email;
      this.errors.recipient_email = '';
    }

    const rawPhone = contact?.phones?.find((p: any) => p?.number)?.number;
    if (rawPhone) {
      this.applyPickedPhone(rawPhone);
      this.errors.recipient_phone = '';
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
        // Dedicated gift-card confirmation (shows the funded card), not the
        // product success screen and not a silent drop into the wallet.
        this.router.navigate(['/gift-card-success'], {
          replaceUrl: true,
          queryParams: { cardId: giftCardId },
        });
      },
      onFailed: () => {
        this.ui_checking_out = false;
        this.router.navigate(['/gift-cards']);
      },
      onGatewaySkipped: () => {
        this.ui_checking_out = false;
        this.router.navigate(['/gift-card-success'], {
          replaceUrl: true,
          queryParams: { cardId: giftCardId },
        });
      },
    });
  }
}
