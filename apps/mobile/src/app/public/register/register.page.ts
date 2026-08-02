import {Component, OnDestroy, OnInit} from '@angular/core';
import {
  IonContent,
  Platform,
  IonButton,
  IonText,
  IonCheckbox,
  IonCard,
  IonCardHeader,
  IonCardTitle,
  IonCardContent,
  IonSegment,
  IonSegmentButton,
  IonLabel,
} from '@ionic/angular/standalone';
import { ConnectionService } from '../../service/connection.service';
import { Subscription } from 'rxjs';

import { FormsModule } from '@angular/forms';
import {Router} from "@angular/router";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {GlobalComponent} from "../../global-component";
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";
import {Preferences} from "@capacitor/preferences";
import {FirebaseAuthentication} from "@capacitor-firebase/authentication";
import {BlockerService} from "../../blocker.service";

import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
import { AxIconComponent } from '../../shared/ax-mobile/icon';

import {transformV3LoginResponse} from "../login/login-response.transform";
import {CartMergeService} from "../../core/services/cart-merge.service";
import {PushManager} from "../../core/services/push-manager.service";
import {DIAL_CODES, DialCode, mapDialToIso} from "../shared/dial-codes";

/**
 * Mobile registration page — rebuilt for the v3 PHONE-FIRST 4-step flow.
 *
 * Flow (replaces the legacy 2-stage register/confirm flow)
 * ========================================================
 *
 *   Step 1 (Phone): country-code + phone.
 *     [Continue] -> POST /auth/register/initiate { phone, country_code }
 *       -> data { verification_id }. Stash verification_id, advance to Step 2.
 *       CONFLICT_PHONE_TAKEN (409) -> specific error + offer login link.
 *
 *   Step 2 (Verify phone): "We sent a code to <phone>" + OTP.
 *     [Verify] -> POST /auth/register/verify-phone { verification_id, code }
 *       -> data { registration_token }. Stash registration_token, advance
 *       to Step 3. OTP_VERIFICATION_FAILED (401) -> specific error.
 *     Resend -> re-call initiate. Change number -> back to Step 1.
 *
 *   Step 3 (Details): verified phone PREFILLED + READONLY, plus first_name,
 *     last_name, email, password, confirm_password, accept-terms (T&C sheet).
 *     [Continue] -> validate -> POST /auth/register/submit
 *       { registration_token, email, password, first_name, last_name }
 *       -> data { verification_id (email) }. Stash, advance to Step 4.
 *       CONFLICT_EMAIL_TAKEN (409) -> inline email error.
 *       AUTH_INVALID_TOKEN (401, token expired) -> toast + restart at Step 1.
 *
 *   Step 4 (Verify email): "We sent a code to <email>" + OTP.
 *     [Verify] -> POST /auth/register/confirm-email { verification_id, code }
 *       -> data { tokens, user } (same shape /auth/confirm returns).
 *       transformV3LoginResponse -> Preferences.set('user') ->
 *       cartMerge.mergeIfAny (non-blocking) -> pushManager.onSignedIn() ->
 *       navigate /account (auto-login).
 *     Resend -> re-call submit with same data. Back -> Step 3.
 *
 * Errors
 * ======
 * v3 errors arrive on the SUCCESS channel as
 * { response_code, status: 'error', message, error_code }. We read
 * response.error_code / response.message; we also defensively read
 * err?.error?.error?.code on the rxjs error cb. Known codes map to i18n.
 */
@Component({
  selector: 'app-register',
  templateUrl: './register.page.html',
  styleUrls: ['./register.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    FormsModule,
    IonButton,
    IonText,
    IonCheckbox,
    IonCard,
    IonCardHeader,
    IonCardTitle,
    IonCardContent,
    IonSegment,
    IonSegmentButton,
    IonLabel,
    TranslatePipe,
    AxLoaderComponent,
    AxTextFieldComponent,
    AxBottomSheetComponent,
    AxIconComponent,
  ]
})
export class RegisterPage implements OnInit, OnDestroy {
  isOnline = true;
  private sub: Subscription;
  isTermsOpen = false; // controls the terms-of-service modal visibility
  isCountryCodeOpen = false; // controls the dial-code picker sheet

