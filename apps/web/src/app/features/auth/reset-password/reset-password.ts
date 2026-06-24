import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
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
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthService } from '../../../core/auth/auth.service';
import {
  FormFieldComponent,
  PasswordStrengthComponent,
  mapApiErrors,
  readRetryAfterSeconds,
  ApiErrorMapping,
  ToastService,
} from '../../../shared/forms';
import { AUTH_ERROR_CODES } from '../../../core/auth/auth.types';

/** Resend cooldown — matches the other OTP surfaces. */
const RESEND_COOLDOWN_SECONDS = 30;

/**
 * Reset password page — /reset-password.
 *
 * Receives query params:
 *   verification_id  REQUIRED — from /forgot-password
 *   email            OPTIONAL — for display context
 *
 * Form
 * ----
 *   - code            6-digit OTP (Validators.pattern + required)
 *   - new_password    required + minLength 8
 *   - confirm_password required + matches new_password
 *
 * The new_password field renders a PasswordStrengthComponent meter
 * below it (same as register page).
 *
 * Submit flow
 * -----------
 *   1. AuthService.confirmPasswordReset({ verification_id, code, new_password })
 *   2. On success the API issues tokens (auto-login). Navigate to '/'.
 *   3. On OTP_INVALID_CODE → inline error on the code field. This
 *      also fires for the anti-enumeration 'fake-' verification_id
 *      path — the user can't tell whether the email was registered.
 *   4. On VALIDATION_FAILED → per-field errors via mapApiErrors.
 *   5. On any other code → toast.
 *
 * passwordsMatchValidator
 * -----------------------
 * Group-level validator that returns { passwordsMismatch: true } on
 * the confirm field when the two don't match. Attaches the error to
 * confirm_password (not new_password) so the inline error renders
 * under the confirm field — closer to where the user notices the
 * discrepancy.
 */
