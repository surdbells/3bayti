import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { RouterLink, ActivatedRoute } from '@angular/router';
import { ReactiveFormsModule, FormBuilder, FormControl, FormGroup, Validators } from '@angular/forms';
import { TranslatePipe } from '@ngx-translate/core';
import { FormFieldComponent, ToastService, mapApiErrors } from '../../shared/forms';
import { ProfileService, ProfileUpdate, ProfileGender } from './profile.service';
import { PhoneService } from '../../core/auth/phone.service';
import { EmailService } from '../../core/auth/email.service';
import { AUTH_ERROR_CODES } from '../../core/auth/auth.types';
import type { AuthUser } from '../../core/auth/auth.types';

/** Steps of the inline change-phone flow. */
type PhoneChangeStep = 'enterPhone' | 'enterCode';

/** Steps of the inline change-email flow. */
type EmailChangeStep = 'enterEmail' | 'enterCode';

const GENDERS: ProfileGender[] = ['male', 'female', 'other', 'prefer_not_to_say'];
const LOCALES = ['en', 'ar', 'en-AE', 'ar-AE'];

/**
 * /account/profile, edit the authenticated user's profile.
 *
 * Editable (mirrors apps/api UpdateProfileInput exactly):
 *   first_name, last_name, gender, dob, locale.
 * Read-only display: email + phone (with verified badges), changed
 * via dedicated verified-contact flows, not this endpoint.
 *
 * Submit sends ONLY changed fields (diff against the loaded baseline),
 * leaning on the API's JSON Merge Patch semantics. Empty text inputs
 * are sent as null (clears the field) when they differ from baseline.
 *
 * Errors route through mapApiErrors: per-field VALIDATION_FAILED lands
 * on the control; anything unmapped or a network failure becomes a
 * toast.
 */
