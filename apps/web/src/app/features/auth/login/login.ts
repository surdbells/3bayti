import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnDestroy,
} from '@angular/core';
import { NgIf } from '@angular/common';
import {
  FormBuilder,
  FormGroup,
  FormControl,
  Validators,
  ReactiveFormsModule,
} from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthService } from '../../../core/auth/auth.service';
import {
  FormFieldComponent,
  PhoneInputComponent,
  PHONE_COUNTRIES,
  mapApiErrors,
  ApiErrorMapping,
  ToastService,
} from '../../../shared/forms';
import { AUTH_ERROR_CODES } from '../../../core/auth/auth.types';

const RESEND_COOLDOWN_SECONDS = 30;

/**
 * Login page — /login. PASSWORDLESS-FIRST redesign (web parity with the
 * mobile passwordless login, issue #8).
 *
 * A single page hosting several view states (no router sub-pages), driven
 * by the `view` signal. Mirrors apps/mobile/src/app/public/login.
 *
 *   'choice'    How would you like to log in? → two options: Phone / Email.
 *   'phone'     PhoneInputComponent. Next → AuthService.sendOtpLogin
 *               ({ channel:'phone', country_code, phone }) → verification_id
 *               → 'otp'.
 *   'email'     Email field. Next → AuthService.sendOtpLogin
 *               ({ channel:'email', email }) → verification_id → 'otp'.
 *   'otp'       6-digit code input + resend + destination label.
 *               Verify → AuthService.verifyOtpLogin({ verification_id, code })
 *               → SAME envelope as /login → applyAuthState → navigate.
 *   'password'  Legacy email + password form (PRESERVED). Reached via the
 *               "Log in with password instead" link. Submit →
 *               AuthService.login → applyAuthState → navigate.
 *
 * Anti-enumeration: /auth/otp-login/send always returns a verification_id,
 * even for an unknown user, so the UI never reveals whether an account
 * exists; a bad/expired code only fails at the verify step.
 *
 * returnUrl handling
 * ------------------
 * Same open-redirect defense as the old page: in-app paths only (single
 * leading slash, NOT '//host' protocol-relative).
 */
