import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnDestroy,
} from '@angular/core';
import { NgIf } from '@angular/common';
import {
  FormBuilder,
  FormGroup,
  FormControl,
  Validators,
  ReactiveFormsModule,
  AbstractControl,
  ValidationErrors,
} from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthService } from '../../../core/auth/auth.service';
import {
  FormFieldComponent,
  PhoneInputComponent,
  PasswordStrengthComponent,
  PHONE_COUNTRIES,
  mapApiErrors,
  ApiErrorMapping,
  ToastService,
} from '../../../shared/forms';
import { AUTH_ERROR_CODES } from '../../../core/auth/auth.types';

const RESEND_COOLDOWN_SECONDS = 30;

/**
 * Register page — /register. PHONE-FIRST 4-step flow (web parity with the
 * mobile v3 registration, issue #8). Replaces the legacy single-form
 * register → verify-phone hand-off.
 *
 * Mirrors apps/mobile/src/app/public/register.
 *
 *   Step 1 (Phone): PhoneInputComponent. A debounced AuthService.validatePhone
 *     probe surfaces an inline "already registered" hint (best-effort UX;
 *     the hard gate is the Continue-time CONFLICT_PHONE_TAKEN). [Continue] →
 *     AuthService.registerInitiate({ phone, country_code }) → verification_id.
 *   Step 2 (Verify phone): "We sent a code to <phone>" + OTP. [Verify] →
 *     AuthService.registerVerifyPhone({ verification_id, code }) →
 *     registration_token. Resend re-calls initiate; "Change number" → Step 1.
 *   Step 3 (Details): verified phone shown READONLY + first_name, last_name,
 *     email, password, confirm_password. [Continue] →
 *     AuthService.registerSubmit({ registration_token, ... }) →
 *     verification_id (email). AUTH_INVALID_TOKEN (expired) → restart Step 1.
 *   Step 4 (Verify email): "We sent a code to <email>" + OTP. [Verify] →
 *     AuthService.registerConfirmEmail({ verification_id, code }) → token-pair
 *     → applyAuthState (auto-login) → navigate. Resend re-calls submit.
 *
 * country_code is derived from the phone input's E.164 dial code — we don't
 * ask separately.
 */