@Component({
  selector: 'app-account-profile',
  standalone: true,
  imports: [NgIf, NgFor, RouterLink, ReactiveFormsModule, TranslatePipe, FormFieldComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="account-profile" data-testid="account-profile-page">
      <div class="account-profile__container">
        <nav class="account-profile__breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ 'account.profile.title' | translate }}</span>
        </nav>

        <h1 class="account-profile__title">{{ 'account.profile.title' | translate }}</h1>

        <ng-container *ngIf="!isLoading(); else loadingState">
          <!-- Avatar upload -->
          <div class="account-profile__avatar" data-testid="prof-avatar">
            <span class="account-profile__avatar-img" aria-hidden="true">
              <img
                *ngIf="avatarUrl(); else avatarPlaceholder"
                [src]="avatarUrl()"
                [alt]="'account.profile.avatar.alt' | translate"
                data-testid="prof-avatar-img"
              />
              <ng-template #avatarPlaceholder>
                <span class="account-profile__avatar-initials">{{ initials() }}</span>
              </ng-template>
            </span>
            <div class="account-profile__avatar-control">
              <label class="account-profile__avatar-btn" [class.is-disabled]="isSaving()">
                {{ (isSaving() ? 'common.saving' : 'account.profile.avatar.change') | translate }}
                <input
                  type="file"
                  accept="image/*"
                  class="account-profile__avatar-input"
                  (change)="onAvatarSelected($event)"
                  [disabled]="isSaving()"
                  data-testid="prof-avatar-input"
                />
              </label>
              <span class="account-profile__avatar-hint">
                {{ 'account.profile.avatar.hint' | translate }}
              </span>
            </div>
          </div>

          <form
            class="account-profile__form"
            [formGroup]="form"
            (ngSubmit)="onSubmit()"
            novalidate
          >
            <div class="account-profile__row-2col">
              <ui-form-field
                [label]="'account.profile.firstName'"
                fieldId="prof-first"
                [control]="form.controls.first_name"
                [errorMap]="fieldErrors"
              >
                <input
                  id="prof-first"
                  type="text"
                  autocomplete="given-name"
                  formControlName="first_name"
                  class="auth-input"
                  data-testid="prof-first"
                />
              </ui-form-field>

              <ui-form-field
                [label]="'account.profile.lastName'"
                fieldId="prof-last"
                [control]="form.controls.last_name"
                [errorMap]="fieldErrors"
              >
                <input
                  id="prof-last"
                  type="text"
                  autocomplete="family-name"
                  formControlName="last_name"
                  class="auth-input"
                  data-testid="prof-last"
                />
              </ui-form-field>
            </div>

            <div class="account-profile__row-2col">
              <ui-form-field
                [label]="'account.profile.gender'"
                fieldId="prof-gender"
                [control]="form.controls.gender"
                [errorMap]="fieldErrors"
              >
                <select
                  id="prof-gender"
                  formControlName="gender"
                  class="auth-input"
                  data-testid="prof-gender"
                >
                  <option value="">{{ 'account.profile.genderUnset' | translate }}</option>
                  <option *ngFor="let g of genders" [value]="g">
                    {{ 'account.profile.genderOptions.' + g | translate }}
                  </option>
                </select>
              </ui-form-field>

              <ui-form-field
                [label]="'account.profile.dob'"
                fieldId="prof-dob"
                [control]="form.controls.dob"
                [errorMap]="fieldErrors"
              >
                <input
                  id="prof-dob"
                  type="date"
                  formControlName="dob"
                  class="auth-input"
                  data-testid="prof-dob"
                />
              </ui-form-field>
            </div>

            <ui-form-field
              [label]="'account.profile.locale'"
              fieldId="prof-locale"
              [control]="form.controls.locale"
              [errorMap]="fieldErrors"
            >
              <select
                id="prof-locale"
                formControlName="locale"
                class="auth-input"
                data-testid="prof-locale"
              >
                <option *ngFor="let l of locales" [value]="l">
                  {{ 'account.profile.localeOptions.' + l | translate }}
                </option>
              </select>
            </ui-form-field>

            <!-- Read-only contact info -->
            <div class="account-profile__readonly" data-testid="prof-readonly">
              <div class="account-profile__readonly-row">
                <span class="account-profile__readonly-label">{{ 'account.profile.email' | translate }}</span>
                <span class="account-profile__readonly-value">
                  {{ email() }}
                  <span
                    *ngIf="emailVerified()"
                    class="account-profile__verified"
                    [attr.title]="'account.profile.verified' | translate"
                    data-testid="prof-email-verified"
                  >✓</span>
                  <button
                    *ngIf="!emailEditing()"
                    type="button"
                    class="account-profile__readonly-action"
                    (click)="startEmailChange()"
                    data-testid="prof-email-change"
                  >
                    {{ 'account.profile.emailChange.change' | translate }}
                  </button>
                  <span
                    *ngIf="needsEmailUpdate() && !emailEditing()"
                    class="account-profile__readonly-hint"
                    data-testid="prof-email-needs-update"
                  >
                    {{ 'account.profile.emailChange.needsUpdate' | translate }}
                  </span>
                </span>
              </div>

              <!-- Inline change-email flow (OTP verified). -->
              <div
                *ngIf="emailEditing()"
                class="account-profile__email-change"
                data-testid="prof-email-change-flow"
              >
                <!-- Step 1: enter the new email. -->
                <ng-container *ngIf="emailStep() === 'enterEmail'">
                  <label class="account-profile__email-label" for="prof-email-new">
                    {{ 'account.profile.emailChange.newLabel' | translate }}
                  </label>
                  <input
                    id="prof-email-new"
                    type="email"
                    inputmode="email"
                    autocomplete="email"
                    spellcheck="false"
                    class="auth-input"
                    [placeholder]="'account.profile.emailChange.placeholder' | translate"
                    [value]="newEmail()"
                    (input)="onNewEmailInput($event)"
                    [disabled]="emailBusy()"
                    data-testid="prof-email-input"
                  />
                  <p class="account-profile__email-hint">
                    {{ 'account.profile.emailChange.hint' | translate }}
                  </p>

                  <p
                    *ngIf="emailError() as emailErr"
                    class="account-profile__email-error"
                    role="alert"
                    data-testid="prof-email-error"
                  >
                    {{ emailErr | translate }}
                  </p>

                  <div class="account-profile__email-actions">
                    <button
                      type="button"
                      class="account-profile__email-btn"
                      (click)="sendEmailOtp()"
                      [disabled]="emailBusy()"
                      data-testid="prof-email-send"
                    >
                      {{ (emailBusy() ? 'common.loading' : 'account.profile.emailChange.sendCode') | translate }}
                    </button>
                    <button
                      type="button"
                      class="account-profile__email-btn account-profile__email-btn--ghost"
                      (click)="cancelEmailChange()"
                      [disabled]="emailBusy()"
                      data-testid="prof-email-cancel"
                    >
                      {{ 'account.profile.emailChange.cancel' | translate }}
                    </button>
                  </div>
                </ng-container>

                <!-- Step 2: enter the OTP code sent to the new email. -->
                <ng-container *ngIf="emailStep() === 'enterCode'">
                  <label class="account-profile__email-label" for="prof-email-otp">
                    {{ 'account.profile.emailChange.codeLabel' | translate : { email: newEmail() } }}
                  </label>
                  <input
                    id="prof-email-otp"
                    type="text"
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    maxlength="6"
                    spellcheck="false"
                    class="auth-input auth-input--code"
                    [value]="emailCode()"
                    (input)="onEmailCodeInput($event)"
                    [disabled]="emailBusy()"
                    data-testid="prof-email-code"
                  />

                  <p
                    *ngIf="emailError() as emailErr"
                    class="account-profile__email-error"
                    role="alert"
                    data-testid="prof-email-error"
                  >
                    {{ emailErr | translate }}
                  </p>

                  <div class="account-profile__email-actions">
                    <button
                      type="button"
                      class="account-profile__email-btn"
                      (click)="verifyEmailOtp()"
                      [disabled]="emailBusy()"
                      data-testid="prof-email-verify"
                    >
                      {{ (emailBusy() ? 'common.loading' : 'account.profile.emailChange.verify') | translate }}
                    </button>
                    <button
                      type="button"
                      class="account-profile__email-btn account-profile__email-btn--ghost"
                      (click)="resendEmailOtp()"
                      [disabled]="emailBusy()"
                      data-testid="prof-email-resend"
                    >
                      {{ 'account.profile.emailChange.resend' | translate }}
                    </button>
                    <button
                      type="button"
                      class="account-profile__email-btn account-profile__email-btn--ghost"
                      (click)="cancelEmailChange()"
                      [disabled]="emailBusy()"
                      data-testid="prof-email-cancel"
                    >
                      {{ 'account.profile.emailChange.cancel' | translate }}
                    </button>
                  </div>
                </ng-container>
              </div>
              <div class="account-profile__readonly-row">
                <span class="account-profile__readonly-label">{{ 'account.profile.phone' | translate }}</span>
                <span class="account-profile__readonly-value">
                  {{ phone() }}
                  <span
                    *ngIf="phoneVerified()"
                    class="account-profile__verified"
                    [attr.title]="'account.profile.verified' | translate"
                    data-testid="prof-phone-verified"
                  >✓</span>
                  <button
                    *ngIf="!phoneEditing()"
                    type="button"
                    class="account-profile__readonly-action"
                    (click)="startPhoneChange()"
                    data-testid="prof-phone-change"
                  >
                    {{ 'account.profile.phoneChange.change' | translate }}
                  </button>
                </span>
              </div>

              <!-- Inline change-phone flow (OTP verified). -->
              <div
                *ngIf="phoneEditing()"
                class="account-profile__phone-change"
                data-testid="prof-phone-change-flow"
              >
                <!-- Step 1: enter the new number. -->
                <ng-container *ngIf="phoneStep() === 'enterPhone'">
                  <label class="account-profile__phone-label" for="prof-phone-new">
                    {{ 'account.profile.phoneChange.newLabel' | translate }}
                  </label>
                  <input
                    id="prof-phone-new"
                    type="tel"
                    inputmode="tel"
                    autocomplete="tel"
                    spellcheck="false"
                    class="auth-input"
                    [placeholder]="'account.profile.phoneChange.placeholder' | translate"
                    [value]="newPhone()"
                    (input)="onNewPhoneInput($event)"
                    [disabled]="phoneBusy()"
                    data-testid="prof-phone-input"
                  />
                  <p class="account-profile__phone-hint">
                    {{ 'account.profile.phoneChange.hint' | translate }}
                  </p>

                  <p
                    *ngIf="phoneError() as phoneErr"
                    class="account-profile__phone-error"
                    role="alert"
                    data-testid="prof-phone-error"
                  >
                    {{ phoneErr | translate }}
                  </p>

                  <div class="account-profile__phone-actions">
                    <button
                      type="button"
                      class="account-profile__phone-btn"
                      (click)="sendPhoneOtp()"
                      [disabled]="phoneBusy()"
                      data-testid="prof-phone-send"
                    >
                      {{ (phoneBusy() ? 'common.loading' : 'account.profile.phoneChange.sendCode') | translate }}
                    </button>
                    <button
                      type="button"
                      class="account-profile__phone-btn account-profile__phone-btn--ghost"
                      (click)="cancelPhoneChange()"
                      [disabled]="phoneBusy()"
                      data-testid="prof-phone-cancel"
                    >
                      {{ 'account.profile.phoneChange.cancel' | translate }}
                    </button>
                  </div>
                </ng-container>

                <!-- Step 2: enter the OTP code. -->
                <ng-container *ngIf="phoneStep() === 'enterCode'">
                  <label class="account-profile__phone-label" for="prof-phone-otp">
                    {{ 'account.profile.phoneChange.codeLabel' | translate : { phone: newPhone() } }}
                  </label>
                  <input
                    id="prof-phone-otp"
                    type="text"
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    maxlength="6"
                    spellcheck="false"
                    class="auth-input auth-input--code"
                    [value]="phoneCode()"
                    (input)="onPhoneCodeInput($event)"
                    [disabled]="phoneBusy()"
                    data-testid="prof-phone-code"
                  />

                  <p
                    *ngIf="phoneError() as phoneErr"
                    class="account-profile__phone-error"
                    role="alert"
                    data-testid="prof-phone-error"
                  >
                    {{ phoneErr | translate }}
                  </p>

                  <div class="account-profile__phone-actions">
                    <button
                      type="button"
                      class="account-profile__phone-btn"
                      (click)="verifyPhoneOtp()"
                      [disabled]="phoneBusy()"
                      data-testid="prof-phone-verify"
                    >
                      {{ (phoneBusy() ? 'common.loading' : 'account.profile.phoneChange.verify') | translate }}
                    </button>
                    <button
                      type="button"
                      class="account-profile__phone-btn account-profile__phone-btn--ghost"
                      (click)="resendPhoneOtp()"
                      [disabled]="phoneBusy()"
                      data-testid="prof-phone-resend"
                    >
                      {{ 'account.profile.phoneChange.resend' | translate }}
                    </button>
                    <button
                      type="button"
                      class="account-profile__phone-btn account-profile__phone-btn--ghost"
                      (click)="cancelPhoneChange()"
                      [disabled]="phoneBusy()"
                      data-testid="prof-phone-cancel"
                    >
                      {{ 'account.profile.phoneChange.cancel' | translate }}
                    </button>
                  </div>
                </ng-container>
              </div>
            </div>

            <div class="account-profile__actions">
              <button
                type="submit"
                class="account-profile__save"
                [disabled]="isSaving()"
                data-testid="prof-save"
              >
                {{ (isSaving() ? 'common.saving' : 'account.profile.save') | translate }}
              </button>
            </div>
          </form>

          <div class="account-profile__links" data-testid="prof-connected-link-row">
            <a routerLink="/account/connected" class="account-profile__link" data-testid="prof-connected-link">
              {{ 'account.connected.title' | translate }}
            </a>
          </div>

          <div class="account-profile__danger" data-testid="prof-danger-zone">
            <a routerLink="/account/delete" class="account-profile__danger-link" data-testid="prof-delete-link">
              {{ 'account.delete.title' | translate }}
            </a>
          </div>
        </ng-container>

        <ng-template #loadingState>
          <div class="account-profile__loading" data-testid="prof-loading">
            {{ 'common.loading' | translate }}
          </div>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './account-profile.scss',
})
export class AccountProfilePageComponent implements OnInit {
  private readonly profileService = inject(ProfileService);
  private readonly phoneService = inject(PhoneService);
  private readonly emailService = inject(EmailService);
  private readonly route = inject(ActivatedRoute);
  private readonly toast = inject(ToastService);

  protected readonly genders = GENDERS;
  protected readonly locales = LOCALES;

  protected readonly isLoading = this.profileService.isLoading;
  protected readonly isSaving = this.profileService.isSaving;

  private readonly _user = signal<AuthUser | null>(null);
  protected email = (): string => this._user()?.email ?? '';
  protected phone = (): string => this._user()?.phone ?? '';
  protected emailVerified = (): boolean => this._user()?.is_email_verified ?? false;
  protected phoneVerified = (): boolean => this._user()?.is_phone_verified ?? false;
  protected needsEmailUpdate = (): boolean => this._user()?.needs_email_update ?? false;
  protected avatarUrl = (): string | null => this._user()?.avatar_url ?? null;

  /* ---- Change-phone (OTP) flow state ---------------------------------
     A small inline two-step flow next to the read-only phone: enter a new
     E.164 number → send OTP → enter the 6-digit code → verify. State is
     signal-based (no reactive form) since it's a self-contained widget;
     PhoneService writes the verified number back into AuthService, and we
     mirror it into the local _user so phone()/phoneVerified() update. */
  protected readonly phoneEditing = signal<boolean>(false);
  protected readonly phoneStep = signal<PhoneChangeStep>('enterPhone');
  protected readonly newPhone = signal<string>('');
  protected readonly phoneCode = signal<string>('');
  protected readonly phoneBusy = signal<boolean>(false);
  /** i18n key of the current inline error, or null when clear. */
  protected readonly phoneError = signal<string | null>(null);
  private readonly phoneVerificationId = signal<string | null>(null);

  /* ---- Change-email (OTP) flow state ---------------------------------
     Mirror of the change-phone widget: enter a new email → send OTP (to the
     NEW address, proving deliverability) → enter the 6-digit code → verify.
     EmailService writes the promoted email back into AuthService; we mirror
     it into _user so email()/emailVerified()/needsEmailUpdate() update. */
  protected readonly emailEditing = signal<boolean>(false);
  protected readonly emailStep = signal<EmailChangeStep>('enterEmail');
  protected readonly newEmail = signal<string>('');
  protected readonly emailCode = signal<string>('');
  protected readonly emailBusy = signal<boolean>(false);
  /** i18n key of the current inline error, or null when clear. */
  protected readonly emailError = signal<string | null>(null);
  private readonly emailVerificationId = signal<string | null>(null);

  /** Initials shown as a placeholder when no avatar is set. */
  protected initials(): string {
    const u = this._user();
    const first = u?.first_name?.trim() ?? '';
    const last = u?.last_name?.trim() ?? '';
    if (first !== '' && last !== '') return (first[0] + last[0]).toUpperCase();
    if (first !== '') return first.substring(0, 2).toUpperCase();
    const localPart = (u?.email ?? '').split('@')[0] ?? '';
    return localPart.substring(0, 2).toUpperCase();
  }

  /** Max avatar upload size: 5 MB. */
  private static readonly MAX_AVATAR_BYTES = 5 * 1024 * 1024;

  protected readonly form: FormGroup<{
    first_name: FormControl<string>;
    last_name: FormControl<string>;
    gender: FormControl<string>;
    dob: FormControl<string>;
    locale: FormControl<string>;
  }>;

  /** Per-field i18n error keys surfaced by FormFieldComponent. */
  protected readonly fieldErrors: Record<string, string> = {
    apiValidation: 'account.profile.errors.invalid',
    maxlength: 'account.profile.errors.tooLong',
  };

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      first_name: fb.control('', [Validators.maxLength(100)]),
      last_name: fb.control('', [Validators.maxLength(100)]),
      gender: fb.control(''),
      dob: fb.control(''),
      locale: fb.control('en'),
    });
  }

  async ngOnInit(): Promise<void> {
    try {
      const user = await this.profileService.getProfile();
      this._user.set(user);
      this.form.patchValue({
        first_name: user.first_name ?? '',
        last_name: user.last_name ?? '',
        gender: user.gender ?? '',
        dob: user.dob ?? '',
        locale: user.locale ?? 'en',
      });
      this.form.markAsPristine();
      // Deep-link from the "update your email" reminder banner opens the flow.
      if (this.route.snapshot.queryParamMap.get('editEmail')) {
        this.startEmailChange();
      }
    } catch {
      this.toast.error('account.profile.errors.loadFailed');
    }
  }

  /**
   * Avatar file picker: validate (image type, <=5 MB), read as a base64
   * data URL, then POST it. On success the new avatar_url is reflected
   * both here and in the auth user (header user-menu) via the service.
   */
  protected onAvatarSelected(event: Event): void {
    if (this.isSaving()) return;
    const input = event.target as HTMLInputElement;
    const file = input.files && input.files[0];
    input.value = ''; // allow re-picking the same file
    if (!file) return;

    if (!file.type.startsWith('image/')) {
      this.toast.error('account.profile.avatar.errors.type');
      return;
    }
    if (file.size > AccountProfilePageComponent.MAX_AVATAR_BYTES) {
      this.toast.error('account.profile.avatar.errors.tooLarge');
      return;
    }

    const reader = new FileReader();
    reader.onload = () => {
      void this.uploadAvatar(reader.result as string);
    };
    reader.onerror = () => this.toast.error('account.profile.avatar.errors.read');
    reader.readAsDataURL(file);
  }

  private async uploadAvatar(dataUrl: string): Promise<void> {
    try {
      const avatarUrl = await this.profileService.uploadAvatar(dataUrl);
      const user = this._user();
      if (user !== null) this._user.set({ ...user, avatar_url: avatarUrl });
      this.toast.success('account.profile.avatar.updated');
    } catch (err) {
      const result = mapApiErrors(err, this.form, {});
      if (result.isNetworkError) {
        this.toast.error('common.errors.network');
      } else {
        this.toast.error('account.profile.avatar.errors.uploadFailed');
      }
    }
  }

  protected async onSubmit(): Promise<void> {
    if (this.isSaving()) return;
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const patch = this.buildPatch();
    if (Object.keys(patch).length === 0) {
      /* Nothing changed, no API call, gentle confirmation. */
      this.toast.info('account.profile.noChanges');
      return;
    }

    try {
      const updated = await this.profileService.updateProfile(patch);
      this._user.set(updated);
      this.form.markAsPristine();
      this.toast.success('account.profile.saved');
    } catch (err) {
      const result = mapApiErrors(err, this.form, {});
      if (result.isNetworkError) {
        this.toast.error('common.errors.network');
      } else if (result.unmapped.length > 0) {
        this.toast.error('account.profile.errors.saveFailed');
      }
    }
  }

  /* ------------------------------------------------------------------
     Change-phone (OTP) flow
     ------------------------------------------------------------------ */

  /** Open the inline flow, pre-filling the current number for convenience. */
  protected startPhoneChange(): void {
    this.phoneEditing.set(true);
    this.phoneStep.set('enterPhone');
    this.newPhone.set(this.phone());
    this.phoneCode.set('');
    this.phoneError.set(null);
    this.phoneVerificationId.set(null);
  }

  /** Close the flow and reset all transient state. */
  protected cancelPhoneChange(): void {
    if (this.phoneBusy()) return;
    this.phoneEditing.set(false);
    this.phoneStep.set('enterPhone');
    this.newPhone.set('');
    this.phoneCode.set('');
    this.phoneError.set(null);
    this.phoneVerificationId.set(null);
  }

  protected onNewPhoneInput(event: Event): void {
    this.newPhone.set((event.target as HTMLInputElement).value);
    if (this.phoneError() !== null) this.phoneError.set(null);
  }

  protected onPhoneCodeInput(event: Event): void {
    this.phoneCode.set((event.target as HTMLInputElement).value);
    if (this.phoneError() !== null) this.phoneError.set(null);
  }

  /** Basic client-side E.164 check: leading '+', then 7–15 digits. */
  private isValidE164(phone: string): boolean {
    return /^\+\d{7,15}$/.test(phone);
  }

  /**
   * Step 1 → 2: validate the number, POST /me/phone to dispatch an OTP,
   * store the verification_id and advance to the code step. A 409
   * CONFLICT_PHONE_TAKEN (number on another account) surfaces inline.
   */
  protected async sendPhoneOtp(): Promise<void> {
    if (this.phoneBusy()) return;
    const phone = this.newPhone().trim();
    if (!this.isValidE164(phone)) {
      this.phoneError.set('account.profile.phoneChange.errors.invalidPhone');
      return;
    }
    this.phoneBusy.set(true);
    this.phoneError.set(null);
    try {
      const res = await this.phoneService.sendOtp(phone);
      this.phoneVerificationId.set(res.verification_id);
      this.newPhone.set(phone);
      this.phoneCode.set('');
      this.phoneStep.set('enterCode');
    } catch (err) {
      this.phoneError.set(this.sendErrorKey(err));
    } finally {
      this.phoneBusy.set(false);
    }
  }

  /** Re-dispatch an OTP to the same number (stays on the code step). */
  protected async resendPhoneOtp(): Promise<void> {
    if (this.phoneBusy()) return;
    const phone = this.newPhone().trim();
    if (!this.isValidE164(phone)) {
      this.phoneError.set('account.profile.phoneChange.errors.invalidPhone');
      return;
    }
    this.phoneBusy.set(true);
    this.phoneError.set(null);
    try {
      const res = await this.phoneService.sendOtp(phone);
      this.phoneVerificationId.set(res.verification_id);
      this.phoneCode.set('');
      this.toast.success('account.profile.phoneChange.codeResent');
    } catch (err) {
      this.phoneError.set(this.sendErrorKey(err));
    } finally {
      this.phoneBusy.set(false);
    }
  }

  /**
   * Step 2: confirm the OTP via POST /me/phone/verify. On success
   * PhoneService writes the verified number back into AuthService; we
   * mirror it into _user so phone()/phoneVerified() update, then close.
   */
  protected async verifyPhoneOtp(): Promise<void> {
    if (this.phoneBusy()) return;
    const vid = this.phoneVerificationId();
    const code = this.phoneCode().trim();
    if (vid === null) {
      this.phoneError.set('account.profile.phoneChange.errors.sendFailed');
      return;
    }
    if (!/^\d{4,6}$/.test(code)) {
      this.phoneError.set('account.profile.phoneChange.errors.invalidCode');
      return;
    }
    this.phoneBusy.set(true);
    this.phoneError.set(null);
    try {
      const res = await this.phoneService.verify(vid, code);
      const user = this._user();
      if (user !== null) {
        this._user.set({ ...user, phone: res.phone, is_phone_verified: res.is_phone_verified });
      }
      this.phoneEditing.set(false);
      this.phoneStep.set('enterPhone');
      this.newPhone.set('');
      this.phoneCode.set('');
      this.phoneVerificationId.set(null);
      this.toast.success('account.profile.phoneChange.updated');
    } catch (err) {
      this.phoneError.set(this.verifyErrorKey(err));
    } finally {
      this.phoneBusy.set(false);
    }
  }

  /** Extract the API error_code from an HttpErrorResponse body (flat or nested). */
  private extractErrorCode(err: unknown): string | null {
    if (!(err instanceof HttpErrorResponse)) return null;
    const body = err.error as
      | { error_code?: string; code?: string; error?: { code?: string } }
      | string
      | null;
    if (body === null || typeof body !== 'object') return null;
    return body.error_code ?? body.error?.code ?? body.code ?? null;
  }

  /** Map a send-OTP failure to an inline i18n key. */
  private sendErrorKey(err: unknown): string {
    if (!(err instanceof HttpErrorResponse) || err.status === 0) {
      return 'common.errors.network';
    }
    const code = this.extractErrorCode(err);
    if (code === AUTH_ERROR_CODES.CONFLICT_PHONE_TAKEN) {
      return 'account.profile.phoneChange.errors.taken';
    }
    if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
      return 'account.profile.phoneChange.errors.rateLimited';
    }
    return 'account.profile.phoneChange.errors.sendFailed';
  }

  /** Map a verify-OTP failure to an inline i18n key. */
  private verifyErrorKey(err: unknown): string {
    if (!(err instanceof HttpErrorResponse) || err.status === 0) {
      return 'common.errors.network';
    }
    const code = this.extractErrorCode(err);
    if (
      code === AUTH_ERROR_CODES.OTP_INVALID_CODE ||
      code === AUTH_ERROR_CODES.OTP_VERIFICATION_FAILED
    ) {
      return 'account.profile.phoneChange.errors.invalidCode';
    }
    if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
      return 'account.profile.phoneChange.errors.rateLimited';
    }
    return 'account.profile.phoneChange.errors.verifyFailed';
  }

  /* ------------------------------------------------------------------
     Change-email (OTP) flow
     ------------------------------------------------------------------ */

  /** Open the inline flow. Starts blank — the point is to move OFF the
      current (non-deliverable) address, so pre-filling it is unhelpful. */
  protected startEmailChange(): void {
    this.emailEditing.set(true);
    this.emailStep.set('enterEmail');
    this.newEmail.set('');
    this.emailCode.set('');
    this.emailError.set(null);
    this.emailVerificationId.set(null);
  }

  /** Close the flow and reset all transient state. */
  protected cancelEmailChange(): void {
    if (this.emailBusy()) return;
    this.emailEditing.set(false);
    this.emailStep.set('enterEmail');
    this.newEmail.set('');
    this.emailCode.set('');
    this.emailError.set(null);
    this.emailVerificationId.set(null);
  }

  protected onNewEmailInput(event: Event): void {
    this.newEmail.set((event.target as HTMLInputElement).value);
    if (this.emailError() !== null) this.emailError.set(null);
  }

  protected onEmailCodeInput(event: Event): void {
    this.emailCode.set((event.target as HTMLInputElement).value);
    if (this.emailError() !== null) this.emailError.set(null);
  }

  /** Basic client-side email check; the server is the real validator. */
  private isValidEmail(email: string): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  /**
   * Step 1 → 2: validate the address, POST /me/email to dispatch an OTP to it,
   * store the verification_id and advance to the code step. A 422 (address
   * itself non-deliverable / invalid) or 409 CONFLICT_EMAIL_TAKEN surfaces
   * inline.
   */
  protected async sendEmailOtp(): Promise<void> {
    if (this.emailBusy()) return;
    const email = this.newEmail().trim();
    if (!this.isValidEmail(email)) {
      this.emailError.set('account.profile.emailChange.errors.invalidEmail');
      return;
    }
    this.emailBusy.set(true);
    this.emailError.set(null);
    try {
      const res = await this.emailService.sendOtp(email);
      this.emailVerificationId.set(res.verification_id);
      this.newEmail.set(email);
      this.emailCode.set('');
      this.emailStep.set('enterCode');
    } catch (err) {
      this.emailError.set(this.sendEmailErrorKey(err));
    } finally {
      this.emailBusy.set(false);
    }
  }

  /** Re-dispatch an OTP to the same address (stays on the code step). */
  protected async resendEmailOtp(): Promise<void> {
    if (this.emailBusy()) return;
    const email = this.newEmail().trim();
    if (!this.isValidEmail(email)) {
      this.emailError.set('account.profile.emailChange.errors.invalidEmail');
      return;
    }
    this.emailBusy.set(true);
    this.emailError.set(null);
    try {
      const res = await this.emailService.sendOtp(email);
      this.emailVerificationId.set(res.verification_id);
      this.emailCode.set('');
      this.toast.success('account.profile.emailChange.codeResent');
    } catch (err) {
      this.emailError.set(this.sendEmailErrorKey(err));
    } finally {
      this.emailBusy.set(false);
    }
  }

  /**
   * Step 2: confirm the OTP via POST /me/email/verify. On success EmailService
   * promotes the email in AuthService; we mirror it into _user so the profile
   * (and the needs-update hint) update, then close.
   */
  protected async verifyEmailOtp(): Promise<void> {
    if (this.emailBusy()) return;
    const vid = this.emailVerificationId();
    const code = this.emailCode().trim();
    if (vid === null) {
      this.emailError.set('account.profile.emailChange.errors.sendFailed');
      return;
    }
    if (!/^\d{4,6}$/.test(code)) {
      this.emailError.set('account.profile.emailChange.errors.invalidCode');
      return;
    }
    this.emailBusy.set(true);
    this.emailError.set(null);
    try {
      const res = await this.emailService.verify(vid, code);
      const user = this._user();
      if (user !== null) {
        this._user.set({
          ...user,
          email: res.email,
          is_email_verified: res.is_email_verified,
          needs_email_update: res.needs_email_update,
        });
      }
      this.emailEditing.set(false);
      this.emailStep.set('enterEmail');
      this.newEmail.set('');
      this.emailCode.set('');
      this.emailVerificationId.set(null);
      this.toast.success('account.profile.emailChange.updated');
    } catch (err) {
      this.emailError.set(this.verifyEmailErrorKey(err));
    } finally {
      this.emailBusy.set(false);
    }
  }

  /** Map a send-OTP failure to an inline i18n key. */
  private sendEmailErrorKey(err: unknown): string {
    if (!(err instanceof HttpErrorResponse) || err.status === 0) {
      return 'common.errors.network';
    }
    const code = this.extractErrorCode(err);
    if (code === AUTH_ERROR_CODES.CONFLICT_EMAIL_TAKEN) {
      return 'account.profile.emailChange.errors.taken';
    }
    if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
      return 'account.profile.emailChange.errors.rateLimited';
    }
    if (code === AUTH_ERROR_CODES.VALIDATION_FAILED) {
      // The new address is itself non-deliverable (relay / placeholder) or
      // malformed — the server rejects it with 422 VALIDATION_FAILED.
      return 'account.profile.emailChange.errors.invalidEmail';
    }
    return 'account.profile.emailChange.errors.sendFailed';
  }

  /** Map a verify-OTP failure to an inline i18n key. */
  private verifyEmailErrorKey(err: unknown): string {
    if (!(err instanceof HttpErrorResponse) || err.status === 0) {
      return 'common.errors.network';
    }
    const code = this.extractErrorCode(err);
    if (
      code === AUTH_ERROR_CODES.OTP_INVALID_CODE ||
      code === AUTH_ERROR_CODES.OTP_VERIFICATION_FAILED
    ) {
      return 'account.profile.emailChange.errors.invalidCode';
    }
    if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
      return 'account.profile.emailChange.errors.rateLimited';
    }
    if (code === AUTH_ERROR_CODES.CONFLICT_EMAIL_TAKEN) {
      return 'account.profile.emailChange.errors.taken';
    }
    return 'account.profile.emailChange.errors.verifyFailed';
  }

  /**
   * Diff the form against the loaded baseline; include only changed
   * fields. Empty text → null (clear). Mirrors the API's Merge Patch
   * contract: omitted keys are left untouched.
   */
  private buildPatch(): ProfileUpdate {
    const user = this._user();
    const v = this.form.getRawValue();
    const patch: ProfileUpdate = {};

    const normFirst = v.first_name.trim() === '' ? null : v.first_name.trim();
    if (normFirst !== (user?.first_name ?? null)) patch.first_name = normFirst;

    const normLast = v.last_name.trim() === '' ? null : v.last_name.trim();
    if (normLast !== (user?.last_name ?? null)) patch.last_name = normLast;

    const normGender = v.gender === '' ? null : (v.gender as ProfileGender);
    if (normGender !== (user?.gender ?? null)) patch.gender = normGender;

    const normDob = v.dob === '' ? null : v.dob;
    if (normDob !== (user?.dob ?? null)) patch.dob = normDob;

    if (v.locale !== (user?.locale ?? null)) patch.locale = v.locale;

    return patch;
  }
}
