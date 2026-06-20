import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
  PLATFORM_ID,
} from '@angular/core';
import { isPlatformBrowser, DecimalPipe } from '@angular/common';
import { FormsModule, Validators } from '@angular/forms';
import type { AbstractControl } from '@angular/forms';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { TranslatePipe } from '@ngx-translate/core';

import { PhoneInputComponent, parseE164 } from '../../shared/forms';
import { GiftCardVisualComponent } from './gift-card-visual';
import { GiftCardService } from './gift-card.service';
import { CheckoutService } from '../../core/checkout/checkout.service';
import { SeoService } from '../../core/seo/seo.service';
import { markGiftCardCheckout } from './gift-card-checkout-handoff';
import {
  GIFT_CARD_MIN_DENOMINATION,
  GIFT_CARD_MAX_DENOMINATION,
} from './gift-card.model';
import type {
  GiftCardTheme,
  GiftCardThemeOption,
  GiftCardPurchaseInput,
} from './gift-card.model';

type LoadState = 'loading' | 'ready' | 'error';

/**
 * /gift-cards — browse the themed gift-card designs and buy one.
 *
 * Purchase flow (Phase E2 — synthetic checkout, bypasses the cart):
 *   1. POST /v3/gift-cards/purchase  → a pending_payment card {id}
 *   2. POST /v3/checkout/initiate { gift_card_purchase_id } → Noon URL
 *   3. record the order_reference (markGiftCardCheckout) and redirect to
 *      the Noon hosted page
 *   4. Noon webhook activates the card + the shared /checkout/return page
 *      sees paid=true and routes the buyer to /account/gift-cards
 *
 * Auth: purchasing requires a signed-in buyer (the API returns 401
 * otherwise) — we catch that and send them to /login with a returnUrl.
 *
 * Auto-delivery: the buyer may add a recipient email and/or phone. When
 * either is present the API delivers the card automatically (email via
 * ZeptoMail, SMS via MessageCentral); when both are blank, nothing is
 * sent and the buyer shares the code themselves.
 */