@Component({
  selector: 'app-register',
  standalone: true,
  imports: [
    NgIf,
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
    FormFieldComponent,
    PhoneInputComponent,
    PasswordStrengthComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="auth-page" data-testid="register-page">
      <div class="auth-card auth-card--wide">
        <!-- ===== Step 1: phone ===== -->
        <ng-container *ngIf="step() === 1">
          <h1 class="auth-card__title">{{ 'auth.register.title' | translate }}</h1>
          <p class="auth-card__subtitle">{{ 'auth.register.phoneSubtitle' | translate }}</p>

          <form
            [formGroup]="phoneForm"
            (ngSubmit)="onInitiate()"
            novalidate
            class="auth-form"
            data-testid="register-phone-form"
          >
            <ui-form-field
              [label]="'auth.register.phoneLabel'"
              fieldId="register-phone"
              [required]="true"
              [helper]="'auth.register.phoneHint'"
              [control]="phoneForm.controls.phone"
              [errorMap]="phoneErrors"
            >
              <ui-phone-input
                inputId="register-phone"
                formControlName="phone"
                data-testid="register-phone"
              ></ui-phone-input>
            </ui-form-field>

            <p
              *ngIf="phoneTaken()"
              class="auth-field-hint auth-field-hint--warn"
              role="status"
              data-testid="register-phone-taken"
            >
              {{ 'auth.register.errors.phoneTakenHint' | translate }}
              <a routerLink="/login" class="auth-link auth-link--strong">
                {{ 'auth.register.signInLink' | translate }}
              </a>
            </p>

            <button
              type="submit"
              class="auth-submit"
              [disabled]="submitting()"
              data-testid="register-phone-submit"
            >
              {{ (submitting() ? 'common.loading' : 'auth.register.continue') | translate }}
            </button>
          </form>

          <p class="auth-card__footer">
            {{ 'auth.register.haveAccount' | translate }}
            <a routerLink="/login" class="auth-link auth-link--strong">
              {{ 'auth.register.signInLink' | translate }}
            </a>
          </p>
        </ng-container>

        <!-- ===== Step 2: verify phone ===== -->
        <ng-container *ngIf="step() === 2">
          <h1 class="auth-card__title">{{ 'auth.register.verifyPhoneTitle' | translate }}</h1>
          <p class="auth-card__subtitle">
            {{ 'auth.register.verifyPhoneSubtitle' | translate : { phone: fullPhone() } }}
          </p>

          <form
            [formGroup]="otpForm"
            (ngSubmit)="onVerifyPhone()"
            novalidate
            class="auth-form"
            data-testid="register-verify-phone-form"
          >
            <ui-form-field
              [label]="'auth.register.codeLabel'"
              fieldId="register-phone-code"
              [required]="true"
              [control]="otpForm.controls.code"
              [errorMap]="codeErrors"
            >
              <input
                id="register-phone-code"
                type="text"
                autocomplete="one-time-code"
                inputmode="numeric"
                maxlength="6"
                spellcheck="false"
                formControlName="code"
                class="auth-input auth-input--code"
                data-testid="register-phone-code"
              />
            </ui-form-field>

            <button
              type="submit"
              class="auth-submit"
              [disabled]="submitting()"
              data-testid="register-verify-phone-submit"
            >
              {{ (submitting() ? 'common.loading' : 'auth.register.verify') | translate }}
            </button>
          </form>

          <div class="auth-resend">
            <p class="auth-resend__prompt">{{ 'auth.register.resendCta' | translate }}</p>
            <button
              type="button"
              class="auth-link auth-resend__button"
              [disabled]="resendCooldown() > 0 || submitting()"
              [attr.aria-disabled]="resendCooldown() > 0 || submitting()"
              (click)="onResendPhone()"
              data-testid="register-resend-phone"
            >
              <ng-container *ngIf="resendCooldown() > 0; else readyP">
                {{ 'auth.register.resendCooldown' | translate : { seconds: resendCooldown() } }}
              </ng-container>
              <ng-template #readyP>{{ 'auth.register.resendButton' | translate }}</ng-template>
            </button>
          </div>

          <p class="auth-card__footer">
            <button type="button" class="auth-link" (click)="changePhone()" data-testid="register-change-phone">
              {{ 'auth.register.changeNumber' | translate }}
            </button>
          </p>
        </ng-container>

        <!-- ===== Step 3: details ===== -->
        <ng-container *ngIf="step() === 3">
          <h1 class="auth-card__title">{{ 'auth.register.detailsTitle' | translate }}</h1>
          <p class="auth-card__subtitle">{{ 'auth.register.detailsSubtitle' | translate }}</p>

          <form
            [formGroup]="detailsForm"
            (ngSubmit)="onSubmitDetails()"
            novalidate
            class="auth-form"
            data-testid="register-details-form"
          >
            <ui-form-field
              [label]="'auth.register.phoneLabel'"
              fieldId="register-verified-phone"
              [control]="null"
              [errorMap]="{}"
            >
              <input
                id="register-verified-phone"
                type="tel"
                [value]="fullPhone()"
                class="auth-input"
                readonly
                data-testid="register-verified-phone"
              />
            </ui-form-field>

            <div class="auth-form__row-2col">
              <ui-form-field
                [label]="'auth.register.firstNameLabel'"
                fieldId="register-first-name"
                [required]="true"
                [control]="detailsForm.controls.first_name"
                [errorMap]="genericFieldErrors"
              >
                <input
                  id="register-first-name"
                  type="text"
                  autocomplete="given-name"
                  formControlName="first_name"
                  class="auth-input"
                  data-testid="register-first-name"
                />
              </ui-form-field>

              <ui-form-field
                [label]="'auth.register.lastNameLabel'"
                fieldId="register-last-name"
                [required]="true"
                [control]="detailsForm.controls.last_name"
                [errorMap]="genericFieldErrors"
              >
                <input
                  id="register-last-name"
                  type="text"
                  autocomplete="family-name"
                  formControlName="last_name"
                  class="auth-input"
                  data-testid="register-last-name"
                />
              </ui-form-field>
            </div>

            <ui-form-field
              [label]="'auth.register.emailLabel'"
              fieldId="register-email"
              [required]="true"
              [control]="detailsForm.controls.email"
              [errorMap]="emailErrors"
            >
              <input
                id="register-email"
                type="email"
                autocomplete="email"
                inputmode="email"
                spellcheck="false"
                formControlName="email"
                class="auth-input"
                data-testid="register-email"
              />
            </ui-form-field>

            <ui-form-field
              [label]="'auth.register.passwordLabel'"
              fieldId="register-password"
              [required]="true"
              [helper]="'auth.register.passwordHint'"
              [control]="detailsForm.controls.password"
              [errorMap]="passwordErrors"
            >
              <input
                id="register-password"
                type="password"
                autocomplete="new-password"
                formControlName="password"
                class="auth-input"
                data-testid="register-password"
              />
            </ui-form-field>
            <ui-password-strength
              [password]="detailsForm.controls.password.value"
              data-testid="register-password-strength"
            ></ui-password-strength>

            <ui-form-field
              [label]="'auth.register.confirmPasswordLabel'"
              fieldId="register-confirm-password"
              [required]="true"
              [control]="detailsForm.controls.confirm_password"
              [errorMap]="confirmErrors"
            >
              <input
                id="register-confirm-password"
                type="password"
                autocomplete="new-password"
                formControlName="confirm_password"
                class="auth-input"
                data-testid="register-confirm-password"
              />
            </ui-form-field>

            <button
              type="submit"
              class="auth-submit auth-submit--with-meter"
              [disabled]="submitting()"
              data-testid="register-details-submit"
            >
              {{ (submitting() ? 'common.loading' : 'auth.register.continue') | translate }}
            </button>
          </form>
        </ng-container>

        <!-- ===== Step 4: verify email ===== -->
        <ng-container *ngIf="step() === 4">
          <h1 class="auth-card__title">{{ 'auth.register.verifyEmailTitle' | translate }}</h1>
          <p class="auth-card__subtitle">
            {{ 'auth.register.verifyEmailSubtitle' | translate : { email: detailsForm.controls.email.value } }}
          </p>

          <form
            [formGroup]="otpForm"
            (ngSubmit)="onConfirmEmail()"
            novalidate
            class="auth-form"
            data-testid="register-verify-email-form"
          >
            <ui-form-field
              [label]="'auth.register.codeLabel'"
              fieldId="register-email-code"
              [required]="true"
              [control]="otpForm.controls.code"
              [errorMap]="codeErrors"
            >
              <input
                id="register-email-code"
                type="text"
                autocomplete="one-time-code"
                inputmode="numeric"
                maxlength="6"
                spellcheck="false"
                formControlName="code"
                class="auth-input auth-input--code"
                data-testid="register-email-code"
              />
            </ui-form-field>

            <button
              type="submit"
              class="auth-submit"
              [disabled]="submitting()"
              data-testid="register-verify-email-submit"
            >
              {{ (submitting() ? 'common.loading' : 'auth.register.verify') | translate }}
            </button>
          </form>

          <div class="auth-resend">
            <p class="auth-resend__prompt">{{ 'auth.register.resendCta' | translate }}</p>
            <button
              type="button"
              class="auth-link auth-resend__button"
              [disabled]="resendCooldown() > 0 || submitting()"
              [attr.aria-disabled]="resendCooldown() > 0 || submitting()"
              (click)="onResendEmail()"
              data-testid="register-resend-email"
            >
              <ng-container *ngIf="resendCooldown() > 0; else readyE">
                {{ 'auth.register.resendCooldown' | translate : { seconds: resendCooldown() } }}
              </ng-container>
              <ng-template #readyE>{{ 'auth.register.resendButton' | translate }}</ng-template>
            </button>
          </div>

          <p class="auth-card__footer">
            <button type="button" class="auth-link" (click)="backToDetails()" data-testid="register-back-details">
              {{ 'auth.register.backToDetails' | translate }}
            </button>
          </p>
        </ng-container>
      </div>
    </main>
  `,
  styleUrl: './register.scss',
})
export class RegisterComponent implements OnDestroy {
  /** Flow step: 1 phone, 2 verify-phone, 3 details, 4 verify-email. */
  protected readonly step = signal<1 | 2 | 3 | 4>(1);

  protected readonly submitting = signal(false);
  protected readonly resendCooldown = signal(0);
  /** Inline "already registered" hint for step 1 (best-effort). */
  protected readonly phoneTaken = signal(false);

  /** Issued by initiate; consumed by verify-phone. */
  private readonly phoneVerificationId = signal<string | null>(null);
  /** Issued by verify-phone; consumed by submit. */
  private readonly registrationToken = signal<string | null>(null);
  /** Issued by submit; consumed by confirm-email. */
  private readonly emailVerificationId = signal<string | null>(null);

  protected readonly phoneForm: FormGroup<{ phone: FormControl<string> }>;
  protected readonly otpForm: FormGroup<{ code: FormControl<string> }>;
  protected readonly detailsForm: FormGroup<{
    first_name: FormControl<string>;
    last_name: FormControl<string>;
    email: FormControl<string>;
    password: FormControl<string>;
    confirm_password: FormControl<string>;
  }>;

  /** Full E.164 phone captured at step 1 (used for display + restart). */
  protected readonly fullPhone = signal('');

  protected readonly phoneErrors: Record<string, string> = {
    required: 'auth.fields.required',
    phoneInvalid: 'auth.fields.phone_invalid',
    phoneTaken: 'auth.register.errors.phoneTaken',
  };

  protected readonly codeErrors: Record<string, string> = {
    required: 'auth.fields.required',
    pattern: 'auth.validation.code6Digits',
    invalidCode: 'auth.register.errors.invalidCode',
  };

  protected readonly genericFieldErrors: Record<string, string> = {
    required: 'auth.fields.required',
  };

  protected readonly emailErrors: Record<string, string> = {
    required: 'auth.fields.required',
    email: 'auth.fields.email_invalid',
    emailTaken: 'auth.register.errors.emailTaken',
  };

  protected readonly passwordErrors: Record<string, string> = {
    required: 'auth.fields.required',
    minlength: 'auth.fields.password_too_short',
  };

  protected readonly confirmErrors: Record<string, string> = {
    required: 'auth.fields.required',
    passwordsMismatch: 'auth.reset.errors.passwordsMismatch',
  };

  private phoneCheckTimer: ReturnType<typeof setTimeout> | null = null;
  private lastCheckedPhone = '';
  private cooldownTimer: ReturnType<typeof setInterval> | null = null;

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.phoneForm = fb.group({
      phone: fb.control('', [Validators.required]),
    });
    this.otpForm = fb.group({
      code: fb.control('', [Validators.required, Validators.pattern(/^\d{4,6}$/)]),
    });
    this.detailsForm = fb.group(
      {
        first_name: fb.control('', [Validators.required]),
        last_name: fb.control('', [Validators.required]),
        email: fb.control('', [Validators.required, Validators.email]),
        password: fb.control('', [Validators.required, Validators.minLength(8)]),
        confirm_password: fb.control('', [Validators.required]),
      },
      { validators: [passwordsMatchValidator] },
    );

    /* Debounced phone-availability probe (best-effort UX). The Continue
       409 in onInitiate() remains the hard gate. */
    this.phoneForm.controls.phone.valueChanges.subscribe((value) => {
      this.phoneTaken.set(false);
      if (this.phoneCheckTimer !== null) clearTimeout(this.phoneCheckTimer);
      this.phoneCheckTimer = setTimeout(() => this.checkPhoneAvailability(value), 600);
    });
  }

  ngOnDestroy(): void {
    if (this.phoneCheckTimer !== null) clearTimeout(this.phoneCheckTimer);
    this.clearCooldown();
  }

  /* ---------- Step 1: phone availability + initiate ---------- */

  private checkPhoneAvailability(phone: string): void {
    if (this.phoneForm.controls.phone.invalid) return;
    if (phone === '' || phone === this.lastCheckedPhone) return;
    /* Fire-and-forget; never blocks, never toasts. */
    this.auth
      .validatePhone({ phone })
      .then((res) => {
        /* Ignore stale responses if the number changed mid-flight. */
        if (this.phoneForm.controls.phone.value !== phone) return;
        this.lastCheckedPhone = phone;
        this.phoneTaken.set(res.available === false);
      })
      .catch(() => {
        /* Best-effort: swallow; the Continue 409 still gates. */
      });
  }

  protected async onInitiate(): Promise<void> {
    this.phoneForm.markAllAsTouched();
    if (this.phoneForm.invalid || this.submitting()) return;

    const phone = this.phoneForm.controls.phone.value;
    this.submitting.set(true);
    try {
      const res = await this.auth.registerInitiate({
        phone,
        country_code: deriveCountryCode(phone),
      });
      this.phoneVerificationId.set(res.verification_id);
      this.fullPhone.set(phone);
      this.otpForm.reset({ code: '' });
      this.step.set(2);
      this.startCooldown();
      this.toast.success('auth.register.codeSent');
    } catch (err) {
      this.surfaceError(err, this.phoneForm, REGISTER_PHONE_ERROR_MAP);
    } finally {
      this.submitting.set(false);
    }
  }

  /* ---------- Step 2: verify phone ---------- */

  protected async onVerifyPhone(): Promise<void> {
    const vid = this.phoneVerificationId();
    if (vid === null) return;
    this.otpForm.markAllAsTouched();
    if (this.otpForm.invalid || this.submitting()) return;

    this.submitting.set(true);
    try {
      const res = await this.auth.registerVerifyPhone({
        verification_id: vid,
        code: this.otpForm.controls.code.value,
      });
      this.registrationToken.set(res.registration_token);
      this.otpForm.reset({ code: '' });
      this.step.set(3);
    } catch (err) {
      this.surfaceError(err, this.otpForm, REGISTER_OTP_ERROR_MAP);
    } finally {
      this.submitting.set(false);
    }
  }

  protected async onResendPhone(): Promise<void> {
    if (this.resendCooldown() > 0 || this.submitting()) return;
    this.submitting.set(true);
    try {
      const res = await this.auth.registerInitiate({
        phone: this.fullPhone(),
        country_code: deriveCountryCode(this.fullPhone()),
      });
      this.phoneVerificationId.set(res.verification_id);
      this.otpForm.reset({ code: '' });
      this.startCooldown();
      this.toast.success('auth.register.codeSent');
    } catch (err) {
      this.surfaceError(err, this.otpForm, REGISTER_PHONE_ERROR_MAP);
    } finally {
      this.submitting.set(false);
    }
  }

  protected changePhone(): void {
    this.step.set(1);
    this.otpForm.reset({ code: '' });
  }

  /* ---------- Step 3: details → submit ---------- */

  protected async onSubmitDetails(): Promise<void> {
    const token = this.registrationToken();
    if (token === null) {
      this.restartExpired();
      return;
    }
    this.detailsForm.markAllAsTouched();
    if (this.detailsForm.invalid || this.submitting()) return;

    this.submitting.set(true);
    try {
      const res = await this.auth.registerSubmit({
        registration_token: token,
        email: this.detailsForm.controls.email.value,
        password: this.detailsForm.controls.password.value,
        first_name: this.detailsForm.controls.first_name.value,
        last_name: this.detailsForm.controls.last_name.value,
      });
      this.emailVerificationId.set(res.verification_id);
      this.otpForm.reset({ code: '' });
      this.step.set(4);
      this.startCooldown();
      this.toast.success('auth.register.codeSent');
    } catch (err) {
      if (this.isExpiredToken(err)) {
        this.restartExpired();
        return;
      }
      this.surfaceError(err, this.detailsForm, REGISTER_SUBMIT_ERROR_MAP);
    } finally {
      this.submitting.set(false);
    }
  }

  /* ---------- Step 4: confirm email → auto-login ---------- */

  protected async onConfirmEmail(): Promise<void> {
    const vid = this.emailVerificationId();
    if (vid === null) return;
    this.otpForm.markAllAsTouched();
    if (this.otpForm.invalid || this.submitting()) return;

    this.submitting.set(true);
    try {
      await this.auth.registerConfirmEmail({
        verification_id: vid,
        code: this.otpForm.controls.code.value,
      });
      /* Auto-logged-in via the token-pair envelope. AuthService has
         applied state — navigate home. */
      await this.router.navigateByUrl('/');
    } catch (err) {
      this.surfaceError(err, this.otpForm, REGISTER_OTP_ERROR_MAP);
    } finally {
      this.submitting.set(false);
    }
  }

  protected async onResendEmail(): Promise<void> {
    const token = this.registrationToken();
    if (token === null) {
      this.restartExpired();
      return;
    }
    if (this.resendCooldown() > 0 || this.submitting()) return;
    this.submitting.set(true);
    try {
      const res = await this.auth.registerSubmit({
        registration_token: token,
        email: this.detailsForm.controls.email.value,
        password: this.detailsForm.controls.password.value,
        first_name: this.detailsForm.controls.first_name.value,
        last_name: this.detailsForm.controls.last_name.value,
      });
      this.emailVerificationId.set(res.verification_id);
      this.otpForm.reset({ code: '' });
      this.startCooldown();
      this.toast.success('auth.register.codeSent');
    } catch (err) {
      if (this.isExpiredToken(err)) {
        this.restartExpired();
        return;
      }
      this.surfaceError(err, this.detailsForm, REGISTER_SUBMIT_ERROR_MAP);
    } finally {
      this.submitting.set(false);
    }
  }

  protected backToDetails(): void {
    this.step.set(3);
    this.otpForm.reset({ code: '' });
  }

  /* ---------- Errors + helpers ---------- */

  private isExpiredToken(err: unknown): boolean {
    if (!(err instanceof Object)) return false;
    const body = (err as { error?: { error_code?: string; error?: { code?: string } } }).error;
    const code = body?.error_code ?? body?.error?.code;
    return code === AUTH_ERROR_CODES.INVALID_TOKEN;
  }

  private restartExpired(): void {
    this.step.set(1);
    this.registrationToken.set(null);
    this.phoneVerificationId.set(null);
    this.emailVerificationId.set(null);
    this.otpForm.reset({ code: '' });
    this.toast.error('auth.register.errors.sessionExpired');
  }

  private surfaceError(
    err: unknown,
    form: FormGroup,
    map: Record<string, ApiErrorMapping>,
  ): void {
    const result = mapApiErrors(err, form, map);
    if (result.isNetworkError) {
      this.toast.error('auth.login.errors.network');
      return;
    }
    if (result.unmapped.length === 0) return; /* per-field handled. */
    for (const code of result.unmapped) {
      if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
        this.toast.error('auth.register.errors.rateLimited');
      } else if (code === AUTH_ERROR_CODES.OTP_PROVIDER_ERROR) {
        this.toast.error('auth.register.errors.providerError');
      } else if (code === AUTH_ERROR_CODES.INVALID_TOKEN) {
        this.restartExpired();
      } else {
        this.toast.error('auth.register.errors.unexpected');
      }
    }
  }

  private startCooldown(): void {
    this.resendCooldown.set(RESEND_COOLDOWN_SECONDS);
    this.clearCooldown();
    this.cooldownTimer = setInterval(() => {
      const next = this.resendCooldown() - 1;
      if (next <= 0) {
        this.resendCooldown.set(0);
        this.clearCooldown();
      } else {
        this.resendCooldown.set(next);
      }
    }, 1000);
  }

  private clearCooldown(): void {
    if (this.cooldownTimer !== null) {
      clearInterval(this.cooldownTimer);
      this.cooldownTimer = null;
    }
  }
}

/**
 * Cross-field validator: confirm_password must equal password. Attaches
 * the error to confirm_password specifically. Mirrors reset-password.
 */
function passwordsMatchValidator(group: AbstractControl): ValidationErrors | null {
  const pass = group.get('password');
  const confirm = group.get('confirm_password');
  if (pass === null || confirm === null) return null;
  if (confirm.value === '' || pass.value === '') return null;

  if (pass.value !== confirm.value) {
    const existing = confirm.errors ?? {};
    confirm.setErrors({ ...existing, passwordsMismatch: true });
    return { passwordsMismatch: true };
  }
  if (confirm.errors !== null) {
    const { passwordsMismatch: _removed, ...rest } = confirm.errors;
    void _removed;
    confirm.setErrors(Object.keys(rest).length > 0 ? rest : null);
  }
  return null;
}

/**
 * ISO 3166-1 alpha-2 from an E.164 number; longest-prefix match, UAE
 * default. Inlined (also in login.ts) to keep the page from reaching into
 * PhoneInputComponent internals.
 */
function deriveCountryCode(e164: string): string {
  const sorted = [...PHONE_COUNTRIES].sort((a, b) => b.dialCode.length - a.dialCode.length);
  for (const country of sorted) {
    if (e164.startsWith(country.dialCode)) {
      return country.code;
    }
  }
  return 'AE';
}

const REGISTER_PHONE_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.CONFLICT_PHONE_TAKEN]: { field: 'phone', key: 'phoneTaken' },
  [AUTH_ERROR_CODES.OTP_RATE_LIMITED]: { field: null, key: 'rateLimited' },
  [AUTH_ERROR_CODES.OTP_PROVIDER_ERROR]: { field: null, key: 'providerError' },
};

const REGISTER_OTP_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.OTP_INVALID_CODE]: { field: 'code', key: 'invalidCode' },
  [AUTH_ERROR_CODES.OTP_VERIFICATION_FAILED]: { field: 'code', key: 'invalidCode' },
  [AUTH_ERROR_CODES.OTP_RATE_LIMITED]: { field: null, key: 'rateLimited' },
};

const REGISTER_SUBMIT_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.CONFLICT_EMAIL_TAKEN]: { field: 'email', key: 'emailTaken' },
  [AUTH_ERROR_CODES.OTP_RATE_LIMITED]: { field: null, key: 'rateLimited' },
  [AUTH_ERROR_CODES.OTP_PROVIDER_ERROR]: { field: null, key: 'providerError' },
};
