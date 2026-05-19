import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
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
} from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthService } from '../../../core/auth/auth.service';
import {
  FormFieldComponent,
  mapApiErrors,
  ApiErrorMapping,
  ToastService,
} from '../../../shared/forms';
import { AUTH_ERROR_CODES } from '../../../core/auth/auth.types';

const VERIFICATION_ID_STORAGE_KEY = 'bayti_pending_verification_id';
const PHONE_STORAGE_KEY = 'bayti_pending_verification_phone';
const RESEND_COOLDOWN_SECONDS = 30;

/**
 * Verify phone page — /verify-phone.
 *
 * Reached from /register after a successful account creation, or from
 * /login when the user logs in with is_phone_verified=false.
 *
 * Query params
 * ------------
 *   verification_id  REQUIRED — opaque token from the API's /register
 *                    or /send-otp. Used in the /confirm call.
 *   phone            OPTIONAL — the phone number (E.164) so we can
 *                    show a masked version. If absent, the page shows
 *                    'your phone' instead.
 *   from             OPTIONAL — 'register' | 'login' — affects
 *                    secondary copy (whether to show 'back to login'
 *                    or 'back to register').
 *
 * Persistence
 * -----------
 * verification_id is also written to sessionStorage so a page refresh
 * doesn't lose it. Without this, refreshing /verify-phone after a
 * successful /register would strip the query string and the user
 * would have to start over.
 *
 * sessionStorage is per-tab. localStorage would persist across tabs
 * and reopens — probably too sticky for a 10-minute OTP window.
 *
 * Resend cooldown
 * ---------------
 * After the user clicks 'resend', the resend button is disabled for
 * RESEND_COOLDOWN_SECONDS (30s by default) with a countdown timer.
 * Matches the API's send-otp rate limit (3 per hour); 30s between
 * clicks gives 6 attempts in 3 minutes which is well under the limit.
 *
 * Submit flow
 * -----------
 *   1. AuthService.confirmRegistration({ verification_id, code }).
 *   2. On 200 → AuthService applies tokens, schedules refresh, sets
 *      currentUser. We clear sessionStorage and navigate to '/'.
 *   3. On OTP_INVALID_CODE → inline error.
 *   4. On OTP_RATE_LIMITED → toast.
 *   5. On any other error → toast.
 *
 * Code input UX
 * -------------
 * Single text input, maxlength=6, inputmode='numeric', autocomplete='one-time-code'.
 * 'one-time-code' triggers iOS's SMS auto-fill prompt when an SMS
 * arrives — important for the GCC market where iOS share is high.
 *
 * a11y
 * ----
 *   - <h1> + descriptive subtitle naming the phone (masked)
 *   - Error block with role='alert'
 *   - Resend button disabled state announced via aria-disabled
 */