  // Dial-code picker state (reuses the profile page pattern).
  readonly dialCodes: DialCode[] = DIAL_CODES;
  codeSearch = '';

  /**
   * Inline phone-availability hint (Step 1). `taken` drives the inline
   * "already registered" message shown BEFORE the user taps Continue.
   * `checkedPhone` records the last full phone we validated so we don't
   * re-hit the endpoint for an unchanged value. The hard gate is still
   * the Continue-time 409 in initiate_phone().
   */
  phoneCheck = { taken: false, checkedPhone: '' };
  private phoneCheckTimer: ReturnType<typeof setTimeout> | null = null;

  constructor(
    private net: ConnectionService,
    private platform: Platform,
    private blocker: BlockerService,
    private router: Router,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private i18n: I18nService,
    private cartMerge: CartMergeService,
    private pushManager: PushManager,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
    if (this.phoneCheckTimer) {
      clearTimeout(this.phoneCheckTimer);
    }
    this.clearOtpTimer();
    this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
  }

  ngOnInit() {
    // v3 sends OTP internally on each step; page starts ready for input.
  }

  /**
   * UI flow state.
   *   step 1: phone
   *   step 2: verify phone OTP
   *   step 3: account details
   *   step 4: verify email OTP
   */
  ui_controls = {
    loading: false,
    // Steps 1-4 are the email/password registration flow. Steps 5-6 are the
    // phone-after-social gate reached only via Google/Apple sign-in:
    //   5 = capture phone, 6 = verify phone OTP.
    step: 1 as 1 | 2 | 3 | 4 | 5 | 6,
    termsArabic: true,
    termsEnglish: false,
  };

  /** True while a native Google/Apple sign-in is in flight (button spinner). */
  socialLoading = false;
  /** Auth token of a freshly social-signed-in user (phone-gate calls). */
  private socialAuthToken = '';
  /** Phone the social user is verifying. */
  socialPhone = '';

  register = {
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    password: "",
    confirm_password: "",
    countryCode: "+971",
    accepted_terms: false,
  };

  /** Issued by initiate; consumed by verify-phone. */
  phoneVerificationId = "";
  /** Issued by verify-phone; consumed by submit. */
  registrationToken = "";
  /** Issued by submit; consumed by confirm-email. */
  emailVerificationId = "";

  otp = { code: "" };

  /**
   * Resend cooldown / rate-limit lockout state (shared by the phone-OTP
   * step 2 and the email-OTP step 4). `otpCooldown` is the remaining
   * seconds the Resend control is disabled; a short cooldown starts after
   * each successful send, a longer lockout (server retry_after) starts on
   * an OTP_RATE_LIMITED 429. `otpLocked` marks the hard server lockout
   * (also disables Verify).
   */
  otpCooldown = 0;
  otpLocked = false;
  private readonly DEFAULT_RESEND_COOLDOWN = 30;
  private readonly DEFAULT_LOCKOUT = 60;
  private otpTimer: ReturnType<typeof setInterval> | null = null;

  /** Selected dial-code metadata (flag) for the picker button. */
  get selectedDial(): DialCode | undefined {
    return this.dialCodes.find(c => c.code === this.register.countryCode);
  }

  /** Full E.164-ish phone for display / API ("+971" + national digits). */
  get fullPhone(): string {
    const national = this.register.phone.replace(/^0+/, '').replace(/\D/g, '');
    return `${this.register.countryCode}${national}`;
  }

  filteredDialCodes(): DialCode[] {
    const q = this.codeSearch.trim().toLowerCase();
    if (q.length === 0) return this.dialCodes;
    return this.dialCodes.filter(c =>
      c.name.toLowerCase().includes(q) || c.code.includes(q));
  }

  selectCode(c: DialCode) {
    this.register.countryCode = c.code;
    // Re-check availability for the new dial-code + existing national digits.
    this.onPhoneChanged();
  }

  // --- Inline phone availability check (Step 1 UX) -----------------------