@Component({
  selector: 'app-reset-password',
  standalone: true,
  imports: [
    NgIf,
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
    FormFieldComponent,
    PasswordStrengthComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="auth-page" data-testid="reset-page">
      <div class="auth-card">
        <h1 class="auth-card__title">{{ 'auth.reset.title' | translate }}</h1>
        <p class="auth-card__subtitle">
          {{ 'auth.reset.subtitle' | translate : { destination: destination() || '' } }}
        </p>

        <ng-container *ngIf="hasVerificationId(); else missingVerification">
          <form
            [formGroup]="form"
            (ngSubmit)="onSubmit()"
            novalidate
            class="auth-form"
            data-testid="reset-form"
          >
            <ui-form-field
              [label]="'auth.reset.codeLabel'"
              fieldId="reset-code"
              [required]="true"
              [control]="form.controls.code"
              [errorMap]="codeErrors"
            >
              <input
                id="reset-code"
                type="text"
                autocomplete="one-time-code"
                inputmode="numeric"
                maxlength="6"
                spellcheck="false"
                formControlName="code"
                class="auth-input auth-input--code"
                data-testid="reset-code"
              />
            </ui-form-field>

            <ui-form-field
              [label]="'auth.reset.newPasswordLabel'"
              fieldId="reset-new-password"
              [required]="true"
              [helper]="'auth.register.passwordHint'"
              [control]="form.controls.new_password"
              [errorMap]="passwordErrors"
            >
              <input
                id="reset-new-password"
                type="password"
                autocomplete="new-password"
                formControlName="new_password"
                class="auth-input"
                data-testid="reset-new-password"
              />
            </ui-form-field>
            <ui-password-strength
              [password]="form.controls.new_password.value"
              data-testid="reset-password-strength"
            ></ui-password-strength>

            <ui-form-field
              [label]="'auth.reset.confirmPasswordLabel'"
              fieldId="reset-confirm-password"
              [required]="true"
              [control]="form.controls.confirm_password"
              [errorMap]="confirmErrors"
            >
              <input
                id="reset-confirm-password"
                type="password"
                autocomplete="new-password"
                formControlName="confirm_password"
                class="auth-input"
                data-testid="reset-confirm-password"
              />
            </ui-form-field>

            <button
              type="submit"
              class="auth-submit"
              [disabled]="submitting() || lockedOut()"
              data-testid="reset-submit"
            >
              {{ (submitting() ? 'common.loading' : 'auth.reset.submit') | translate }}
            </button>
          </form>

          <p
            *ngIf="lockedOut()"
            class="auth-field-hint auth-field-hint--warn"
            role="alert"
            data-testid="reset-lockout"
          >
            {{ 'auth.lockout.countdown' | translate : { seconds: resendCooldown() } }}
          </p>

          <div class="auth-resend" *ngIf="canResend()">
            <p class="auth-resend__prompt">{{ 'auth.reset.resendCta' | translate }}</p>
            <button
              type="button"
              class="auth-link auth-resend__button"
              [disabled]="resendCooldown() > 0 || resending()"
              [attr.aria-disabled]="resendCooldown() > 0 || resending()"
              (click)="onResend()"
              data-testid="reset-resend"
            >
              <ng-container *ngIf="resendCooldown() > 0; else readyResend">
                {{ 'auth.reset.resendCooldown' | translate : { seconds: resendCooldown() } }}
              </ng-container>
              <ng-template #readyResend>{{ 'auth.reset.resendButton' | translate }}</ng-template>
            </button>
          </div>
        </ng-container>

        <ng-template #missingVerification>
          <p class="auth-error-banner" data-testid="reset-missing-vid" role="alert">
            {{ 'auth.reset.errors.missingVerification' | translate }}
          </p>
          <a routerLink="/forgot-password" class="auth-link auth-link--strong">
            {{ 'auth.reset.errors.startOver' | translate }}
          </a>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: '../verify-phone/verify-phone.scss',
})
export class ResetPasswordComponent implements OnInit, OnDestroy {
  protected readonly form: FormGroup<{
    code: FormControl<string>;
    new_password: FormControl<string>;
    confirm_password: FormControl<string>;
  }>;

  protected readonly submitting = signal(false);
  protected readonly resending = signal(false);
  protected readonly resendCooldown = signal(0);
  /**
   * True while a server-reflected OTP lockout (429 OTP_RATE_LIMITED) is in
   * effect. Reuses resendCooldown for the countdown but ALSO disables Submit
   * (and Resend) for the retry_after window.
   */
  protected readonly lockedOut = signal(false);
  /** Email or phone the code was sent to — shown for context only. */
  protected readonly destination = signal<string | null>(null);
  /** Channel + destination let us re-issue the reset code (resend). */
  private readonly channel = signal<'email' | 'phone' | null>(null);
  private readonly verificationId = signal<string | null>(null);

  private cooldownTimer: ReturnType<typeof setInterval> | null = null;

  protected readonly codeErrors: Record<string, string> = {
    required: 'auth.fields.required',
    pattern: 'auth.validation.code6Digits',
    invalidCode: 'auth.reset.errors.invalidCode',
  };

  protected readonly passwordErrors: Record<string, string> = {
    required: 'auth.fields.required',
    minlength: 'auth.fields.password_too_short',
  };

  protected readonly confirmErrors: Record<string, string> = {
    required: 'auth.fields.required',
    passwordsMismatch: 'auth.reset.errors.passwordsMismatch',
  };

  protected hasVerificationId(): boolean {
    return this.verificationId() !== null;
  }

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly toast = inject(ToastService);

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group(
      {
        code: fb.control('', [Validators.required, Validators.pattern(/^\d{4,6}$/)]),
        new_password: fb.control('', [Validators.required, Validators.minLength(8)]),
        confirm_password: fb.control('', [Validators.required]),
      },
      { validators: [passwordsMatchValidator] },
    );
  }

  ngOnInit(): void {
    const params = this.route.snapshot.queryParamMap;
    const vid = params.get('verification_id');
    /* New two-channel flow (#8) passes `destination`; keep `email` as a
       fallback for any old in-flight links. */
    const dest = params.get('destination') ?? params.get('email');
    const channel = params.get('channel');
    if (vid !== null) this.verificationId.set(vid);
    if (dest !== null) this.destination.set(dest);
    if (channel === 'email' || channel === 'phone') this.channel.set(channel);
  }

  ngOnDestroy(): void {
    this.clearCooldown();
  }

  /**
   * Resend is only possible when we know both the channel and destination
   * (carried as query params from /forgot-password). Old links that lack
   * them simply hide the resend affordance.
   */
  protected canResend(): boolean {
    return this.channel() !== null && (this.destination() ?? '').length > 0;
  }

  /** Re-issue the reset code via the same channel + destination. */
  protected async onResend(): Promise<void> {
    if (this.resendCooldown() > 0 || this.resending()) return;
    const channel = this.channel();
    const dest = this.destination();
    if (channel === null || dest === null || dest.length === 0) return;

    this.resending.set(true);
    try {
      const res = await this.auth.requestPasswordResetChannel(
        channel === 'phone' ? { channel: 'phone', phone: dest } : { channel: 'email', email: dest },
      );
      this.verificationId.set(res.verification_id);
      this.startCooldown();
      this.toast.success('auth.reset.codeSent');
    } catch (err) {
      const result = mapApiErrors(err, this.form, RESET_ERROR_MAP);
      if (result.isNetworkError) {
        this.toast.error('auth.login.errors.network');
      } else if (result.unmapped.includes(AUTH_ERROR_CODES.OTP_RATE_LIMITED)) {
        this.toast.error('auth.reset.errors.rateLimited');
        this.startLockout(err);
      } else {
        this.toast.error('auth.reset.errors.unexpected');
        this.startCooldown();
      }
    } finally {
      this.resending.set(false);
    }
  }

  protected async onSubmit(): Promise<void> {
    const vid = this.verificationId();
    if (vid === null) return;

    this.form.markAllAsTouched();
    if (this.form.invalid || this.submitting()) return;

    this.submitting.set(true);
    try {
      await this.auth.confirmPasswordReset({
        verification_id: vid,
        code: this.form.controls.code.value,
        new_password: this.form.controls.new_password.value,
      });
      /* Auto-logged-in via the API's token response. AuthService has
         applied state. Navigate to home. */
      await this.router.navigateByUrl('/');
    } catch (err) {
      const result = mapApiErrors(err, this.form, RESET_ERROR_MAP);
      if (result.isNetworkError) {
        this.toast.error('auth.login.errors.network');
      } else if (result.unmapped.length > 0) {
        for (const code of result.unmapped) {
          if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
            this.toast.error('auth.reset.errors.rateLimited');
            this.startLockout(err);
          } else {
            this.toast.error('auth.reset.errors.unexpected');
          }
        }
      }
    } finally {
      this.submitting.set(false);
    }
  }

  /* ---------- Cooldown ---------- */

  private startCooldown(seconds: number = RESEND_COOLDOWN_SECONDS): void {
    this.resendCooldown.set(seconds);
    this.clearCooldown();
    this.cooldownTimer = setInterval(() => {
      const next = this.resendCooldown() - 1;
      if (next <= 0) {
        this.resendCooldown.set(0);
        this.lockedOut.set(false);
        this.clearCooldown();
      } else {
        this.resendCooldown.set(next);
      }
    }, 1000);
  }

  /**
   * Reflect a server OTP lockout (429 OTP_RATE_LIMITED): disable Resend AND
   * Submit for `retry_after` seconds (from the 429 body; ~60s fallback),
   * reusing the resend countdown. Never shortens an in-flight longer lockout.
   */
  private startLockout(err: unknown): void {
    const seconds = Math.max(readRetryAfterSeconds(err), this.resendCooldown());
    this.lockedOut.set(true);
    this.startCooldown(seconds);
  }

  private clearCooldown(): void {
    if (this.cooldownTimer !== null) {
      clearInterval(this.cooldownTimer);
      this.cooldownTimer = null;
    }
  }
}