@Component({
  selector: 'app-verify-phone',
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
    <main class="auth-page" data-testid="verify-phone-page">
      <div class="auth-card">
        <h1 class="auth-card__title">{{ 'auth.verifyPhone.title' | translate }}</h1>
        <p class="auth-card__subtitle">
          {{ 'auth.verifyPhone.subtitle' | translate : { phone: maskedPhone() } }}
        </p>

        <ng-container *ngIf="hasVerificationId(); else missingVerification">
          <form
            [formGroup]="form"
            (ngSubmit)="onSubmit()"
            novalidate
            class="auth-form"
            data-testid="verify-form"
          >
            <ui-form-field
              [label]="'auth.verifyPhone.codeLabel'"
              fieldId="verify-code"
              [required]="true"
              [control]="form.controls.code"
              [errorMap]="codeErrors"
            >
              <input
                id="verify-code"
                type="text"
                autocomplete="one-time-code"
                inputmode="numeric"
                maxlength="6"
                spellcheck="false"
                formControlName="code"
                class="auth-input auth-input--code"
                data-testid="verify-code"
              />
            </ui-form-field>

            <button
              type="submit"
              class="auth-submit"
              [disabled]="submitting()"
              data-testid="verify-submit"
            >
              {{ (submitting() ? 'common.loading' : 'auth.verifyPhone.submit') | translate }}
            </button>
          </form>

          <div class="auth-resend">
            <p class="auth-resend__prompt">
              {{ 'auth.verifyPhone.resendCta' | translate }}
            </p>
            <button
              type="button"
              class="auth-link auth-resend__button"
              [disabled]="resendCooldown() > 0 || resending()"
              [attr.aria-disabled]="resendCooldown() > 0 || resending()"
              (click)="onResend()"
              data-testid="verify-resend"
            >
              <ng-container *ngIf="resendCooldown() > 0; else readyToResend">
                {{ 'auth.verifyPhone.resendCooldown' | translate : { seconds: resendCooldown() } }}
              </ng-container>
              <ng-template #readyToResend>
                {{ 'auth.verifyPhone.resendButton' | translate }}
              </ng-template>
            </button>
          </div>
        </ng-container>

        <ng-template #missingVerification>
          <p class="auth-error-banner" data-testid="verify-missing-vid" role="alert">
            {{ 'auth.verifyPhone.errors.missingVerification' | translate }}
          </p>
          <a routerLink="/register" class="auth-link auth-link--strong">
            {{ 'auth.verifyPhone.errors.startOver' | translate }}
          </a>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './verify-phone.scss',
})
export class VerifyPhoneComponent implements OnInit, OnDestroy {
  protected readonly form: FormGroup<{ code: FormControl<string> }>;

  protected readonly submitting = signal(false);
  protected readonly resending = signal(false);
  protected readonly resendCooldown = signal(0);

  private readonly verificationId = signal<string | null>(null);
  private readonly phone = signal<string | null>(null);

  protected readonly codeErrors: Record<string, string> = {
    required: 'auth.fields.required',
    pattern: 'auth.validation.code6Digits',
    invalidCode: 'auth.verifyPhone.errors.invalidCode',
  };

  /** Masked phone — '+971•••••4567' shape. */
  protected readonly maskedPhone = computed(() => {
    const p = this.phone();
    if (p === null) return 'your phone';
    if (p.length <= 7) return p; /* Too short to mask sensibly. */
    /* Keep leading dial code (up to first 4 chars after +) and last 4
       digits; bullet-mask the middle. */
    const dialCodeEnd = Math.min(4, p.length - 4);
    const middleLength = p.length - dialCodeEnd - 4;
    return p.substring(0, dialCodeEnd) + '•'.repeat(Math.max(middleLength, 3)) + p.substring(p.length - 4);
  });

  protected hasVerificationId(): boolean {
    return this.verificationId() !== null;
  }