  /**
   * Clear the inline "already registered" hint as soon as the number
   * changes, and schedule a debounced availability check so the hint can
   * appear without waiting for blur on fast typists.
   */
  onPhoneChanged() {
    this.phoneCheck.taken = false;
    this.phoneCheck.checkedPhone = '';
    if (this.phoneCheckTimer) {
      clearTimeout(this.phoneCheckTimer);
    }
    this.phoneCheckTimer = setTimeout(() => this.checkPhoneAvailability(), 600);
  }

  /**
   * Ask the backend whether this phone is already registered and surface
   * an inline hint. Best-effort UX only: never blocks, never toasts —
   * the Continue-time 409 in initiate_phone() remains the hard gate.
   * POST /auth/validate-phone -> data { phone, available }.
   */
  checkPhoneAvailability() {
    if (this.phoneCheckTimer) {
      clearTimeout(this.phoneCheckTimer);
      this.phoneCheckTimer = null;
    }
    if (!this.isOnline) return;
    const national = this.register.phone.replace(/\D/g, '');
    if (national.length < 6) return;

    const full = this.fullPhone;
    if (full === this.phoneCheck.checkedPhone) return;

    this.networkAdapter.post_v3('POST /auth/validate-phone', { phone: full }).subscribe({
      next: (response: any) => {
        // Ignore stale responses if the number changed mid-flight.
        if (this.fullPhone !== full) return;
        if (response?.response_code === 200 && response?.status === 'success') {
          this.phoneCheck.checkedPhone = full;
          this.phoneCheck.taken = response.data?.available === false;
        }
      },
      error: () => {
        // Best-effort hint: swallow errors, the Continue 409 still gates.
      },
    });
  }

  // --- Step 1: phone -> initiate -----------------------------------------