@Component({
  selector: 'app-gift-card-landing',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    FormsModule,
    TranslatePipe,
    DecimalPipe,
    GiftCardVisualComponent,
    PhoneInputComponent,
  ],
  template: `
    <section class="gc">
      <header class="gc__header">
        <p class="gc__eyebrow">{{ 'giftCards.landing.eyebrow' | translate }}</p>
        <h1 class="gc__title">{{ 'giftCards.landing.title' | translate }}</h1>
        <p class="gc__subtitle">{{ 'giftCards.landing.subtitle' | translate }}</p>
      </header>

      @switch (loadState()) {
        @case ('loading') {
          <div class="gc__layout" aria-hidden="true">
            <div class="gc__preview"><div class="gc__skeleton-card"></div></div>
            <div class="gc__form">
              <div class="gc__skeleton-row"></div>
              <div class="gc__skeleton-row"></div>
              <div class="gc__skeleton-row gc__skeleton-row--short"></div>
            </div>
          </div>
        }

        @case ('error') {
          <div class="gc__state gc__state--error" role="alert">
            <p>{{ 'giftCards.landing.errorLoad' | translate }}</p>
            <button type="button" class="gc__retry" (click)="reload()">
              {{ 'giftCards.landing.retry' | translate }}
            </button>
          </div>
        }

        @case ('ready') {
          <div class="gc__layout">
            <!-- Live preview -->
            <div class="gc__preview">
              <ui-gift-card
                [theme]="theme()"
                [themeMeta]="selected()"
                [amount]="amountNumber()"
                [recipientName]="recipientName()"
                [recipientMessage]="recipientMessage()"
                [photoUrl]="recipientPhotoUrl()"
              />
            </div>

            <!-- Purchase form -->
            <form class="gc__form" (ngSubmit)="submit()" novalidate>
              <!-- Theme -->
              <fieldset class="gc__field">
                <legend class="gc__label">{{ 'giftCards.landing.chooseTheme' | translate }}</legend>
                <div class="gc__themes">
                  @for (t of themes(); track t.theme) {
                    <button
                      type="button"
                      class="gc__theme"
                      [class.gc__theme--active]="t.theme === theme()"
                      [style.--gc-swatch]="t.accent_color"
                      [style.--gc-swatch-bg]="t.primary_color"
                      [attr.aria-pressed]="t.theme === theme()"
                      (click)="selectTheme(t.theme)"
                    >
                      <span class="gc__theme-dot"></span>
                      <span class="gc__theme-name">{{ t.label }}</span>
                    </button>
                  }
                </div>
              </fieldset>

              <!-- Amount -->
              <fieldset class="gc__field">
                <legend class="gc__label">{{ 'giftCards.landing.chooseAmount' | translate }}</legend>
                <div class="gc__presets">
                  @for (p of presets(); track p) {
                    <button
                      type="button"
                      class="gc__preset"
                      [class.gc__preset--active]="!customMode() && amountNumber() === presetValue(p)"
                      (click)="selectPreset(p)"
                    >
                      AED {{ presetValue(p) | number }}
                    </button>
                  }
                  <button
                    type="button"
                    class="gc__preset gc__preset--custom"
                    [class.gc__preset--active]="customMode()"
                    (click)="enableCustom()"
                  >
                    {{ 'giftCards.landing.customAmount' | translate }}
                  </button>
                </div>

                @if (customMode()) {
                  <div class="gc__custom">
                    <span class="gc__custom-prefix">AED</span>
                    <input
                      type="text"
                      inputmode="numeric"
                      autocomplete="off"
                      class="gc__custom-input"
                      name="customAmount"
                      [ngModel]="denomination()"
                      (ngModelChange)="onCustomInput($event)"
                      [attr.aria-label]="'giftCards.landing.customAmount' | translate"
                    />
                  </div>
                }

                <p
                  class="gc__hint"
                  [class.gc__hint--error]="denomination() !== '' && !amountValid()"
                >
                  {{ 'giftCards.landing.amountHint' | translate }}
                </p>
              </fieldset>

              <!-- Recipient name -->
              <div class="gc__field">
                <label class="gc__label" for="gc-recipient">
                  {{ 'giftCards.landing.recipientName' | translate }}
                </label>
                <input
                  id="gc-recipient"
                  type="text"
                  class="gc__input"
                  maxlength="60"
                  name="recipientName"
                  [ngModel]="recipientName()"
                  (ngModelChange)="recipientName.set($event)"
                  [attr.placeholder]="'giftCards.landing.recipientNamePlaceholder' | translate"
                />
              </div>

              <!-- Message -->
              <div class="gc__field">
                <label class="gc__label" for="gc-message">
                  {{ 'giftCards.landing.recipientMessage' | translate }}
                </label>
                <textarea
                  id="gc-message"
                  class="gc__textarea"
                  rows="3"
                  maxlength="200"
                  name="recipientMessage"
                  [ngModel]="recipientMessage()"
                  (ngModelChange)="recipientMessage.set($event)"
                  [attr.placeholder]="'giftCards.landing.recipientMessagePlaceholder' | translate"
                ></textarea>
              </div>

              <!-- Auto-delivery (optional email + phone) -->
              <div class="gc__field">
                <label class="gc__label" for="gc-recipient-email">
                  {{ 'giftCards.landing.recipientEmail' | translate }}
                </label>
                <input
                  id="gc-recipient-email"
                  type="email"
                  inputmode="email"
                  autocomplete="off"
                  class="gc__input"
                  maxlength="120"
                  name="recipientEmail"
                  [class.gc__input--error]="!recipientEmailValid()"
                  [ngModel]="recipientEmail()"
                  (ngModelChange)="recipientEmail.set($event)"
                  [attr.aria-invalid]="!recipientEmailValid()"
                  [attr.placeholder]="'giftCards.landing.recipientEmailPlaceholder' | translate"
                />
                @if (!recipientEmailValid()) {
                  <p class="gc__hint gc__hint--error" role="alert">
                    {{ 'giftCards.landing.recipientEmailInvalid' | translate }}
                  </p>
                }
              </div>

              <div class="gc__field">
                <label class="gc__label" for="gc-recipient-phone">
                  {{ 'giftCards.landing.recipientPhone' | translate }}
                </label>
                <ui-phone-input
                  inputId="gc-recipient-phone"
                  name="recipientPhone"
                  [nationalAriaLabel]="'giftCards.landing.recipientPhone' | translate"
                  [ngModel]="recipientPhone()"
                  (ngModelChange)="onPhoneChange($event)"
                />
                @if (!recipientPhoneValid()) {
                  <p class="gc__hint gc__hint--error" role="alert">
                    {{ 'giftCards.landing.recipientPhoneInvalid' | translate }}
                  </p>
                }
                <p class="gc__hint">{{ 'giftCards.landing.autoDeliverHint' | translate }}</p>
              </div>

              <!-- Delivery timing -->
              <div class="gc__field">
                <span class="gc__label">{{ 'giftCards.landing.deliveryWhen' | translate }}</span>
                <div class="gc__toggle" role="group">
                  <button
                    type="button"
                    class="gc__toggle-btn"
                    [class.gc__toggle-btn--active]="!scheduleEnabled()"
                    [attr.aria-pressed]="!scheduleEnabled()"
                    (click)="setSchedule(false)"
                  >
                    {{ 'giftCards.landing.deliveryNow' | translate }}
                  </button>
                  <button
                    type="button"
                    class="gc__toggle-btn"
                    [class.gc__toggle-btn--active]="scheduleEnabled()"
                    [attr.aria-pressed]="scheduleEnabled()"
                    (click)="setSchedule(true)"
                  >
                    {{ 'giftCards.landing.deliverySchedule' | translate }}
                  </button>
                </div>
                @if (scheduleEnabled()) {
                  <input
                    type="datetime-local"
                    class="gc__input gc__input--date"
                    name="scheduledAt"
                    [min]="minScheduleValue()"
                    [ngModel]="scheduledAt()"
                    (ngModelChange)="scheduledAt.set($event)"
                  />
                }
              </div>

              @if (selected()?.supports_photo) {
                <div class="gc__field gc__photo-field">
                  <span class="gc__label">{{ 'giftCards.landing.photoLabel' | translate }}</span>
                  <p class="gc__photo-hint">{{ 'giftCards.landing.photoHint' | translate }}</p>

                  @if (recipientPhotoUrl()) {
                    <div class="gc__photo-preview">
                      <img class="gc__photo-thumb" [src]="recipientPhotoUrl()" alt="" />
                      <button type="button" class="gc__photo-remove" (click)="removePhoto()">
                        {{ 'giftCards.landing.photoRemove' | translate }}
                      </button>
                    </div>
                  } @else {
                    <label
                      class="gc__photo-upload"
                      [class.gc__photo-upload--busy]="photoUploading()"
                    >
                      <input
                        type="file"
                        class="gc__photo-input"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        [disabled]="photoUploading()"
                        (change)="onPhotoSelected($event)"
                      />
                      <span class="gc__photo-upload-label">
                        @if (photoUploading()) {
                          {{ 'giftCards.landing.photoUploading' | translate }}
                        } @else {
                          {{ 'giftCards.landing.photoChoose' | translate }}
                        }
                      </span>
                    </label>
                  }

                  @if (photoError(); as err) {
                    <p class="gc__photo-error" role="alert">
                      {{ ('giftCards.landing.photoError.' + err) | translate }}
                    </p>
                  }
                </div>
              }

              @if (submitError()) {
                <p class="gc__submit-error" role="alert">
                  {{ 'giftCards.landing.errorSubmit' | translate }}
                </p>
              }

              <button type="submit" class="gc__submit" [disabled]="!canSubmit()">
                @if (submitting()) {
                  {{ 'giftCards.landing.submitting' | translate }}
                } @else {
                  {{ 'giftCards.landing.buy' | translate }}
                  @if (amountValid()) {
                    <span class="gc__submit-amount">· AED {{ amountNumber() | number }}</span>
                  }
                }
              </button>
              <p class="gc__reassure">{{ 'giftCards.landing.reassure' | translate }}</p>
            </form>
          </div>
        }
      }
    </section>
  `,
  styleUrl: './gift-card-landing-page.scss',
})
export class GiftCardLandingPageComponent implements OnInit {
  private readonly gift = inject(GiftCardService);
  private readonly checkout = inject(CheckoutService);
  private readonly seo = inject(SeoService);
  private readonly router = inject(Router);
  private readonly platformId = inject(PLATFORM_ID);