@Component({
  selector: 'app-login',
  standalone: true,
  imports: [
    NgIf,
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
    FormFieldComponent,
    PhoneInputComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="auth-page" data-testid="login-page">
      <div class="auth-card">
        <a routerLink="/" class="auth-card__brand" [attr.aria-label]="'common.brand' | translate">
          <span class="brand-logo brand-logo--wordmark" aria-hidden="true"></span>
        </a>

        <!-- ===== View: choice ===== -->
        <ng-container *ngIf="view() === 'choice'">
          <h1 class="auth-card__title">{{ 'auth.login.title' | translate }}</h1>
          <p class="auth-card__subtitle">{{ 'auth.login.choiceSubtitle' | translate }}</p>

          <div class="auth-choice" data-testid="login-choice">
            <button
              type="button"
              class="auth-choice__option"
              (click)="goPhone()"
              data-testid="login-choice-phone"
            >
              <span class="auth-choice__label">{{ 'auth.login.choicePhone' | translate }}</span>
              <span class="auth-choice__hint">{{ 'auth.login.choicePhoneHint' | translate }}</span>
            </button>
            <button
              type="button"
              class="auth-choice__option"
              (click)="goEmail()"
              data-testid="login-choice-email"
            >
              <span class="auth-choice__label">{{ 'auth.login.choiceEmail' | translate }}</span>
              <span class="auth-choice__hint">{{ 'auth.login.choiceEmailHint' | translate }}</span>
            </button>
          </div>
        </ng-container>

        <!-- ===== View: phone ===== -->
        <ng-container *ngIf="view() === 'phone'">
          <h1 class="auth-card__title">{{ 'auth.login.phoneTitle' | translate }}</h1>
          <p class="auth-card__subtitle">{{ 'auth.login.phoneSubtitle' | translate }}</p>

          <form
            [formGroup]="phoneForm"
            (ngSubmit)="onSendPhoneOtp()"
            novalidate
            class="auth-form"
            data-testid="login-phone-form"
          >
            <ui-form-field
              [label]="'auth.login.phoneLabel'"
              fieldId="login-phone"
              [required]="true"
              [control]="phoneForm.controls.phone"
              [errorMap]="phoneErrors"
            >
              <ui-phone-input
                inputId="login-phone"
                formControlName="phone"
                data-testid="login-phone"
              ></ui-phone-input>
            </ui-form-field>

            <button
              type="submit"
              class="auth-submit"
              [disabled]="sending()"
              data-testid="login-phone-submit"
            >
              {{ (sending() ? 'common.loading' : 'auth.login.sendCode') | translate }}
            </button>
          </form>

          <p class="auth-card__footer">
            <button type="button" class="auth-link" (click)="goChoice()" data-testid="login-phone-back">
              {{ 'auth.login.backToChoice' | translate }}
            </button>
          </p>
        </ng-container>

        <!-- ===== View: email ===== -->
        <ng-container *ngIf="view() === 'email'">
          <h1 class="auth-card__title">{{ 'auth.login.emailTitle' | translate }}</h1>
          <p class="auth-card__subtitle">{{ 'auth.login.emailSubtitle' | translate }}</p>

          <form
            [formGroup]="emailForm"
            (ngSubmit)="onSendEmailOtp()"
            novalidate
            class="auth-form"
            data-testid="login-email-form"
          >
            <ui-form-field
              [label]="'auth.login.emailLabel'"
              fieldId="login-email"
              [required]="true"
              [control]="emailForm.controls.email"
              [errorMap]="emailErrors"
            >
              <input
                id="login-email"
                type="email"
                autocomplete="email"
                inputmode="email"
                spellcheck="false"
                formControlName="email"
                class="auth-input"
                data-testid="login-email"
              />
            </ui-form-field>

            <button
              type="submit"
              class="auth-submit"
              [disabled]="sending()"
              data-testid="login-email-submit"
            >
              {{ (sending() ? 'common.loading' : 'auth.login.sendCode') | translate }}
            </button>
          </form>

          <div class="auth-form__row auth-form__row--center">
            <button type="button" class="auth-link" (click)="goPassword()" data-testid="login-use-password">
              {{ 'auth.login.usePasswordInstead' | translate }}
            </button>
          </div>

          <p class="auth-card__footer">
            <button type="button" class="auth-link" (click)="goChoice()" data-testid="login-email-back">
              {{ 'auth.login.backToChoice' | translate }}
            </button>
          </p>
        </ng-container>

        <!-- ===== View: otp ===== -->
        <ng-container *ngIf="view() === 'otp'">
          <h1 class="auth-card__title">{{ 'auth.login.otpTitle' | translate }}</h1>
          <p class="auth-card__subtitle">
            {{ 'auth.login.otpSubtitle' | translate : { destination: otpDestination() } }}
          </p>

          <form
            [formGroup]="otpForm"
            (ngSubmit)="onVerifyOtp()"
            novalidate
            class="auth-form"
            data-testid="login-otp-form"
          >
            <ui-form-field
              [label]="'auth.login.codeLabel'"
              fieldId="login-otp"
              [required]="true"
              [control]="otpForm.controls.code"
              [errorMap]="codeErrors"
            >
              <input
                id="login-otp"
                type="text"
                autocomplete="one-time-code"
                inputmode="numeric"
                maxlength="6"
                spellcheck="false"
                formControlName="code"
                class="auth-input auth-input--code"
                data-testid="login-otp"
              />
            </ui-form-field>

            <button
              type="submit"
              class="auth-submit"
              [disabled]="submitting()"
              data-testid="login-otp-submit"
            >
              {{ (submitting() ? 'common.loading' : 'auth.login.verify') | translate }}
            </button>
          </form>

          <div class="auth-resend">
            <p class="auth-resend__prompt">{{ 'auth.login.resendCta' | translate }}</p>
            <button
              type="button"
              class="auth-link auth-resend__button"
              [disabled]="resendCooldown() > 0 || sending()"
              [attr.aria-disabled]="resendCooldown() > 0 || sending()"
              (click)="onResend()"
              data-testid="login-otp-resend"
            >
              <ng-container *ngIf="resendCooldown() > 0; else readyToResend">
                {{ 'auth.login.resendCooldown' | translate : { seconds: resendCooldown() } }}
              </ng-container>
              <ng-template #readyToResend>{{ 'auth.login.resendButton' | translate }}</ng-template>
            </button>
          </div>

          <p class="auth-card__footer">
            <button type="button" class="auth-link" (click)="changeDestination()" data-testid="login-otp-change">
              {{ 'auth.login.changeDestination' | translate }}
            </button>
          </p>
        </ng-container>

        <!-- ===== View: password (legacy fallback) ===== -->
        <ng-container *ngIf="view() === 'password'">
          <h1 class="auth-card__title">{{ 'auth.login.title' | translate }}</h1>
          <p class="auth-card__subtitle">{{ 'auth.login.subtitle' | translate }}</p>

          <form
            [formGroup]="passwordForm"
            (ngSubmit)="onSubmitPassword()"
            novalidate
            class="auth-form"
            data-testid="login-form"
          >
            <ui-form-field
              [label]="'auth.login.emailLabel'"
              fieldId="login-pw-email"
              [required]="true"
              [control]="passwordForm.controls.email"
              [errorMap]="passwordEmailErrors"
            >
              <input
                id="login-pw-email"
                type="email"
                autocomplete="email"
                inputmode="email"
                spellcheck="false"
                formControlName="email"
                class="auth-input"
                data-testid="login-pw-email"
              />
            </ui-form-field>

            <ui-form-field
              [label]="'auth.login.passwordLabel'"
              fieldId="login-password"
              [required]="true"
              [control]="passwordForm.controls.password"
              [errorMap]="passwordErrors"
            >
              <input
                id="login-password"
                type="password"
                autocomplete="current-password"
                formControlName="password"
                class="auth-input"
                data-testid="login-password"
              />
            </ui-form-field>

            <div class="auth-form__row">
              <a routerLink="/forgot-password" class="auth-link">
                {{ 'auth.login.forgotPassword' | translate }}
              </a>
            </div>

            <button
              type="submit"
              class="auth-submit"
              [disabled]="submitting()"
              data-testid="login-submit"
            >
              {{ (submitting() ? 'common.loading' : 'auth.login.submit') | translate }}
            </button>
          </form>

          <div class="auth-form__row auth-form__row--center">
            <button type="button" class="auth-link" (click)="goChoice()" data-testid="login-use-otp">
              {{ 'auth.login.useOtpInstead' | translate }}
            </button>
          </div>
        </ng-container>

        <p class="auth-card__footer">
          {{ 'auth.login.noAccount' | translate }}
          <a routerLink="/register" class="auth-link auth-link--strong">
            {{ 'auth.login.registerLink' | translate }}
          </a>
        </p>
      </div>
    </main>
  `,
  styleUrl: './login.scss',
})
export class LoginComponent implements OnDestroy {
  protected readonly view = signal<'choice' | 'phone' | 'email' | 'otp' | 'password'>('choice');

  protected readonly sending = signal(false);
  protected readonly submitting = signal(false);
  protected readonly resendCooldown = signal(0);

  /** Channel of the in-flight OTP, and a human-readable destination label. */
  private readonly otpChannel = signal<'phone' | 'email'>('phone');
  protected readonly otpDestination = computed(() =>
    this.otpChannel() === 'phone'
      ? this.phoneForm.controls.phone.value
      : this.emailForm.controls.email.value,
  );
  /** verification_id issued by send, consumed by verify. */
  private readonly verificationId = signal<string | null>(null);

  protected readonly phoneForm: FormGroup<{ phone: FormControl<string> }>;
  protected readonly emailForm: FormGroup<{ email: FormControl<string> }>;
  protected readonly otpForm: FormGroup<{ code: FormControl<string> }>;
  protected readonly passwordForm: FormGroup<{
    email: FormControl<string>;
    password: FormControl<string>;
  }>;

  protected readonly phoneErrors: Record<string, string> = {
    required: 'auth.fields.required',
    phoneInvalid: 'auth.fields.phone_invalid',
  };

  protected readonly emailErrors: Record<string, string> = {
    required: 'auth.fields.required',
    email: 'auth.fields.email_invalid',
  };

  protected readonly codeErrors: Record<string, string> = {
    required: 'auth.fields.required',
    pattern: 'auth.validation.code6Digits',
    invalidCode: 'auth.login.errors.invalidCode',
  };

  protected readonly passwordEmailErrors: Record<string, string> = {
    required: 'auth.fields.required',
    email: 'auth.fields.email_invalid',
    invalidCredentials: 'auth.login.errors.invalidCredentials',
    accountInactive: 'auth.login.errors.accountInactive',
  };

  protected readonly passwordErrors: Record<string, string> = {
    required: 'auth.fields.required',
    invalidCredentials: 'auth.login.errors.invalidCredentials',
  };

  private cooldownTimer: ReturnType<typeof setInterval> | null = null;

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly toast = inject(ToastService);

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.phoneForm = fb.group({
      phone: fb.control('', [Validators.required]),
    });
    this.emailForm = fb.group({
      email: fb.control('', [Validators.required, Validators.email]),
    });
    this.otpForm = fb.group({
      code: fb.control('', [Validators.required, Validators.pattern(/^\d{4,6}$/)]),
    });
    this.passwordForm = fb.group({
      email: fb.control('', [Validators.required, Validators.email]),
      password: fb.control('', [Validators.required]),
    });
  }

  ngOnDestroy(): void {
    this.clearCooldown();
  }

  /* ---------- View navigation ---------- */

  protected goChoice(): void {
    this.view.set('choice');
    this.otpForm.reset({ code: '' });
  }
  protected goPhone(): void {
    this.view.set('phone');
  }
  protected goEmail(): void {
    this.view.set('email');
  }
  protected goPassword(): void {
    this.view.set('password');
  }

  /** Back from OTP-entry to the relevant entry screen. */
  protected changeDestination(): void {
    this.view.set(this.otpChannel() === 'phone' ? 'phone' : 'email');
    this.otpForm.reset({ code: '' });
  }

  /* ---------- Step: send OTP ---------- */

  protected async onSendPhoneOtp(): Promise<void> {
    this.phoneForm.markAllAsTouched();
    if (this.phoneForm.invalid || this.sending()) return;

    const phone = this.phoneForm.controls.phone.value;
    await this.sendOtp(
      { channel: 'phone', country_code: deriveCountryCode(phone), phone },
      'phone',
    );
  }

  protected async onSendEmailOtp(): Promise<void> {
    this.emailForm.markAllAsTouched();
    if (this.emailForm.invalid || this.sending()) return;

    await this.sendOtp(
      { channel: 'email', email: this.emailForm.controls.email.value },
      'email',
    );
  }

  /** Re-issue the OTP (re-call send for the active channel). */
  protected async onResend(): Promise<void> {
    if (this.resendCooldown() > 0 || this.sending()) return;
    if (this.otpChannel() === 'phone') {
      const phone = this.phoneForm.controls.phone.value;
      await this.sendOtp(
        { channel: 'phone', country_code: deriveCountryCode(phone), phone },
        'phone',
        true,
      );
    } else {
      await this.sendOtp(
        { channel: 'email', email: this.emailForm.controls.email.value },
        'email',
        true,
      );
    }
  }

  /**
   * Shared send path. On success: stash verification_id, switch to the OTP
   * view, start the resend cooldown, toast. `isResend` keeps us on the OTP
   * view (we're already there) and only refreshes the verification_id.
   */
  private async sendOtp(
    input:
      | { channel: 'phone'; country_code: string; phone: string }
      | { channel: 'email'; email: string },
    channel: 'phone' | 'email',
    isResend = false,
  ): Promise<void> {
    this.sending.set(true);
    try {
      const res = await this.auth.sendOtpLogin(input);
      this.verificationId.set(res.verification_id);
      this.otpChannel.set(channel);
      if (!isResend) {
        this.otpForm.reset({ code: '' });
        this.view.set('otp');
      }
      this.startCooldown();
      this.toast.success('auth.login.codeSent');
    } catch (err) {
      this.surfaceSendError(err, channel === 'phone' ? this.phoneForm : this.emailForm);
    } finally {
      this.sending.set(false);
    }
  }

  private surfaceSendError(err: unknown, form: FormGroup): void {
    const result = mapApiErrors(err, form, OTP_SEND_ERROR_MAP);
    if (result.isNetworkError) {
      this.toast.error('auth.login.errors.network');
    } else if (result.unmapped.length > 0) {
      for (const code of result.unmapped) {
        if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
          this.toast.error('auth.login.errors.rateLimited');
        } else if (code === AUTH_ERROR_CODES.OTP_PROVIDER_ERROR) {
          this.toast.error('auth.login.errors.providerError');
        } else {
          this.toast.error('auth.login.errors.unexpected');
        }
      }
    }
  }

  /* ---------- Step: verify OTP → login ---------- */

  protected async onVerifyOtp(): Promise<void> {
    const vid = this.verificationId();
    if (vid === null) return;

    this.otpForm.markAllAsTouched();
    if (this.otpForm.invalid || this.submitting()) return;

    this.submitting.set(true);
    try {
      await this.auth.verifyOtpLogin({ verification_id: vid, code: this.otpForm.controls.code.value });
      await this.navigateAfterLogin();
    } catch (err) {
      const result = mapApiErrors(err, this.otpForm, OTP_VERIFY_ERROR_MAP);
      if (result.isNetworkError) {
        this.toast.error('auth.login.errors.network');
      } else if (result.unmapped.length > 0) {
        for (const code of result.unmapped) {
          if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
            this.toast.error('auth.login.errors.rateLimited');
          } else {
            this.toast.error('auth.login.errors.unexpected');
          }
        }
      }
    } finally {
      this.submitting.set(false);
    }
  }

  /* ---------- Legacy email + password (preserved) ---------- */

  protected async onSubmitPassword(): Promise<void> {
    this.passwordForm.markAllAsTouched();
    if (this.passwordForm.invalid || this.submitting()) return;

    this.submitting.set(true);
    try {
      await this.auth.login({
        email: this.passwordForm.controls.email.value,
        password: this.passwordForm.controls.password.value,
      });
      await this.navigateAfterLogin();
    } catch (err) {
      const result = mapApiErrors(err, this.passwordForm, LOGIN_ERROR_MAP);
      if (result.isNetworkError) {
        this.toast.error('auth.login.errors.network');
      } else if (result.unmapped.length > 0) {
        this.toast.error('auth.login.errors.unexpected');
      }
    } finally {
      this.submitting.set(false);
    }
  }

  /* ---------- Cooldown ---------- */

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

  /* ---------- Navigation ---------- */

  private async navigateAfterLogin(): Promise<void> {
    const returnUrl = this.route.snapshot.queryParamMap.get('returnUrl');
    const safeReturn = this.isSafeInAppPath(returnUrl) ? returnUrl : '/';
    await this.router.navigateByUrl(safeReturn);
  }

  private isSafeInAppPath(value: string | null): value is string {
    if (value === null || value.length === 0) return false;
    if (value[0] !== '/') return false;
    if (value.length > 1 && value[1] === '/') return false;
    return true;
  }
}

/**
 * Pull the ISO 3166-1 alpha-2 country code out of an E.164 number by
 * matching against PHONE_COUNTRIES. Longest-prefix match wins so '+971'
 * beats '+97'. Defaults to UAE if unmatched. Inlined (also in register.ts)
 * to keep the page from reaching into PhoneInputComponent internals.
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

const OTP_SEND_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.OTP_RATE_LIMITED]: { field: null, key: 'rateLimited' },
  [AUTH_ERROR_CODES.OTP_PROVIDER_ERROR]: { field: null, key: 'providerError' },
};

const OTP_VERIFY_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.OTP_INVALID_CODE]: { field: 'code', key: 'invalidCode' },
  [AUTH_ERROR_CODES.OTP_VERIFICATION_FAILED]: { field: 'code', key: 'invalidCode' },
  [AUTH_ERROR_CODES.OTP_RATE_LIMITED]: { field: null, key: 'rateLimited' },
};

/**
 * Error-code → form-field mapping for the legacy password login.
 */
const LOGIN_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.INVALID_CREDENTIALS]: { field: 'email', key: 'invalidCredentials' },
  [AUTH_ERROR_CODES.ACCOUNT_INACTIVE]: { field: 'email', key: 'accountInactive' },
};