  initiate_phone() {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    if (this.register.phone.replace(/\D/g, '').length === 0) {
      this.error_notification(this.i18n.t('text_phone_required'));
      return;
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/register/initiate', {
      phone: this.fullPhone,
      country_code: mapDialToIso(this.register.countryCode),
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const vid = response.data?.verification_id;
          if (typeof vid !== 'string' || vid.length === 0) {
            this.error_notification(this.i18n.t('text_request_failed'));
            return;
          }
          this.phoneVerificationId = vid;
          this.otp.code = '';
          this.ui_controls.step = 2;
          this.startResendCooldown();
          this.success_notification(this.i18n.t('text_otp_sent'));
        } else {
          this.handleOtpError(response);
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  // --- Step 2: verify phone OTP -> registration_token --------------------

  verify_phone() {
    const code = (this.otp.code ?? '').trim();
    if (code.length === 0) {
      this.error_notification(this.i18n.t('text_otp_required'));
      return;
    }
    // The API rejects a non-4-6-digit code as a validation failure. Catch it
    // here with a clear message instead of the opaque server banner (an
    // autofilled OTP can carry stray spaces/characters).
    if (!/^\d{4,6}$/.test(code)) {
      this.error_notification(this.i18n.t('text_otp_verification_failed'));
      return;
    }
    // Guard the verification_id too: if it's been lost (blank), the server
    // returns the same generic "fields failed validation" — restart cleanly
    // rather than firing a doomed request.
    if (!this.phoneVerificationId) {
      this.restartExpired();
      return;
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/register/verify-phone', {
      verification_id: this.phoneVerificationId,
      code,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const token = response.data?.registration_token;
          if (typeof token !== 'string' || token.length === 0) {
            this.error_notification(this.i18n.t('text_request_failed'));
            return;
          }
          this.registrationToken = token;
          this.ui_controls.step = 3;
          this.clearOtpTimer();
          this.otpCooldown = 0;
          this.otpLocked = false;
        } else {
          this.handleOtpError(response);
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  resend_phone_otp() {
    if (this.otpCooldown > 0) return; // cooldown / lockout active
    // Re-issue the phone OTP by re-calling initiate. Keeps us on Step 2.
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/register/initiate', {
      phone: this.fullPhone,
      country_code: mapDialToIso(this.register.countryCode),
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const vid = response.data?.verification_id;
          if (typeof vid === 'string' && vid.length > 0) {
            this.phoneVerificationId = vid;
            this.otp.code = '';
          }
          this.startResendCooldown();
          this.success_notification(this.i18n.t('text_otp_sent'));
        } else {
          this.handleOtpError(response);
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  change_phone() {
    this.ui_controls.step = 1;
    this.otp.code = '';
    this.clearOtpTimer();
    this.otpCooldown = 0;
    this.otpLocked = false;
  }

  // --- Step 3: details -> submit -> email verification_id ----------------

  submit_details() {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    if (this.register.first_name.length === 0) {
      this.error_notification(this.i18n.t('text_first_name_required'));
      return;
    }
    if (this.register.last_name.length === 0) {
      this.error_notification(this.i18n.t('text_last_name_required'));
      return;
    }
    if (this.register.email.length === 0) {
      this.error_notification(this.i18n.t('text_email_required'));
      return;
    }
    if (!GlobalComponent.validateEmail(this.register.email)) {
      this.error_notification(this.i18n.t('text_invalid_email_format'));
      return;
    }
    if (this.register.password.length < 8) {
      this.error_notification(this.i18n.t('text_password_min_length'));
      return;
    }
    if (this.register.password !== this.register.confirm_password) {
      this.error_notification(this.i18n.t('text_passwords_do_not_match'));
      return;
    }
    if (!this.register.accepted_terms) {
      this.error_notification(this.i18n.t('text_accept_terms_required'));
      return;
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/register/submit', {
      registration_token: this.registrationToken,
      email: this.register.email,
      password: this.register.password,
      first_name: this.register.first_name,
      last_name: this.register.last_name,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const vid = response.data?.verification_id;
          if (typeof vid !== 'string' || vid.length === 0) {
            this.error_notification(this.i18n.t('text_request_failed'));
            return;
          }
          this.emailVerificationId = vid;
          this.otp.code = '';
          this.ui_controls.step = 4;
          this.startResendCooldown();
          this.success_notification(this.i18n.t('text_otp_sent'));
        } else {
          this.handleSubmitError(response);
        }
      },
      error: (e) => {
        const code = e?.error?.error?.code ?? e?.error?.error_code;
        if (code === 'AUTH_INVALID_TOKEN') {
          this.restartExpired();
          return;
        }
        this.handleHttpError(e);
      },
    });
  }

  /** Map submit-specific errors: email-taken inline; expired token -> restart. */
  private handleSubmitError(response: any) {
    const code = response?.error_code;
    if (code === 'AUTH_INVALID_TOKEN') {
      this.restartExpired();
      return;
    }
    if (code === 'OTP_RATE_LIMITED') {
      this.startLockout(this.readRetryAfter(response));
    }
    this.error_notification(this.mapErrorMessage(response));
  }

  private restartExpired() {
    this.ui_controls.loading = false;
    this.ui_controls.step = 1;
    this.registrationToken = '';
    this.phoneVerificationId = '';
    this.emailVerificationId = '';
    this.otp.code = '';
    this.clearOtpTimer();
    this.otpCooldown = 0;
    this.otpLocked = false;
    this.error_notification(this.i18n.t('text_session_expired_restart'));
  }

  // --- Step 4: confirm email OTP -> tokens -> auto-login -----------------

  confirm_email() {
    if (this.otp.code.length === 0) {
      this.error_notification(this.i18n.t('text_otp_required'));
      return;
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/register/confirm-email', {
      verification_id: this.emailVerificationId,
      code: this.otp.code,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const userToStore = transformV3LoginResponse(response.data) ?? response.data;
          Preferences.set({ key: 'user', value: JSON.stringify(userToStore) });

          const authBody = {
            id: typeof (userToStore as any)?.id === 'number' ? (userToStore as any).id : 0,
            token: typeof (userToStore as any)?.token === 'string' ? (userToStore as any).token : '',
          };
          this.cartMerge.mergeIfAny(authBody)
            .catch((err) => console.warn('[Register] cart merge failed (non-fatal)', err));

          void this.pushManager.onSignedIn();

          this.clearOtpTimer();
          this.router.navigate(['/account'], { replaceUrl: true });
          this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
        } else {
          this.handleOtpError(response);
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  resend_email_otp() {
    // Re-issue the email OTP by re-calling submit with the same data.
    if (this.otpCooldown > 0) return; // cooldown / lockout active
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/register/submit', {
      registration_token: this.registrationToken,
      email: this.register.email,
      password: this.register.password,
      first_name: this.register.first_name,
      last_name: this.register.last_name,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const vid = response.data?.verification_id;
          if (typeof vid === 'string' && vid.length > 0) {
            this.emailVerificationId = vid;
            this.otp.code = '';
          }
          this.startResendCooldown();
          this.success_notification(this.i18n.t('text_otp_sent'));
        } else {
          this.handleSubmitError(response);
        }
      },
      error: (e) => {
        const code = e?.error?.error?.code ?? e?.error?.error_code;
        if (code === 'AUTH_INVALID_TOKEN') {
          this.restartExpired();
          return;
        }
        this.handleHttpError(e);
      },
    });
  }

