import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
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
  ApiErrorMapping,
  ToastService,
} from '../../../shared/forms';
import { AUTH_ERROR_CODES } from '../../../core/auth/auth.types';

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
          {{ 'auth.reset.subtitle' | translate : { email: email() || '' } }}
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
              [disabled]="submitting()"
              data-testid="reset-submit"
            >
              {{ (submitting() ? 'common.loading' : 'auth.reset.submit') | translate }}
            </button>
          </form>
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
export class ResetPasswordComponent implements OnInit {
  protected readonly form: FormGroup<{
    code: FormControl<string>;
    new_password: FormControl<string>;
    confirm_password: FormControl<string>;
  }>;

  protected readonly submitting = signal(false);
  protected readonly email = signal<string | null>(null);
  private readonly verificationId = signal<string | null>(null);

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
        code: fb.control('', [Validators.required, Validators.pattern(/^\d{6}$/)]),
        new_password: fb.control('', [Validators.required, Validators.minLength(8)]),
        confirm_password: fb.control('', [Validators.required]),
      },
      { validators: [passwordsMatchValidator] },
    );
  }

  ngOnInit(): void {
    const vid = this.route.snapshot.queryParamMap.get('verification_id');
    const em = this.route.snapshot.queryParamMap.get('email');
    if (vid !== null) this.verificationId.set(vid);
    if (em !== null) this.email.set(em);
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
          } else {
            this.toast.error('auth.reset.errors.unexpected');
          }
        }
      }
    } finally {
      this.submitting.set(false);
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
  [AUTH_ERROR_CODES.OTP_RATE_LIMITED]: { field: null, key: 'rateLimited' },
};
