import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
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
  mapApiErrors,
  ApiErrorMapping,
  ToastService,
} from '../../../shared/forms';
import { AUTH_ERROR_CODES } from '../../../core/auth/auth.types';

/**
 * Forgot password page — /forgot-password.
 *
 * Asks for the user's email and triggers an OTP-based reset.
 *
 * Anti-enumeration protocol
 * -------------------------
 * The API endpoint /v3/auth/reset ALWAYS returns 200 with a
 * verification_id — even when the email isn't registered. For
 * non-existent emails the verification_id is prefixed with 'fake-'.
 * The frontend MUST NOT distinguish: we show the same success state
 * for either case ('check your email/SMS for a code') and route the
 * user to /reset-password regardless. An attacker can't tell which
 * emails are registered by probing this endpoint.
 *
 * Submit flow
 * -----------
 *   1. AuthService.requestPasswordReset(email)
 *   2. Navigate to /reset-password?verification_id=...&email=...
 *      (The reset-password page reads both — it needs the
 *      verification_id for confirm, and shows the email for context.)
 *   3. On OTP_RATE_LIMITED → toast (rare, but possible if a user
 *      is hammering the endpoint).
 *   4. On network failure → toast.
 *
 * We do NOT show a 'no account exists' error here — that would
 * defeat the anti-enumeration design.
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
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="auth-page" data-testid="forgot-page">
      <div class="auth-card">
        <h1 class="auth-card__title">{{ 'auth.forgot.title' | translate }}</h1>
        <p class="auth-card__subtitle">{{ 'auth.forgot.subtitle' | translate }}</p>

        <form
          [formGroup]="form"
          (ngSubmit)="onSubmit()"
          novalidate
          class="auth-form"
          data-testid="forgot-form"
        >
          <ui-form-field
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

          <button
            type="submit"
            class="auth-submit"
            [disabled]="submitting()"
            data-testid="forgot-submit"
          >
            {{ (submitting() ? 'common.loading' : 'auth.forgot.submit') | translate }}
          </button>
        </form>

        <p class="auth-card__footer">
          <a routerLink="/login" class="auth-link auth-link--strong">
            {{ 'auth.forgot.backToLogin' | translate }}
          </a>
        </p>
      </div>
    </main>
  `,
  styleUrl: '../login/login.scss',
})
export class ForgotPasswordComponent {
  protected readonly form: FormGroup<{ email: FormControl<string> }>;
  protected readonly submitting = signal(false);

  protected readonly emailErrors: Record<string, string> = {
    required: 'auth.fields.required',
    email: 'auth.fields.email_invalid',
  };

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      email: fb.control('', [Validators.required, Validators.email]),
    });
  }

  protected async onSubmit(): Promise<void> {
    this.form.markAllAsTouched();
    if (this.form.invalid || this.submitting()) return;

    this.submitting.set(true);
    try {
      const email = this.form.controls.email.value;
      const response = await this.auth.requestPasswordReset(email);

      /* Always navigate to /reset-password — even if the email
         isn't registered (the API returns a 'fake-' prefixed
         verification_id in that case, which the reset confirm
         endpoint will reject as an invalid code). This is the
         anti-enumeration design. */
      await this.router.navigate(['/reset-password'], {
        queryParams: {
          verification_id: response.verification_id,
          email,
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
          } else {
            this.toast.error('auth.forgot.errors.unexpected');
          }
        }
      }
    } finally {
      this.submitting.set(false);
    }
  }
}

const FORGOT_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.OTP_RATE_LIMITED]: { field: null, key: 'rateLimited' },
};
