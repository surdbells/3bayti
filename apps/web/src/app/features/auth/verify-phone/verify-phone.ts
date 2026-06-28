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
import { PhoneService } from '../../../core/auth/phone.service';
import {
  FormFieldComponent,
  PhoneInputComponent,
  mapApiErrors,
  readRetryAfterSeconds,
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
    PhoneInputComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="auth-page" data-testid="verify-phone-page">
      <div class="auth-card">
        <h1 class="auth-card__title">{{ 'auth.verifyPhone.title' | translate }}</h1>

        <!-- ===== Phone-after-social gate: collect a phone number first ===== -->
        <ng-container *ngIf="socialPhoneEntry()">
          <p class="auth-card__subtitle">{{ 'auth.verifyPhone.socialPrompt' | translate }}</p>
          <form
            [formGroup]="phoneForm"
            (ngSubmit)="onSendSocialPhone()"
            novalidate
            class="auth-form"
            data-testid="verify-social-phone-form"
          >
            <ui-form-field
              [label]="'auth.verifyPhone.phoneLabel'"
              fieldId="verify-social-phone"
              [required]="true"
              [control]="phoneForm.controls.phone"
              [errorMap]="phoneErrors"
            >
              <ui-phone-input
                inputId="verify-social-phone"
                formControlName="phone"
                data-testid="verify-social-phone"
              ></ui-phone-input>
            </ui-form-field>

            <button
              type="submit"
              class="auth-submit"
              [disabled]="sending()"
              data-testid="verify-social-phone-submit"
            >
              {{ (sending() ? 'common.loading' : 'auth.verifyPhone.sendButton') | translate }}
            </button>
          </form>
        </ng-container>

        <ng-container *ngIf="!socialPhoneEntry()">
        <ng-container *ngIf="hasVerificationId(); else noVerification">
          <p class="auth-card__subtitle">
            {{ 'auth.verifyPhone.subtitle' | translate : { phone: maskedPhone() } }}
          </p>
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
              [disabled]="submitting() || lockedOut()"
              data-testid="verify-submit"
            >
              {{ (submitting() ? 'common.loading' : 'auth.verifyPhone.submit') | translate }}
            </button>
          </form>

          <p
            *ngIf="lockedOut()"
            class="auth-field-hint auth-field-hint--warn"
            role="alert"
            data-testid="verify-lockout"
          >
            {{ 'auth.lockout.countdown' | translate : { seconds: resendCooldown() } }}
          </p>

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

        <ng-template #noVerification>
          <ng-container *ngIf="canSelfServe(); else missingVerification">
            <p class="auth-card__subtitle">
              {{ 'auth.verifyPhone.sendPrompt' | translate : { phone: maskedPhone() } }}
            </p>
            <button
              type="button"
              class="auth-submit"
              [disabled]="sending() || resendCooldown() > 0"
              (click)="onSendCode()"
              data-testid="verify-send-code"
            >
              {{ (sending() ? 'common.loading' : 'auth.verifyPhone.sendButton') | translate }}
            </button>
          </ng-container>

          <ng-template #missingVerification>
            <p class="auth-error-banner" data-testid="verify-missing-vid" role="alert">
              {{ 'auth.verifyPhone.errors.missingVerification' | translate }}
            </p>
            <a routerLink="/register" class="auth-link auth-link--strong">
              {{ 'auth.verifyPhone.errors.startOver' | translate }}
            </a>
          </ng-template>
        </ng-template>
        </ng-container>
      </div>
    </main>
  `,
  styleUrl: './verify-phone.scss',
})
export class VerifyPhoneComponent implements OnInit, OnDestroy {
  protected readonly form: FormGroup<{ code: FormControl<string> }>;
  /** Phone-entry form for the phone-after-social gate (from=social). */
  protected readonly phoneForm: FormGroup<{ phone: FormControl<string> }>;

  /**
   * True when this page is the phone-after-social gate (from=social) and we
   * still need a phone number from the user (no verification_id minted yet).
   * In this mode the OTP send/verify goes through PhoneService (POST /me/phone
   * + /me/phone/verify, Bearer) rather than the BFF /confirm registration flow.
   */
  protected readonly isSocial = signal(false);
  protected socialPhoneEntry(): boolean {
    return this.isSocial() && this.verificationId() === null;
  }

  protected readonly submitting = signal(false);
  protected readonly resending = signal(false);
  protected readonly sending = signal(false);
  protected readonly resendCooldown = signal(0);
  /**
   * True while a server-reflected OTP lockout (429 OTP_RATE_LIMITED) is in
   * effect. Reuses resendCooldown for the countdown but ALSO disables Verify
   * (and Send) for the retry_after window.
   */
  protected readonly lockedOut = signal(false);

  private readonly verificationId = signal<string | null>(null);
  private readonly phone = signal<string | null>(null);

  protected readonly codeErrors: Record<string, string> = {
    required: 'auth.fields.required',
    pattern: 'auth.validation.code6Digits',
    invalidCode: 'auth.verifyPhone.errors.invalidCode',
  };

  protected readonly phoneErrors: Record<string, string> = {
    required: 'auth.fields.required',
    phoneInvalid: 'auth.fields.phone_invalid',
    phoneTaken: 'auth.verifyPhone.errors.phoneTaken',
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

  /**
   * A signed-in but unverified user can request an OTP to their phone on
   * the spot — we have their email via currentUser. This is the path for
   * the post-registration nudges (account banner, checkout gate, header
   * badge) which route here WITHOUT a verification_id; previously that
   * dead-ended at "Verification session missing".
   */
  protected canSelfServe(): boolean {
    const user = this.auth.currentUser();
    return user !== null && user.is_phone_verified === false;
  }

  private cooldownTimer: ReturnType<typeof setInterval> | null = null;

  private readonly auth = inject(AuthService);
  private readonly phoneSvc = inject(PhoneService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly toast = inject(ToastService);

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      code: fb.control('', [Validators.required, Validators.pattern(/^\d{4,6}$/)]),
    });
    this.phoneForm = fb.group({
      phone: fb.control('', [Validators.required]),
    });
  }

  ngOnInit(): void {
    /* Phone-after-social gate: from=social. The user is authenticated (the
       social login already set the session) but has no verified phone, so we
       collect + verify one via PhoneService (POST /me/phone). */
    this.isSocial.set(this.route.snapshot.queryParamMap.get('from') === 'social');

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

    /* Nudge flow (account / checkout / header badge) hands over no phone
       query param — fall back to the signed-in user's phone so the
       masked display still works. */
    if (this.phone() === null) {
      const userPhone = this.auth.currentUser()?.phone ?? null;
      if (userPhone !== null && userPhone.length > 0) {
        this.phone.set(userPhone);
      }
    }

    /* If an already-verified user lands here (e.g. a stale nudge link),
       there is nothing to verify — send them on to where they were
       headed rather than showing a confusing screen. */
    if (this.verificationId() === null) {
      const user = this.auth.currentUser();
      if (user !== null && user.is_phone_verified === true) {
        void this.navigateAfter();
      }
    }
  }

  ngOnDestroy(): void {
    if (this.cooldownTimer !== null) {
      clearInterval(this.cooldownTimer);
      this.cooldownTimer = null;
    }
  }

  /**
   * Phone-after-social: submit the entered phone to POST /me/phone, which
   * dispatches an OTP and returns a verification_id. On success we reveal the
   * code-entry form (verificationId set). 409 CONFLICT_PHONE_TAKEN lands on
   * the phone field.
   */
  protected async onSendSocialPhone(): Promise<void> {
    this.phoneForm.markAllAsTouched();
    if (this.phoneForm.invalid || this.sending()) return;

    const phone = this.phoneForm.controls.phone.value;
    this.sending.set(true);
    try {
      const res = await this.phoneSvc.sendOtp(phone);
      this.verificationId.set(res.verification_id);
      this.phone.set(phone);
      this.form.reset({ code: '' });
      this.startCooldown();
      this.toast.success('auth.verifyPhone.codeSent');
    } catch (err) {
      const result = mapApiErrors(err, this.phoneForm, SOCIAL_PHONE_ERROR_MAP);
      if (result.isNetworkError) {
        this.toast.error('auth.login.errors.network');
      } else if (result.unmapped.length > 0) {
        for (const code of result.unmapped) {
          if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
            this.toast.error('auth.verifyPhone.errors.rateLimited');
            this.startLockout(err);
          } else if (code === AUTH_ERROR_CODES.OTP_PROVIDER_ERROR) {
            this.toast.error('auth.verifyPhone.errors.unexpected');
          } else {
            this.toast.error('auth.verifyPhone.errors.unexpected');
          }
        }
      }
    } finally {
      this.sending.set(false);
    }
  }

  protected async onSubmit(): Promise<void> {
    const vid = this.verificationId();
    if (vid === null) return;

    this.form.markAllAsTouched();
    if (this.form.invalid || this.submitting()) return;

    /* Phone-after-social verifies through PhoneService (Bearer /me/phone/verify)
       rather than the BFF registration /confirm. The user is already signed in;
       there's no token pair to issue — just flip is_phone_verified. */
    if (this.isSocial()) {
      await this.submitSocialOtp(vid);
      return;
    }

    this.submitting.set(true);
    try {
      await this.auth.confirmRegistration({
        verification_id: vid,
        code: this.form.controls.code.value,
      });

      /* On success: AuthService is now in the authenticated state.
         Clear the pending verification from sessionStorage and return
         to the page the user came from (e.g. checkout, when they were
         gated there) if it's a safe in-app path; otherwise home. */
      this.removeFromSession(VERIFICATION_ID_STORAGE_KEY);
      this.removeFromSession(PHONE_STORAGE_KEY);
      await this.navigateAfter();
    } catch (err) {
      const result = mapApiErrors(err, this.form, VERIFY_ERROR_MAP);
      if (result.isNetworkError) {
        this.toast.error('auth.login.errors.network');
      } else if (result.unmapped.length > 0) {
        for (const code of result.unmapped) {
          if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
            this.toast.error('auth.verifyPhone.errors.rateLimited');
            this.startLockout(err);
          } else {
            this.toast.error('auth.verifyPhone.errors.unexpected');
          }
        }
      }
    } finally {
      this.submitting.set(false);
    }
  }

  /**
   * Verify the OTP for the phone-after-social gate via POST /me/phone/verify.
   * On success the user's phone is verified server-side (PhoneService mirrors
   * it into the cached auth user); we continue to the returnUrl / home.
   */
  private async submitSocialOtp(vid: string): Promise<void> {
    this.submitting.set(true);
    try {
      await this.phoneSvc.verify(vid, this.form.controls.code.value);
      await this.navigateAfter();
    } catch (err) {
      const result = mapApiErrors(err, this.form, VERIFY_ERROR_MAP);
      if (result.isNetworkError) {
        this.toast.error('auth.login.errors.network');
      } else if (result.unmapped.length > 0) {
        for (const code of result.unmapped) {
          if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
            this.toast.error('auth.verifyPhone.errors.rateLimited');
            this.startLockout(err);
          } else {
            this.toast.error('auth.verifyPhone.errors.unexpected');
          }
        }
      }
    } finally {
      this.submitting.set(false);
    }
  }

  /**
   * Send-code button on the self-serve screen (signed-in, unverified, no
   * verification_id yet). Mints + sends an OTP to the user's phone via
   * /send-otp, then reveals the code-entry form.
   */
  protected async onSendCode(): Promise<void> {
    const email = this.auth.currentUser()?.email ?? null;
    if (email === null || this.sending()) return;
    this.sending.set(true);
    try {
      if (await this.sendOtp(email)) {
        this.toast.success('auth.verifyPhone.codeSent');
        this.startCooldown();
      }
    } finally {
      this.sending.set(false);
    }
  }

  /**
   * Resend from inside the code form. Uses the signed-in user's email.
   * For the (unauthenticated) register-refresh edge case where we have no
   * email client-side, point the user back to register rather than fail
   * silently.
   */
  protected async onResend(): Promise<void> {
    if (this.resending() || this.resendCooldown() > 0) return;

    /* Phone-after-social resends through /me/phone (re-send to the same
       number) rather than the registration /send-otp path. */
    if (this.isSocial()) {
      const phone = this.phone();
      if (phone === null || phone.length === 0) return;
      this.resending.set(true);
      try {
        const res = await this.phoneSvc.sendOtp(phone);
        this.verificationId.set(res.verification_id);
        this.toast.success('auth.verifyPhone.codeSent');
      } catch (err) {
        const result = mapApiErrors(err, this.form, VERIFY_ERROR_MAP);
        if (result.isNetworkError) {
          this.toast.error('auth.login.errors.network');
        } else if (result.unmapped.includes(AUTH_ERROR_CODES.OTP_RATE_LIMITED)) {
          this.toast.error('auth.verifyPhone.errors.rateLimited');
          this.startLockout(err);
        } else {
          this.toast.error('auth.verifyPhone.errors.unexpected');
        }
      } finally {
        this.resending.set(false);
        this.startCooldown();
      }
      return;
    }

    const email = this.auth.currentUser()?.email ?? null;
    if (email === null) {
      /* Register flow lands here pre-authentication; we deliberately never
         carried the email through (no email-in-URL). Direct them to
         restart from register, where the email is in hand. */
      this.toast.info('auth.verifyPhone.errors.resendUnavailable');
      this.startCooldown();
      return;
    }
    this.resending.set(true);
    try {
      if (await this.sendOtp(email)) {
        this.toast.success('auth.verifyPhone.codeSent');
      }
    } finally {
      this.resending.set(false);
      /* Cooldown regardless, so the button can't be spammed while the
         user reads the result. */
      this.startCooldown();
    }
  }

  /**
   * Request a fresh OTP for `email` via /send-otp and adopt the returned
   * verification_id (persisted to sessionStorage so a refresh keeps it).
   * Returns true on success. The API rate-limits send-otp (3/hour); the
   * 30s resend cooldown keeps us comfortably under that.
   */
  private async sendOtp(email: string): Promise<boolean> {
    try {
      const res = await this.auth.resendOtp(email);
      this.verificationId.set(res.verification_id);
      this.persistToSession(VERIFICATION_ID_STORAGE_KEY, res.verification_id);
      return true;
    } catch (err) {
      const result = mapApiErrors(err, this.form, VERIFY_ERROR_MAP);
      if (result.isNetworkError) {
        this.toast.error('auth.login.errors.network');
      } else if (result.unmapped.includes(AUTH_ERROR_CODES.OTP_RATE_LIMITED)) {
        this.toast.error('auth.verifyPhone.errors.rateLimited');
        this.startLockout(err);
      } else {
        this.toast.error('auth.verifyPhone.errors.unexpected');
      }
      return false;
    }
  }

  /* ----------------------------------------------------------------
     Cooldown
     ---------------------------------------------------------------- */

  private startCooldown(seconds: number = RESEND_COOLDOWN_SECONDS): void {
    this.resendCooldown.set(seconds);
    if (this.cooldownTimer !== null) clearInterval(this.cooldownTimer);
    this.cooldownTimer = setInterval(() => {
      const next = this.resendCooldown() - 1;
      if (next <= 0) {
        this.resendCooldown.set(0);
        this.lockedOut.set(false);
        if (this.cooldownTimer !== null) {
          clearInterval(this.cooldownTimer);
          this.cooldownTimer = null;
        }
      } else {
        this.resendCooldown.set(next);
      }
    }, 1000);
  }

  /**
   * Reflect a server OTP lockout (429 OTP_RATE_LIMITED): disable Resend/Send
   * AND Verify for `retry_after` seconds (from the 429 body; ~60s fallback),
   * reusing the resend countdown. Never shortens an in-flight longer lockout.
   */
  private startLockout(err: unknown): void {
    const seconds = Math.max(readRetryAfterSeconds(err), this.resendCooldown());
    this.lockedOut.set(true);
    this.startCooldown(seconds);
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

  /**
   * Navigate to the post-verification destination: a safe in-app
   * returnUrl if one was supplied (e.g. checkout, when the user was gated
   * there), otherwise home.
   */
  private async navigateAfter(): Promise<void> {
    const returnUrl = this.route.snapshot.queryParamMap.get('returnUrl');
    await this.router.navigateByUrl(
      this.isSafeInAppPath(returnUrl) ? returnUrl : '/',
    );
  }

  /**
   * True only for safe in-app paths ("/...", not "//..." which would be
   * a protocol-relative external URL). Guards the post-verify returnUrl
   * against open-redirects.
   */
  private isSafeInAppPath(value: string | null): value is string {
    if (value === null || value.length === 0) return false;
    if (value[0] !== '/') return false;
    if (value.length > 1 && value[1] === '/') return false;
    return true;
  }
}

const VERIFY_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.OTP_INVALID_CODE]: { field: 'code', key: 'invalidCode' },
  [AUTH_ERROR_CODES.OTP_VERIFICATION_FAILED]: { field: 'code', key: 'invalidCode' },
  [AUTH_ERROR_CODES.OTP_RATE_LIMITED]: { field: null, key: 'rateLimited' },
};

/* Phone-after-social: POST /me/phone surfaces CONFLICT_PHONE_TAKEN when the
   number is already on another account — land it on the phone field. */
const SOCIAL_PHONE_ERROR_MAP: Record<string, ApiErrorMapping> = {
  [AUTH_ERROR_CODES.CONFLICT_PHONE_TAKEN]: { field: 'phone', key: 'phoneTaken' },
  [AUTH_ERROR_CODES.OTP_RATE_LIMITED]: { field: null, key: 'rateLimited' },
  [AUTH_ERROR_CODES.OTP_PROVIDER_ERROR]: { field: null, key: 'providerError' },
};