  back_to_details() {
    this.ui_controls.step = 3;
    this.otp.code = '';
    this.clearOtpTimer();
    this.otpCooldown = 0;
    this.otpLocked = false;
  }

  // --- Error mapping ------------------------------------------------------

  /**
   * Map known v3 error codes to user-friendly i18n strings, falling
   * back to the server-provided message.
   */
  private mapErrorMessage(response: any): string {
    const code = response?.error_code;
    if (typeof code === 'string') {
      switch (code) {
        case 'CONFLICT_EMAIL_TAKEN':
          return this.i18n.t('text_email_already_registered');
        case 'CONFLICT_PHONE_TAKEN':
          return this.i18n.t('text_phone_already_registered');
        case 'OTP_VERIFICATION_FAILED':
          return this.i18n.t('text_otp_verification_failed');
        case 'OTP_RATE_LIMITED':
          return this.i18n.t('text_otp_rate_limited');
        case 'OTP_PROVIDER_ERROR':
          return this.i18n.t('text_otp_provider_error');
        case 'AUTH_INVALID_TOKEN':
          return this.i18n.t('text_session_expired_restart');
        default:
          break;
      }
    }

    // A 422 validation failure ("One or more fields failed validation") is
    // useless without the offending field. The v3 envelope carries the
    // per-field messages in error_details ({ field: [msg, …] }) — surface the
    // first one instead of the generic banner so the user knows what to fix.
    const details = response?.error_details;
    if (details && typeof details === 'object') {
      for (const key of Object.keys(details)) {
        const list = (details as any)[key];
        const msg = Array.isArray(list) ? list[0] : list;
        if (typeof msg === 'string' && msg.trim() !== '') {
          return msg;
        }
      }
    }
    return response?.message ?? this.i18n.t('text_request_failed');
  }

  /** Defensive rxjs-error-channel mapping (err?.error?.error?.code). */
  private handleHttpError(e: any) {
    this.ui_controls.loading = false;
    const code = e?.error?.error?.code ?? e?.error?.error_code;
    if (typeof code === 'string') {
      if (code === 'OTP_RATE_LIMITED') {
        this.startLockout(this.readRetryAfter(e));
      }
      this.error_notification(this.mapErrorMessage({ error_code: code }));
      return;
    }
    console.error(e);
    this.error_notification(this.i18n.t('text_request_failed'));
  }

  // --- Resend cooldown + rate-limit lockout ------------------------------

  /**
   * Centralised OTP-error handler for the success-channel envelope.
   * On OTP_RATE_LIMITED it starts a lockout for `retry_after` seconds
   * (disabling Resend + Verify); all errors surface the mapped message.
   */
  private handleOtpError(response: any) {
    if (response?.error_code === 'OTP_RATE_LIMITED') {
      this.startLockout(this.readRetryAfter(response));
    }
    this.error_notification(this.mapErrorMessage(response));
  }