  private cooldownTimer: ReturnType<typeof setInterval> | null = null;

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly toast = inject(ToastService);

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      code: fb.control('', [Validators.required, Validators.pattern(/^\d{6}$/)]),
    });
  }

  ngOnInit(): void {
    /* Resolve verification_id from query params first, sessionStorage
       second. */
    const qpVid = this.route.snapshot.queryParamMap.get('verification_id');
    const qpPhone = this.route.snapshot.queryParamMap.get('phone');

    if (qpVid !== null) {
      this.verificationId.set(qpVid);
      this.persistToSession(VERIFICATION_ID_STORAGE_KEY, qpVid);
    } else {
      const stored = this.readFromSession(VERIFICATION_ID_STORAGE_KEY);
      if (stored !== null) {
        this.verificationId.set(stored);
      }
    }

    if (qpPhone !== null) {
      this.phone.set(qpPhone);
      this.persistToSession(PHONE_STORAGE_KEY, qpPhone);
    } else {
      const stored = this.readFromSession(PHONE_STORAGE_KEY);
      if (stored !== null) {
        this.phone.set(stored);
      }
    }
  }

  ngOnDestroy(): void {
    if (this.cooldownTimer !== null) {
      clearInterval(this.cooldownTimer);
      this.cooldownTimer = null;
    }
  }

  protected async onSubmit(): Promise<void> {
    const vid = this.verificationId();
    if (vid === null) return;

    this.form.markAllAsTouched();
    if (this.form.invalid || this.submitting()) return;

    this.submitting.set(true);
    try {
      await this.auth.confirmRegistration({
        verification_id: vid,
        code: this.form.controls.code.value,
      });

      /* On success: AuthService is now in the authenticated state.
         Clear the pending verification from sessionStorage and
         navigate to the home page. (Future: route to a 'welcome'
         page or honour a returnUrl.) */
      this.removeFromSession(VERIFICATION_ID_STORAGE_KEY);
      this.removeFromSession(PHONE_STORAGE_KEY);
      await this.router.navigateByUrl('/');
    } catch (err) {
      const result = mapApiErrors(err, this.form, VERIFY_ERROR_MAP);
      if (result.isNetworkError) {
        this.toast.error('auth.login.errors.network');
      } else if (result.unmapped.length > 0) {
        for (const code of result.unmapped) {
          if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
            this.toast.error('auth.verifyPhone.errors.rateLimited');
          } else {
            this.toast.error('auth.verifyPhone.errors.unexpected');
          }
        }
      }
    } finally {
      this.submitting.set(false);
    }
  }

  protected async onResend(): Promise<void> {
    if (this.resending() || this.resendCooldown() > 0) return;
    /* We need the email to call /send-otp; but the user came from
       /register and we didn't carry the email through. The API
       endpoint /v3/auth/send-otp accepts only email. Two ways to
       handle this:
         (a) Carry email through register → verify-phone as a query
             param (slight privacy concern — email in URL).
         (b) Have the user re-enter email on resend.
       For Y.1 we adopt (a) but ONLY when from='register'; on
       login-based verify (from=login) we route the user back to
       login to re-authenticate (their tokens are issued but
       resending OTP requires email which we don't have client-side).

       For this iteration, we surface a toast pointing the user to
       restart from the register page if the email isn't recoverable.
       This is the cleanest path that doesn't introduce email-in-URL
       privacy issues. */
    this.toast.info('auth.verifyPhone.errors.resendUnavailable');
    /* Even so, we kick off the cooldown so the button doesn't get
       click-spammed while the user reads the message. */
    this.startCooldown();
    /* The full resend with email-from-register-state is a Y.4
       refinement; see runbook. */
  }

  /* ----------------------------------------------------------------
     Cooldown
     ---------------------------------------------------------------- */

  private startCooldown(): void {
    this.resendCooldown.set(RESEND_COOLDOWN_SECONDS);
    if (this.cooldownTimer !== null) clearInterval(this.cooldownTimer);
    this.cooldownTimer = setInterval(() => {
      const next = this.resendCooldown() - 1;
      if (next <= 0) {
        this.resendCooldown.set(0);
        if (this.cooldownTimer !== null) {
          clearInterval(this.cooldownTimer);
          this.cooldownTimer = null;
        }
      } else {
        this.resendCooldown.set(next);
      }
    }, 1000);
  }

  /* ----------------------------------------------------------------
     sessionStorage helpers (SSR-safe)
     ---------------------------------------------------------------- */

  private persistToSession(key: string, value: string): void {
    if (typeof sessionStorage === 'undefined') return;
    try {
      sessionStorage.setItem(key, value);
    } catch {
      /* Quota / disabled — silently ignore. */
    }
  }

  private readFromSession(key: string): string | null {
    if (typeof sessionStorage === 'undefined') return null;
    try {
      return sessionStorage.getItem(key);
    } catch {
      return null;
    }
  }

  private removeFromSession(key: string): void {
    if (typeof sessionStorage === 'undefined') return;
    try {
      sessionStorage.removeItem(key);
    } catch {
      /* Silently ignore. */
    }
  }
}

const VERIFY_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.OTP_INVALID_CODE]: { field: 'code', key: 'invalidCode' },
  [AUTH_ERROR_CODES.OTP_RATE_LIMITED]: { field: null, key: 'rateLimited' },
};
