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
} from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthService } from '../../../core/auth/auth.service';
import {
  FormFieldComponent,
  PhoneInputComponent,
  mapApiErrors,
  readRetryAfterSeconds,
  ApiErrorMapping,
  ToastService,
} from '../../../shared/forms';
import { AUTH_ERROR_CODES } from '../../../core/auth/auth.types';

/**
 * UX submit cooldown, blocks back-to-back reset requests. Matches the 30s
 * resend cooldown used across the other OTP surfaces (login/register/verify).
 */
const RESEND_COOLDOWN_SECONDS = 30;

/**
 * Forgot password page, /forgot-password. TWO-CHANNEL reset request (web
 * parity with the mobile reset flow, issue #8).
 *
 * A channel toggle (Email | Phone) drives which identifier the user enters:
 *   Email mode → email field.
 *   Phone mode → PhoneInputComponent.
 * [Send code] → AuthService.requestPasswordResetChannel({ channel, ... }) →
 *   verification_id → navigate to /reset-password.
 *
 * Anti-enumeration protocol
 * -------------------------
 * /v3/auth/reset ALWAYS returns 200 with a verification_id, even when the
 * identifier isn't registered (the API uses a 'fake-' prefix). The frontend
 * MUST NOT distinguish: we route to /reset-password regardless, and the OTP
 * confirm step fails the same way as a bad code.
 */