  /**
   * Read retry_after (seconds) from either envelope: the success-channel
   * error envelope (top-level or under error_details) or the rxjs error
   * body (e.error / e.error.error). Falls back to the default lockout.
   */
  private readRetryAfter(src: any): number {
    const candidates = [
      src?.retry_after,
      src?.error_details?.retry_after,
      src?.error?.retry_after,
      src?.error?.error?.retry_after,
      src?.error?.error?.details?.retry_after,
    ];
    for (const c of candidates) {
      const n = Number(c);
      if (Number.isFinite(n) && n > 0) return Math.ceil(n);
    }
    return this.DEFAULT_LOCKOUT;
  }

  /** Short cooldown after a successful send: disables Resend only. */
  private startResendCooldown() {
    this.otpLocked = false;
    this.startCountdown(this.DEFAULT_RESEND_COOLDOWN);
  }

  /** Longer server lockout: disables Resend AND Verify for `seconds`. */
  private startLockout(seconds: number) {
    this.otpLocked = true;
    this.startCountdown(seconds > 0 ? seconds : this.DEFAULT_LOCKOUT);
  }

  private startCountdown(seconds: number) {
    this.clearOtpTimer();
    this.otpCooldown = Math.max(0, Math.ceil(seconds));
    if (this.otpCooldown === 0) {
      this.otpLocked = false;
      return;
    }
    this.otpTimer = setInterval(() => {
      this.otpCooldown -= 1;
      if (this.otpCooldown <= 0) {
        this.clearOtpTimer();
        this.otpCooldown = 0;
        this.otpLocked = false;
      }
    }, 1000);
  }

  private clearOtpTimer() {
    if (this.otpTimer) {
      clearInterval(this.otpTimer);
      this.otpTimer = null;
    }
  }

  error_notification(message: string) {
    this.toast.error(message, { position: 'top-center' });
  }

  success_notification(message: string) {
    this.toast.success(message, { position: 'top-center' });
  }

  // --- Native social sign-in (Google / Apple via Firebase) ---------------

  /** Native Google sign-in -> Firebase ID token -> POST /auth/social. */
  google_signin() {
    void this.socialSignIn('google');
  }

  /** Native Apple sign-in -> Firebase ID token -> POST /auth/social. */
  apple_signin() {
    void this.socialSignIn('apple');
  }

  /**
   * Shared native social sign-in path (mirrors the login page). Runs the
   * native Firebase sign-in, reads the Firebase ID token (our API verifies
   * THAT token), exchanges it at POST /auth/social for the standard login
   * envelope, then either logs the user straight in (verified phone) or
   * drops them into the phone-after-social gate (step 5). Cancelled sign-in
   * is swallowed quietly.
   */
  private async socialSignIn(provider: 'google' | 'apple'): Promise<void> {
    if (this.socialLoading || this.ui_controls.loading) return;
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }

    this.socialLoading = true;
    try {
      if (provider === 'google') {
        await FirebaseAuthentication.signInWithGoogle();
      } else {
        await FirebaseAuthentication.signInWithApple();
      }

      const { token } = await FirebaseAuthentication.getIdToken();
      if (!token) {
        this.socialLoading = false;
        this.error_notification(this.i18n.t('text_social_signin_failed'));
        return;
      }

      this.ui_controls.loading = true;
      this.networkAdapter.post_v3('POST /auth/social', { id_token: token }).subscribe({
        next: (response: any) => {
          this.socialLoading = false;
          this.ui_controls.loading = false;
          if (response.response_code === 200 && response.status === 'success') {
            this.onSocialLoginSuccess(response.data);
          } else {
            this.error_notification(response.message || this.i18n.t('text_social_signin_failed'));
          }
        },
        error: (e) => {
          this.socialLoading = false;
          this.handleHttpError(e);
        },
      });
    } catch (err) {
      this.socialLoading = false;
      if (this.isCancelledSignIn(err)) return;
      console.error('[Register] social sign-in failed', err);
      this.error_notification(this.i18n.t('text_social_signin_failed'));
    }
  }

  private isCancelledSignIn(err: unknown): boolean {
    const msg = (err && typeof err === 'object' && 'message' in err
      ? String((err as any).message)
      : String(err)).toLowerCase();
    return (
      msg.includes('cancel') ||
      msg.includes('canceled') ||
      msg.includes('1001') ||
      msg.includes('12501')
    );
  }

  /**
   * Post-social-login: persist the user blob, then either auto-login
   * (verified phone) or enter the phone-after-social gate (step 5) for a
   * fresh social account (is_phone_verified=false).
   */
  private onSocialLoginSuccess(data: any): void {
    const userToStore = transformV3LoginResponse(data) ?? data;
    Preferences.set({ key: 'user', value: JSON.stringify(userToStore) });
    void this.pushManager.onSignedIn();

    if ((userToStore as any)?.is_phone_verified === false) {
      this.socialAuthToken = typeof (userToStore as any)?.token === 'string'
        ? (userToStore as any).token
        : '';
      this.socialPhone = '';
      this.otp.code = '';
      this.ui_controls.step = 5;
      return;
    }

    // Verified phone already (returning social user): merge cart + go home.
    const authBody = {
      id: typeof (userToStore as any)?.id === 'number' ? (userToStore as any).id : 0,
      token: typeof (userToStore as any)?.token === 'string' ? (userToStore as any).token : '',
    };
    this.cartMerge.mergeIfAny(authBody)
      .catch((e) => console.warn('[Register] cart merge failed (non-fatal)', e));
    this.router.navigate(['/account'], { replaceUrl: true });
  }

  // --- Phone-after-social gate (steps 5 + 6) -----------------------------

  /** Full E.164-ish social phone for the API ("+971" + national digits). */
  get fullSocialPhone(): string {
    const national = this.socialPhone.replace(/^0+/, '').replace(/\D/g, '');
    return `${this.register.countryCode}${national}`;
  }

  /** Send the OTP for the social user's phone. POST /me/phone. */
  send_social_phone() {
    if (this.ui_controls.loading) return;
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    if (this.socialPhone.replace(/\D/g, '').length === 0) {
      this.error_notification(this.i18n.t('text_phone_required'));
      return;
    }
    this.ui_controls.loading = true;
    this.networkAdapter.post_v3(
      'POST /me/phone',
      { phone: this.fullSocialPhone, country_code: mapDialToIso(this.register.countryCode) },
      { authToken: this.socialAuthToken },
    ).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          this.otp.code = '';
          this.ui_controls.step = 6;
          this.startResendCooldown();
          this.success_notification(this.i18n.t('text_otp_sent'));
        } else {
          this.handleOtpError(response);
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  /** Verify the social user's phone OTP. POST /me/phone/verify -> /account. */
  verify_social_phone() {
    if (this.otp.code.length === 0) {
      this.error_notification(this.i18n.t('text_otp_required'));
      return;
    }
    this.ui_controls.loading = true;
    this.networkAdapter.post_v3(
      'POST /me/phone/verify',
      { code: this.otp.code },
      { authToken: this.socialAuthToken },
    ).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          void this.finishSocialGate();
        } else {
          this.handleOtpError(response);
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  resend_social_phone_otp() {
    if (this.otpCooldown > 0) return;
    this.send_social_phone();
  }

  change_social_phone() {
    this.ui_controls.step = 5;
    this.otp.code = '';
    this.clearOtpTimer();
    this.otpCooldown = 0;
    this.otpLocked = false;
  }

  /** Flip the cached phone-verified flag + navigate home. */
  private async finishSocialGate(): Promise<void> {
    try {
      const ret = await Preferences.get({ key: 'user' });
      if (ret.value) {
        const blob = JSON.parse(ret.value);
        blob.is_phone_verified = true;
        blob.phone = this.fullSocialPhone;
        await Preferences.set({ key: 'user', value: JSON.stringify(blob) });
      }
    } catch {
      /* non-fatal */
    }
    this.clearOtpTimer();
    this.router.navigate(['/account'], { replaceUrl: true });
  }

  sign_in() {
    this.router.navigate(['/', 'login']);
  }

  forgot_password() {
    this.router.navigate(['/', 'reset']);
  }
}