/**
 * Cross-field validator: confirm_password must equal new_password.
 *
 * Attaches the error to confirm_password specifically (not the
 * group) so the inline error renders under the right field. We
 * preserve any existing errors on confirm_password (e.g. required)
 * by merging.
 */
function passwordsMatchValidator(group: AbstractControl): ValidationErrors | null {
  const newPass = group.get('new_password');
  const confirm = group.get('confirm_password');
  if (newPass === null || confirm === null) return null;
  if (confirm.value === '' || newPass.value === '') return null; /* required handles emptiness */

  if (newPass.value !== confirm.value) {
    /* Merge — don't clobber other errors. */
    const existing = confirm.errors ?? {};
    confirm.setErrors({ ...existing, passwordsMismatch: true });
    return { passwordsMismatch: true };
  }
  /* If they match and the only error WAS passwordsMismatch, clear it. */
  if (confirm.errors !== null) {
    const { passwordsMismatch: _removed, ...rest } = confirm.errors;
    void _removed;
    confirm.setErrors(Object.keys(rest).length > 0 ? rest : null);
  }
  return null;
}

const RESET_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.OTP_INVALID_CODE]: { field: 'code', key: 'invalidCode' },
  [AUTH_ERROR_CODES.OTP_VERIFICATION_FAILED]: { field: 'code', key: 'invalidCode' },
  [AUTH_ERROR_CODES.OTP_RATE_LIMITED]: { field: null, key: 'rateLimited' },
};
