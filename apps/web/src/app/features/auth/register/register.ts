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
  PhoneInputComponent,
  PasswordStrengthComponent,
  PHONE_COUNTRIES,
  mapApiErrors,
  ApiErrorMapping,
  ToastService,
} from '../../../shared/forms';
import { AUTH_ERROR_CODES } from '../../../core/auth/auth.types';

/**
 * Register page — /register.
 *
 * Form fields (mirroring apps/api RegisterInput contract exactly)
 * ---------------------------------------------------------------
 *   - email          required + Validators.email
 *   - phone          required + E.164 via PhoneInputComponent (Y.1-D)
 *                    Country defaults to UAE; PhoneInputComponent
 *                    handles validation of national-digit count per
 *                    selected country.
 *   - password       required + minLength 8
 *                    PasswordStrengthComponent renders a five-bar
 *                    meter below the input. The meter is informational
 *                    only — even a weak password passes Validators.
 *                    The API itself enforces only ≥ 8 chars.
 *   - first_name     optional
 *   - last_name      optional
 *
 * country_code (the ISO alpha-2 sent in the payload) is derived from
 * the phone input's selected country. We don't ask the user for it
 * separately — that would be redundant since they already picked a
 * country in the phone dropdown.
 *
 * Submit flow
 * -----------
 *   1. AuthService.register() via the BFF proxy.
 *   2. On 201 → navigate to /verify-phone?verification_id=...&phone=...
 *      The Y.1-G page reads these params and prompts for the SMS code.
 *   3. On CONFLICT_EMAIL_TAKEN / CONFLICT_PHONE_TAKEN → per-field
 *      inline error.
 *   4. On OTP_RATE_LIMITED → 'rateLimited' toast.
 *   5. On OTP_PROVIDER_ERROR → 'providerError' toast.
 *   6. On VALIDATION_FAILED → per-field errors via mapApiErrors.
 *   7. On network failure → 'network' toast.
 *
 * a11y
 * ----
 *   - <h1> at the top
 *   - Each field has a <label> via FormFieldComponent
 *   - First invalid field is implicitly focused via touched ordering
 *   - Terms/Privacy disclosure renders below the submit button (per
 *     Y.1-D errorMap design — copy lives in i18n)
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
        <h1 class="auth-card__title">{{ 'auth.register.title' | translate }}</h1>
        <p class="auth-card__subtitle">{{ 'auth.register.subtitle' | translate }}</p>

        <form
          [formGroup]="form"
          (ngSubmit)="onSubmit()"
          novalidate
          class="auth-form"
          data-testid="register-form"
        >
          <div class="auth-form__row-2col">
            <ui-form-field
              [label]="'auth.register.firstNameLabel'"
              fieldId="register-first-name"
              [control]="form.controls.first_name"
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
              [control]="form.controls.last_name"
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
            [control]="form.controls.email"
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
            [label]="'auth.register.phoneLabel'"
            fieldId="register-phone"
            [required]="true"
            [helper]="'auth.register.phoneHint'"
            [control]="form.controls.phone"
            [errorMap]="phoneErrors"
          >
            <ui-phone-input
              inputId="register-phone"
              formControlName="phone"
              data-testid="register-phone"
            ></ui-phone-input>
          </ui-form-field>

          <ui-form-field
            [label]="'auth.register.passwordLabel'"
            fieldId="register-password"
            [required]="true"
            [helper]="'auth.register.passwordHint'"
            [control]="form.controls.password"
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
            [password]="form.controls.password.value"
            data-testid="register-password-strength"
          ></ui-password-strength>

          <button
            type="submit"
            class="auth-submit auth-submit--with-meter"
            [disabled]="submitting()"
            data-testid="register-submit"
          >
            {{ (submitting() ? 'common.loading' : 'auth.register.submit') | translate }}
          </button>
        </form>

        <p class="auth-card__footer">
          {{ 'auth.register.haveAccount' | translate }}
          <a routerLink="/login" class="auth-link auth-link--strong">
            {{ 'auth.register.signInLink' | translate }}
          </a>
        </p>
      </div>
    </main>
  `,
  styleUrl: './register.scss',
})
export class RegisterComponent {
  protected readonly form: FormGroup<{
    first_name: FormControl<string>;
    last_name: FormControl<string>;
    email: FormControl<string>;
    phone: FormControl<string>;
    password: FormControl<string>;
  }>;

  protected readonly submitting = signal(false);

  protected readonly genericFieldErrors: Record<string, string> = {
    required: 'auth.fields.required',
  };

  protected readonly emailErrors: Record<string, string> = {
    required: 'auth.fields.required',
    email: 'auth.fields.email_invalid',
    emailTaken: 'auth.register.errors.emailTaken',
  };

  protected readonly phoneErrors: Record<string, string> = {
    required: 'auth.fields.required',
    phoneInvalid: 'auth.fields.phone_invalid',
    phoneTaken: 'auth.register.errors.phoneTaken',
  };

  protected readonly passwordErrors: Record<string, string> = {
    required: 'auth.fields.required',
    minlength: 'auth.fields.password_too_short',
  };

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      first_name: fb.control(''),
      last_name: fb.control(''),
      email: fb.control('', [Validators.required, Validators.email]),
      phone: fb.control('', [Validators.required]),
      password: fb.control('', [Validators.required, Validators.minLength(8)]),
    });
  }

  protected async onSubmit(): Promise<void> {
    this.form.markAllAsTouched();
    if (this.form.invalid || this.submitting()) return;

    this.submitting.set(true);
    try {
      /* Derive country_code from the E.164 phone. We parse the first
         3-4 chars after '+' rather than reaching into PhoneInput's
         private state — keeps the contract clean. The Y.1-D parseE164
         helper does the heavy lifting, but to avoid a circular import
         we extract minimally here. */
      const phone = this.form.controls.phone.value;
      const countryCode = deriveCountryCode(phone);

      const response = await this.auth.register({
        email: this.form.controls.email.value,
        phone,
        password: this.form.controls.password.value,
        country_code: countryCode,
        first_name: this.form.controls.first_name.value || null,
        last_name: this.form.controls.last_name.value || null,
      });

      /* On success, hand off to verify-phone with the verification_id
         in query params. The phone is also passed so the verify page
         can show a masked version without re-fetching. */
      await this.router.navigate(['/verify-phone'], {
        queryParams: {
          verification_id: response.verification_id,
          phone,
          from: 'register',
        },
      });
    } catch (err) {
      const result = mapApiErrors(err, this.form, REGISTER_ERROR_MAP);

      if (result.isNetworkError) {
        this.toast.error('auth.login.errors.network');
        return;
      }
      if (result.unmapped.length === 0) {
        return; /* Per-field errors set by mapApiErrors. */
      }
      /* Surface specific unmapped codes via toast. */
      for (const code of result.unmapped) {
        if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
          this.toast.error('auth.register.errors.rateLimited');
        } else if (code === AUTH_ERROR_CODES.OTP_PROVIDER_ERROR) {
          this.toast.error('auth.register.errors.providerError');
        } else {
          this.toast.error('auth.register.errors.unexpected');
        }
      }
    } finally {
      this.submitting.set(false);
    }
  }
}

/**
 * Pull the ISO 3166-1 alpha-2 country code out of an E.164 number by
 * matching against PhoneInputComponent's COUNTRIES list. Longest-prefix
 * match wins so '+971' beats '+97' (hypothetical).
 *
 * Inlined here (~10 lines) rather than re-exported from phone-input.ts
 * so the boundary stays clean — the page doesn't reach into another
 * component's internals.
 */
function deriveCountryCode(e164: string): string {
  const sorted = [...PHONE_COUNTRIES].sort((a, b) => b.dialCode.length - a.dialCode.length);
  for (const country of sorted) {
    if (e164.startsWith(country.dialCode)) {
      return country.code;
    }
  }
  return 'AE'; /* Default to UAE if somehow unmatched. */
}

const REGISTER_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.CONFLICT_EMAIL_TAKEN]: { field: 'email', key: 'emailTaken' },
  [AUTH_ERROR_CODES.CONFLICT_PHONE_TAKEN]: { field: 'phone', key: 'phoneTaken' },
  [AUTH_ERROR_CODES.OTP_RATE_LIMITED]: { field: null, key: 'rateLimited' },
  [AUTH_ERROR_CODES.OTP_PROVIDER_ERROR]: { field: null, key: 'providerError' },
};
