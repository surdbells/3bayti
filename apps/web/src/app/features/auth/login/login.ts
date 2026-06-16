import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf, NgClass } from '@angular/common';
import {
  FormBuilder,
  FormGroup,
  FormControl,
  Validators,
  ReactiveFormsModule,
} from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { AuthService } from '../../../core/auth/auth.service';
import {
  FormFieldComponent,
  mapApiErrors,
  ApiErrorMapping,
  ToastService,
} from '../../../shared/forms';
import { AUTH_ERROR_CODES } from '../../../core/auth/auth.types';

/**
 * Login page — /login.
 *
 * Form
 * ----
 * Email + password. Email gets HTML5 type='email' + Validators.email
 * (cheap client-side validation; the API re-validates). Password is
 * type='password' with autocomplete='current-password' so password
 * managers offer to autofill.
 *
 * Flow
 * ----
 * On submit:
 *   1. AuthService.login(...) is called via the BFF proxy.
 *   2. On 200 with a phone-verified user: navigate to the returnUrl
 *      query param (if in-app) or '/'.
 *   3. On 200 with is_phone_verified=false: navigate to /verify-phone
 *      so the user can complete verification.
 *   4. On 401 AUTH_INVALID_CREDENTIALS or AUTH_ACCOUNT_INACTIVE: show
 *      the matching inline error.
 *   5. On network error: show a 'network' toast.
 *   6. On any other code: show a generic toast.
 *
 * a11y
 * ----
 *   - <h1> at the top for page-title hierarchy
 *   - <form> announced via the page title (no aria-label needed)
 *   - First invalid field receives focus on submit failure
 *   - aria-live="polite" toast region for global errors
 *
 * returnUrl handling
 * ------------------
 * The query param 'returnUrl' is honoured ONLY when it starts with '/'
 * (in-app) AND does NOT start with '//' (protocol-relative open
 * redirect). The same isSafeInAppPath logic in auth.guards.ts. We
 * duplicate it inline here rather than export to avoid coupling the
 * page to the guards file — the check is 3 lines.
 */
@Component({
  selector: 'app-login',
  standalone: true,
  imports: [
    NgIf,
    NgClass,
    ReactiveFormsModule,
    RouterLink,
    TranslatePipe,
    FormFieldComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="auth-page" data-testid="login-page">
      <div class="auth-card">
        <h1 class="auth-card__title">{{ 'auth.login.title' | translate }}</h1>
        <p class="auth-card__subtitle">{{ 'auth.login.subtitle' | translate }}</p>

        <form
          [formGroup]="form"
          (ngSubmit)="onSubmit()"
          novalidate
          class="auth-form"
          data-testid="login-form"
        >
          <ui-form-field
            [label]="'auth.login.emailLabel'"
            fieldId="login-email"
            [required]="true"
            [control]="form.controls.email"
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

          <ui-form-field
            [label]="'auth.login.passwordLabel'"
            fieldId="login-password"
            [required]="true"
            [control]="form.controls.password"
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
export class LoginComponent implements OnInit {
  protected readonly form: FormGroup<{
    email: FormControl<string>;
    password: FormControl<string>;
  }>;

  protected readonly submitting = signal(false);

  protected readonly emailErrors: Record<string, string> = {
    required: 'auth.fields.required',
    email: 'auth.fields.email_invalid',
    /* Per-page custom errors set via mapApiErrors below: */
    invalidCredentials: 'auth.login.errors.invalidCredentials',
    accountInactive: 'auth.login.errors.accountInactive',
  };

  protected readonly passwordErrors: Record<string, string> = {
    required: 'auth.fields.required',
    invalidCredentials: 'auth.login.errors.invalidCredentials',
  };

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly toast = inject(ToastService);
  private readonly translate = inject(TranslateService);

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      email: fb.control('', [Validators.required, Validators.email]),
      password: fb.control('', [Validators.required]),
    });
  }

  ngOnInit(): void {
    /* If we land on /login while already authenticated, the
       guestActivateGuard will redirect. This component only ever
       renders for anonymous users, so no extra guard logic here. */
  }

  protected async onSubmit(): Promise<void> {
    /* Mark all fields touched so untouched-but-invalid fields show
       errors immediately on submit. */
    this.form.markAllAsTouched();
    if (this.form.invalid || this.submitting()) {
      return;
    }

    this.submitting.set(true);
    try {
      await this.auth.login({
        email: this.form.controls.email.value,
        password: this.form.controls.password.value,
      });

      /* Phone verification is no longer a login gate — an unverified
         user can sign in and shop, and is only blocked at order
         placement (with a header badge + /account reminder nudging
         them to verify). So we always honour the returnUrl. */
      const returnUrl = this.route.snapshot.queryParamMap.get('returnUrl');
      const safeReturn = this.isSafeInAppPath(returnUrl) ? returnUrl : '/';
      await this.router.navigateByUrl(safeReturn);
    } catch (err) {
      const result = mapApiErrors(err, this.form, LOGIN_ERROR_MAP);

      if (result.isNetworkError) {
        this.toast.error('auth.login.errors.network');
      } else if (result.unmapped.length > 0) {
        /* Unmapped codes mean the API emitted something we don't
           have inline copy for — generic toast keeps the user
           moving. */
        this.toast.error('auth.login.errors.unexpected');
      }
      /* Per-field errors set by mapApiErrors will render inline. */
    } finally {
      this.submitting.set(false);
    }
  }

  /**
   * Same open-redirect defense as guestActivateGuard: in-app paths
   * only (single leading slash, NOT '//host' protocol-relative).
   */
  private isSafeInAppPath(value: string | null): value is string {
    if (value === null || value.length === 0) return false;
    if (value[0] !== '/') return false;
    if (value.length > 1 && value[1] === '/') return false;
    return true;
  }
}

/**
 * Error-code → form-field mapping for login submissions.
 */
const LOGIN_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.INVALID_CREDENTIALS]: { field: 'email', key: 'invalidCredentials' },
  [AUTH_ERROR_CODES.ACCOUNT_INACTIVE]: { field: 'email', key: 'accountInactive' },
};