@Component({
  selector: 'app-forgot-password',
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
    <main class="auth-page" data-testid="forgot-page">
      <div class="auth-card">
        <h1 class="auth-card__title">{{ 'auth.forgot.title' | translate }}</h1>
        <p class="auth-card__subtitle">{{ 'auth.forgot.channelSubtitle' | translate }}</p>

        <div class="auth-segment" role="tablist" data-testid="forgot-channel">
          <button
            type="button"
            role="tab"
            class="auth-segment__btn"
            [class.auth-segment__btn--active]="channel() === 'email'"
            [attr.aria-selected]="channel() === 'email'"
            (click)="setChannel('email')"
            data-testid="forgot-channel-email"
          >
            {{ 'auth.forgot.channelEmail' | translate }}
          </button>
          <button
            type="button"
            role="tab"
            class="auth-segment__btn"
            [class.auth-segment__btn--active]="channel() === 'phone'"
            [attr.aria-selected]="channel() === 'phone'"
            (click)="setChannel('phone')"
            data-testid="forgot-channel-phone"
          >
            {{ 'auth.forgot.channelPhone' | translate }}
          </button>
        </div>

        <form
          [formGroup]="form"
          (ngSubmit)="onSubmit()"
          novalidate
          class="auth-form"
          data-testid="forgot-form"
        >
          <ui-form-field
            *ngIf="channel() === 'email'"
            [label]="'auth.forgot.emailLabel'"
            fieldId="forgot-email"
            [required]="true"
            [control]="form.controls.email"
            [errorMap]="emailErrors"
          >
            <input
              id="forgot-email"
              type="email"
              autocomplete="email"
              inputmode="email"
              spellcheck="false"
              formControlName="email"
              class="auth-input"
              data-testid="forgot-email"
            />
          </ui-form-field>

          <ui-form-field
            *ngIf="channel() === 'phone'"
            [label]="'auth.forgot.phoneLabel'"
            fieldId="forgot-phone"
            [required]="true"
            [control]="form.controls.phone"
            [errorMap]="phoneErrors"
          >
            <ui-phone-input
              inputId="forgot-phone"
              formControlName="phone"
              data-testid="forgot-phone"
            ></ui-phone-input>
          </ui-form-field>

          <button
            type="submit"
            class="auth-submit"
            [disabled]="submitting() || cooldown() > 0"
            data-testid="forgot-submit"
          >
            <ng-container *ngIf="submitting(); else notSubmitting">
              {{ 'common.loading' | translate }}
            </ng-container>
            <ng-template #notSubmitting>
              <ng-container *ngIf="cooldown() > 0; else readySubmit">
                {{ 'auth.forgot.resendCooldown' | translate : { seconds: cooldown() } }}
              </ng-container>
              <ng-template #readySubmit>{{ 'auth.forgot.submit' | translate }}</ng-template>
            </ng-template>
          </button>
        </form>

        <p
          *ngIf="lockedOut()"
          class="auth-field-hint auth-field-hint--warn"
          role="alert"
          data-testid="forgot-lockout"
        >
          {{ 'auth.lockout.countdown' | translate : { seconds: cooldown() } }}
        </p>

        <p class="auth-card__footer">
          <a routerLink="/login" class="auth-link auth-link--strong">
            {{ 'auth.forgot.backToLogin' | translate }}
          </a>
        </p>
      </div>
    </main>
  `,
  styleUrl: './forgot-password.scss',
})
export class ForgotPasswordComponent implements OnDestroy {
  protected readonly channel = signal<'email' | 'phone'>('email');
  protected readonly submitting = signal(false);
  /** Submit cooldown countdown (seconds). Blocks back-to-back reset sends. */
  protected readonly cooldown = signal(0);
  /**
   * True while a server-reflected OTP lockout (429 OTP_RATE_LIMITED) is in
   * effect, drives the rate-limit banner. Shares the `cooldown` countdown.
   */
  protected readonly lockedOut = signal(false);

  private cooldownTimer: ReturnType<typeof setInterval> | null = null;

  protected readonly form: FormGroup<{
    email: FormControl<string>;
    phone: FormControl<string>;
  }>;

  protected readonly emailErrors: Record<string, string> = {
    required: 'auth.fields.required',
    email: 'auth.fields.email_invalid',
  };

  protected readonly phoneErrors: Record<string, string> = {
    required: 'auth.fields.required',
    phoneInvalid: 'auth.fields.phone_invalid',
  };

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      email: fb.control('', [Validators.required, Validators.email]),
      phone: fb.control(''),
    });
    this.applyChannelValidators('email');
  }

  protected setChannel(channel: 'email' | 'phone'): void {
    if (this.channel() === channel) return;
    this.channel.set(channel);
    this.applyChannelValidators(channel);
  }

  /**
   * Only the ACTIVE channel's control is required/validated; the inactive
   * one is cleared so a stale value can't block submit.
   */
  private applyChannelValidators(channel: 'email' | 'phone'): void {
    const { email, phone } = this.form.controls;
    if (channel === 'email') {
      email.setValidators([Validators.required, Validators.email]);
      phone.clearValidators();
      phone.setValue('');
    } else {
      phone.setValidators([Validators.required]);
      email.clearValidators();
      email.setValue('');
    }
    email.updateValueAndValidity();
    phone.updateValueAndValidity();
  }

  ngOnDestroy(): void {
    this.clearCooldown();
  }

  protected async onSubmit(): Promise<void> {
    this.form.markAllAsTouched();
    if (this.form.invalid || this.submitting() || this.cooldown() > 0) return;

    const channel = this.channel();
    const phone = this.form.controls.phone.value;
    const email = this.form.controls.email.value;
    const destination = channel === 'phone' ? phone : email;

    this.submitting.set(true);
    try {
      const response = await this.auth.requestPasswordResetChannel(
        channel === 'phone' ? { channel: 'phone', phone } : { channel: 'email', email },
      );

      /* Block an immediate re-send if the user navigates back to this page
         (e.g. browser back from /reset-password). */
      this.startCooldown();

      /* Always navigate, even for unregistered identifiers (anti-enumeration;
         the API returns a 'fake-' verification_id which the confirm step
         rejects as an invalid code). */
      await this.router.navigate(['/reset-password'], {
        queryParams: {
          verification_id: response.verification_id,
          channel,
          destination,
        },
      });
    } catch (err) {
      const result = mapApiErrors(err, this.form, FORGOT_ERROR_MAP);
      if (result.isNetworkError) {
        this.toast.error('auth.login.errors.network');
      } else if (result.unmapped.length > 0) {
        for (const code of result.unmapped) {
          if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
            this.toast.error('auth.forgot.errors.rateLimited');
            this.startLockout(err);
          } else if (code === AUTH_ERROR_CODES.OTP_PROVIDER_ERROR) {
            this.toast.error('auth.forgot.errors.providerError');
          } else {
            this.toast.error('auth.forgot.errors.unexpected');
          }
        }
      }
    } finally {
      this.submitting.set(false);
    }
  }

  /* ---------- Cooldown ---------- */

  private startCooldown(seconds: number = RESEND_COOLDOWN_SECONDS): void {
    this.cooldown.set(seconds);
    this.clearCooldown();
    this.cooldownTimer = setInterval(() => {
      const next = this.cooldown() - 1;
      if (next <= 0) {
        this.cooldown.set(0);
        this.lockedOut.set(false);
        this.clearCooldown();
      } else {
        this.cooldown.set(next);
      }
    }, 1000);
  }

  /**
   * Reflect a server OTP lockout (429 OTP_RATE_LIMITED): disable submit for
   * `retry_after` seconds (from the 429 body; ~60s fallback) and show the
   * rate-limit banner. Never shortens an in-flight longer cooldown.
   */
  private startLockout(err: unknown): void {
    const seconds = Math.max(readRetryAfterSeconds(err), this.cooldown());
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

const FORGOT_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.OTP_RATE_LIMITED]: { field: null, key: 'rateLimited' },
  [AUTH_ERROR_CODES.OTP_PROVIDER_ERROR]: { field: null, key: 'providerError' },
};