  protected readonly loadState = signal<LoadState>('loading');
  protected readonly themes = signal<GiftCardThemeOption[]>([]);

  /* Form state */
  protected readonly theme = signal<GiftCardTheme>('birthday');
  protected readonly denomination = signal<string>('500');
  protected readonly customMode = signal<boolean>(false);
  protected readonly recipientName = signal<string>('');
  protected readonly recipientMessage = signal<string>('');
  /** Optional auto-delivery targets. Empty = nothing sent (buyer shares code). */
  protected readonly recipientEmail = signal<string>('');
  /** Full E.164 from ui-phone-input ('' when empty), e.g. '+971501234567'. */
  protected readonly recipientPhone = signal<string>('');
  protected readonly scheduleEnabled = signal<boolean>(false);
  protected readonly scheduledAt = signal<string>('');

  protected readonly submitting = signal<boolean>(false);
  protected readonly submitError = signal<boolean>(false);

  /* Luxury recipient photo state (only the luxury theme supports a photo). */
  protected readonly recipientPhotoUrl = signal<string | null>(null);
  protected readonly photoUploading = signal<boolean>(false);
  /** null | 'type' | 'size' | 'upload' — drives the inline error message key. */
  protected readonly photoError = signal<string | null>(null);

  /** Client-side mirror of the API's accepted types + 10 MB ceiling. */
  private static readonly ALLOWED_PHOTO_TYPES = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
  ];
  private static readonly MAX_PHOTO_BYTES = 10 * 1024 * 1024;

  protected readonly min = GIFT_CARD_MIN_DENOMINATION;
  protected readonly max = GIFT_CARD_MAX_DENOMINATION;

  protected readonly selected = computed<GiftCardThemeOption | null>(
    () => this.themes().find((t) => t.theme === this.theme()) ?? null,
  );
  protected readonly presets = computed<string[]>(() => this.selected()?.presets ?? []);

  protected readonly amountNumber = computed<number>(() => {
    const n = Number(this.denomination());
    return Number.isFinite(n) ? n : 0;
  });

  protected readonly amountValid = computed<boolean>(() => {
    const n = this.amountNumber();
    return Number.isInteger(n) && n >= this.min && n <= this.max;
  });

  /**
   * Optional recipient email. EMPTY is valid; a non-empty value must
   * be a valid email (reuses Angular's Validators.email). Mirrors mobile.
   */
  protected readonly recipientEmailValid = computed<boolean>(() => {
    const v = this.recipientEmail().trim();
    if (v === '') return true;
    return Validators.email({ value: v } as AbstractControl) === null;
  });

  /**
   * Optional recipient phone. EMPTY is valid; a non-empty value must be
   * a sane international number (ui-phone-input emits a full E.164 string
   * with dial code, or '' when no national digits were entered). We
   * validate the national-digit length against the parsed country, the
   * same rule the phone-input's own validator applies.
   */
  protected readonly recipientPhoneValid = computed<boolean>(() => {
    const v = this.recipientPhone().trim();
    if (v === '') return true;
    const parsed = parseE164(v);
    if (parsed === null) return false;
    const len = parsed.nationalDigits.length;
    return len >= parsed.country.nationalDigits.min && len <= parsed.country.nationalDigits.max;
  });

  protected readonly canSubmit = computed<boolean>(
    () =>
      this.loadState() === 'ready' &&
      this.amountValid() &&
      this.recipientEmailValid() &&
      this.recipientPhoneValid() &&
      !this.submitting(),
  );

  /** Amount as the 2dp string the API expects ("500.00"). */
  private readonly amountText = computed<string>(() =>
    this.amountValid() ? this.amountNumber().toFixed(2) : '',
  );

  ngOnInit(): void {
    this.seo.set({
      title: 'Gift Cards · 3bayti',
      description:
        'Give the gift of choice with a beautifully designed 3bayti gift card — ' +
        'six occasions, delivered instantly or scheduled for the perfect moment.',
    });
    void this.loadThemes();
  }

  protected reload(): void {
    void this.loadThemes();
  }

  private async loadThemes(): Promise<void> {
    this.loadState.set('loading');
    try {
      const list = await this.gift.getThemes();
      if (list.length === 0) {
        this.loadState.set('error');
        return;
      }
      this.themes.set(list);
      if (!list.some((t) => t.theme === this.theme())) {
        this.theme.set(list[0].theme);
      }
      this.loadState.set('ready');
    } catch {
      this.loadState.set('error');
    }
  }

  protected selectTheme(t: GiftCardTheme): void {
    this.theme.set(t);
    // The API rejects a recipient photo on any non-luxury theme, so drop
    // it (and any in-flight/error state) when leaving luxury.
    if (t !== 'luxury') {
      this.recipientPhotoUrl.set(null);
      this.photoError.set(null);
      this.photoUploading.set(false);
    }
  }

  /**
   * Validate (client-side, mirroring the API) then upload the chosen
   * luxury recipient photo, storing the returned public URL for the
   * preview + purchase payload.
   */
  protected async onPhotoSelected(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    // Reset the native input so re-selecting the same file re-fires change.
    input.value = '';
    if (file === null) {
      return;
    }
    this.photoError.set(null);
    if (!GiftCardLandingPageComponent.ALLOWED_PHOTO_TYPES.includes(file.type)) {
      this.photoError.set('type');
      return;
    }
    if (file.size > GiftCardLandingPageComponent.MAX_PHOTO_BYTES) {
      this.photoError.set('size');
      return;
    }
    this.photoUploading.set(true);
    try {
      const url = await this.gift.uploadPhoto(file);
      this.recipientPhotoUrl.set(url);
    } catch {
      this.photoError.set('upload');
    } finally {
      this.photoUploading.set(false);
    }
  }

  protected removePhoto(): void {
    this.recipientPhotoUrl.set(null);
    this.photoError.set(null);
  }

  /** Preset values arrive as "500.00"; render + compare as integers. */
  protected presetValue(preset: string): number {
    return Math.trunc(Number(preset));
  }

  protected selectPreset(preset: string): void {
    this.customMode.set(false);
    this.denomination.set(String(this.presetValue(preset)));
  }

  protected enableCustom(): void {
    this.customMode.set(true);
  }

  protected onCustomInput(value: string): void {
    this.customMode.set(true);
    this.denomination.set((value ?? '').replace(/[^\d]/g, ''));
  }

  /** ui-phone-input emits the full E.164 string ('' when empty). */
  protected onPhoneChange(value: string | null): void {
    this.recipientPhone.set(value ?? '');
  }

  protected setSchedule(enabled: boolean): void {
    this.scheduleEnabled.set(enabled);
    if (!enabled) {
      this.scheduledAt.set('');
    }
  }

  /** datetime-local min — now, so the buyer can't schedule in the past. */
  protected minScheduleValue(): string {
    const now = new Date();
    const pad = (n: number): string => String(n).padStart(2, '0');
    return (
      `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}` +
      `T${pad(now.getHours())}:${pad(now.getMinutes())}`
    );
  }

  async submit(): Promise<void> {
    if (!this.canSubmit()) {
      return;
    }
    this.submitError.set(false);
    this.submitting.set(true);
    try {
      const input: GiftCardPurchaseInput = {
        theme: this.theme(),
        denomination: this.amountText(),
        recipient_name: this.recipientName().trim() || null,
        recipient_message: this.recipientMessage().trim() || null,
        recipient_photo_url: this.theme() === 'luxury' ? this.recipientPhotoUrl() : null,
        scheduled_delivery_at: this.scheduledDeliveryIso(),
        // Optional auto-delivery targets. Send blank as null (not "") so
        // the API treats them as absent and skips delivery.
        recipient_email: this.recipientEmail().trim() || null,
        recipient_phone: this.recipientPhone().trim() || null,
      };
      const card = await this.gift.purchase(input);
      const res = await this.checkout.initiate({
        channel: 'web',
        delivery_fee: '0.00',
        discount: '0.00',
        gift_card_purchase_id: card.id,
      });
      markGiftCardCheckout(res.order_reference);
      this.redirectTo(res.checkout_url);
    } catch (err) {
      this.submitting.set(false);
      if (err instanceof HttpErrorResponse && err.status === 401) {
        void this.router.navigateByUrl('/login?returnUrl=/gift-cards');
        return;
      }
      this.submitError.set(true);
    }
  }

  private scheduledDeliveryIso(): string | null {
    if (!this.scheduleEnabled() || this.scheduledAt().trim() === '') {
      return null;
    }
    const d = new Date(this.scheduledAt());
    return Number.isNaN(d.getTime()) ? null : d.toISOString();
  }

  /** Full-page navigation to the Noon hosted checkout. Isolated for tests. */
  protected redirectTo(url: string): void {
    if (isPlatformBrowser(this.platformId)) {
      window.location.assign(url);
    }
  }
}
