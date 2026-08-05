import {Component, OnDestroy, OnInit} from '@angular/core';
import {
  IonContent,
  IonButton,
  Platform, IonText
} from '@ionic/angular/standalone';
import { Preferences } from '@capacitor/preferences';
import { FirebaseAuthentication } from '@capacitor-firebase/authentication';
import { parseSocialAuthError, socialAuthErrorMessage } from '../../core/auth/social-auth-error';
import { ConnectionService } from '../../service/connection.service';
import { Subscription } from 'rxjs';

import { FormsModule } from '@angular/forms';
import {Router} from "@angular/router";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {transformV3LoginResponse} from "./login-response.transform";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {GlobalComponent} from "../../global-component";
import {BlockerService} from "../../blocker.service";
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";
import { CartMergeService } from '../../core/services/cart-merge.service';
import { PushManager } from '../../core/services/push-manager.service';

import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { DIAL_CODES, DialCode, mapDialToIso } from '../shared/dial-codes';

/**
 * Mobile login page — passwordless-first redesign (Chipper-style).
 *
 * A single page hosting several view states (no router sub-pages), driven
 * by `view`:
 *
 *   'choice'   How would you like to log in? -> two option cards:
 *              Phone number / Email.
 *   'phone'    Dial-code picker (reuses DIAL_CODES + ax-bottom-sheet) +
 *              phone field. Next -> POST /auth/otp-login/send
 *              { channel:'phone', country_code, phone } -> verification_id
 *              -> 'otp'.
 *   'email'    Email field. Next -> POST /auth/otp-login/send
 *              { channel:'email', email } -> verification_id -> 'otp'.
 *              Also a "Log in with password instead" link -> 'password'.
 *   'otp'      6-digit code input + resend + destination label.
 *              Verify -> POST /auth/otp-login/verify { verification_id, code }
 *              -> SAME envelope as POST /auth/login -> success block.
 *   'password' Legacy email + password form (preserved). Submit ->
 *              POST /auth/login -> success block.
 *
 * The "success block" is shared by both verify and the password flow:
 * transformV3LoginResponse -> Preferences.set('user') -> push register ->
 * cart merge -> navigate /account. (Behaviour-preserving vs the old page.)
 *
 * Anti-enumeration: /auth/otp-login/send always returns a verification_id,
 * even for an unknown user, so the UI never reveals whether an account
 * exists; a bad/expired code only fails at the verify step.
 */
@Component({
  selector: 'app-login',
  templateUrl: './login.page.html',
  styleUrls: ['./login.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonButton,
    FormsModule,
    IonText,
    TranslatePipe,
    AxLoaderComponent,
    AxTextFieldComponent,
    AxBottomSheetComponent,
    AxIconComponent,
  ]
})
export class LoginPage implements OnInit, OnDestroy {
    isOnline = true;
    private sub: Subscription;

    /**
     * Active view state.
     *
     * The two extra states 'social-phone' / 'social-otp' implement the
     * phone-after-social gate: a freshly social-signed-in account whose
     * is_phone_verified is false must capture + verify a phone before it
     * can proceed to /account.
     */
    view: 'choice' | 'phone' | 'email' | 'otp' | 'password' | 'social-phone' | 'social-otp' = 'choice';

    /** True while a native Google/Apple sign-in is in flight (button spinner). */
    socialLoading = false;

    /**
     * Auth token of the freshly social-signed-in user, held while the
     * phone-after-social gate runs (POST /me/phone + /me/phone/verify use it).
     * The user blob is already persisted in Preferences before we enter the
     * gate; we keep the token here to authenticate the phone calls without a
     * Preferences round-trip on each tap.
     */
    private socialAuthToken = '';
    /** Phone the social user is verifying (separate model from login `phone`). */
    socialPhone = '';

    /** Dial-code picker state (reuses the register/profile pattern). */
    isCountryCodeOpen = false;
    readonly dialCodes: DialCode[] = DIAL_CODES;
    codeSearch = '';
    countryCode = '+971';

    /** Phone-mode + email-mode inputs. */
    phone = '';
    otpEmail = '';

    /** OTP-entry state. */
    otp = { code: '' };
    /** The channel the current OTP was sent over. */
    otpChannel: 'phone' | 'email' = 'phone';
    /** Human-readable destination shown on the OTP screen. */
    otpDestination = '';
    /** verification_id issued by send, consumed by verify. */
    verificationId = '';

    /**
     * Resend cooldown / rate-limit lockout state.
     *
     * `otpCooldown` is the remaining seconds the Resend control is disabled.
     * A short cooldown (DEFAULT_RESEND_COOLDOWN) starts after each successful
     * send; a LONGER lockout (server retry_after) starts on an OTP_RATE_LIMITED
     * 429. `otpLocked` distinguishes the hard server lockout (also disables
     * Verify) from the soft post-send cooldown.
     */
    otpCooldown = 0;
    otpLocked = false;
    private readonly DEFAULT_RESEND_COOLDOWN = 30;
    private readonly DEFAULT_LOCKOUT = 60;
    private otpTimer: ReturnType<typeof setInterval> | null = null;

    constructor(
      private net: ConnectionService,
      private platform: Platform,
      private router: Router,
      private blocker: BlockerService,
      private networkAdapter: MobileNetworkAdapter,
      private toast: AxNotificationService,
      private i18n: I18nService,
      private cartMerge: CartMergeService,
      private pushManager: PushManager,
    ) {
      this.net.setReachabilityCheck(true);
      this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  ngOnInit() {
    this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
    this.getObject();
  }
  ngOnDestroy(): void {
    this.blocker.unblock(); // ✅ restore when leaving
    this.sub?.unsubscribe();
    this.clearOtpTimer();
  }
  single_user = {
    id: 0,
    token: "",
    first_name: "",
    last_name: "",
    user_type: "",
    email: "",
    phone: "",
    avatar: "",
    location: "",
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false,
    is_store_active: false,
    is_store_approved: false,
  }
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      //
    }else{
      this.single_user = JSON.parse(ret.value);
      this.router.navigate(['/', 'account']);
    }
  }
  ui_controls = {
    page_loading: false,
    login_loading: false,
    logged_in: false
  };
  login = {
    email: "",
    password: "",
    remember: false,
  };

  // ===== Navigation between views =========================================

  /** Selected dial-code metadata (flag) for the picker button. */
  get selectedDial(): DialCode | undefined {
    return this.dialCodes.find(c => c.code === this.countryCode);
  }

  /** Full E.164-ish phone for display / API ("+971" + national digits). */
  get fullPhone(): string {
    const national = this.phone.replace(/^0+/, '').replace(/\D/g, '');
    return `${this.countryCode}${national}`;
  }

  filteredDialCodes(): DialCode[] {
    const q = this.codeSearch.trim().toLowerCase();
    if (q.length === 0) return this.dialCodes;
    return this.dialCodes.filter(c =>
      c.name.toLowerCase().includes(q) || c.code.includes(q));
  }

  selectCode(c: DialCode) {
    this.countryCode = c.code;
  }

  goChoice() {
    this.view = 'choice';
    this.otp.code = '';
  }
  goPhone() {
    this.view = 'phone';
  }
  goEmail() {
    this.view = 'email';
  }
  goPassword() {
    this.view = 'password';
  }

  // ===== Step: send OTP (phone or email) ==================================

  sendPhoneOtp() {
    if (this.ui_controls.login_loading) return;
    if (!this.guardOnline()) return;
    if (this.phone.replace(/\D/g, '').length === 0) {
      this.show_error(this.i18n.t('text_phone_required'));
      return;
    }
    this.ui_controls.login_loading = true;
    this.networkAdapter.post_v3('POST /auth/otp-login/send', {
      channel: 'phone',
      country_code: mapDialToIso(this.countryCode),
      phone: this.fullPhone,
    }).subscribe({
      next: (response: any) => this.onSendResponse(response, 'phone', this.fullPhone),
      error: (e) => this.handleHttpError(e),
    });
  }

  sendEmailOtp() {
    if (this.ui_controls.login_loading) return;
    if (!this.guardOnline()) return;
    if (this.otpEmail.length === 0) {
      this.show_error(this.i18n.t('text_email_required'));
      return;
    }
    if (!GlobalComponent.validateEmail(this.otpEmail)) {
      this.show_error(this.i18n.t('text_invalid_email_detailed'));
      return;
    }
    this.ui_controls.login_loading = true;
    this.networkAdapter.post_v3('POST /auth/otp-login/send', {
      channel: 'email',
      email: this.otpEmail,
    }).subscribe({
      next: (response: any) => this.onSendResponse(response, 'email', this.otpEmail),
      error: (e) => this.handleHttpError(e),
    });
  }

  private onSendResponse(response: any, channel: 'phone' | 'email', destination: string) {
    this.ui_controls.login_loading = false;
    if (response.response_code === 200 && response.status === 'success') {
      const vid = response.data?.verification_id;
      if (typeof vid !== 'string' || vid.length === 0) {
        this.show_error(this.i18n.t('text_request_failed'));
        return;
      }
      this.verificationId = vid;
      this.otpChannel = channel;
      this.otpDestination = destination;
      this.otp.code = '';
      this.view = 'otp';
      this.startResendCooldown();
      this.success_notification(this.i18n.t('text_otp_sent'));
    } else {
      this.handleOtpError(response);
    }
  }

  /** Re-issue the OTP (re-call send for the active channel). */
  resendOtp() {
    if (this.otpCooldown > 0) return; // cooldown / lockout active
    if (this.otpChannel === 'phone') {
      this.sendPhoneOtp();
    } else {
      this.sendEmailOtp();
    }
  }

  /** Back from OTP-entry to the relevant entry screen. */
  changeDestination() {
    this.view = this.otpChannel === 'phone' ? 'phone' : 'email';
    this.otp.code = '';
    this.clearOtpTimer();
    this.otpCooldown = 0;
    this.otpLocked = false;
  }

  // ===== Step: verify OTP -> login envelope -> success ====================

  verifyOtp() {
    if (this.otp.code.length === 0) {
      this.show_error(this.i18n.t('text_otp_required'));
      return;
    }
    this.ui_controls.login_loading = true;
    this.networkAdapter.post_v3('POST /auth/otp-login/verify', {
      verification_id: this.verificationId,
      code: this.otp.code,
    }).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.onLoginSuccess(response.data);
        } else {
          this.ui_controls.login_loading = false;
          this.handleOtpError(response);
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  // ===== Legacy email + password login (preserved) ========================

  async signIn() {
    if(this.isOnline){
      if (this.login.email.length == 0) {
        this.show_error(this.i18n.t('text_email_required'));
        return;
      }
      if (!GlobalComponent.validateEmail(this.login.email)) {
        this.show_error(this.i18n.t('text_invalid_email_detailed'));
        return;
      }
      if (this.login.password.length == 0) {
        this.show_error(this.i18n.t('text_password_required'));
        return;
      }
      if (this.login.remember) {
        Preferences.set({key: 'keep_session', value: JSON.stringify(this.login)});
      }
      if (!this.login.remember) {
        Preferences.remove({key: 'keep_session'});
      }
      this.ui_controls.login_loading = true;
      // Direct v3 (POST /v3/auth/login). transformV3LoginResponse maps the
      // v3 envelope -> single_user. See login-response.transform.ts.
      this.networkAdapter.post_v3('POST /auth/login', this.login)
        .subscribe(({
          next: (response: any) => {
            if (response.response_code === 200 && response.status === "success") {
              this.onLoginSuccess(response.data);
            }else{
              this.ui_controls.logged_in = false;
              this.ui_controls.login_loading = false;
              this.show_error(response.message);
              return;
            }
          },
          error: (e) => {
            this.ui_controls.logged_in = false;
            this.ui_controls.login_loading = false;
            this.show_error(e.toString());
            return;
          },
          complete: () => {
            console.info('complete');
          }
        }))
    }else {
      this.show_error(this.i18n.t('text_offline_check_connection'))
    }
  }

  /**
   * Shared post-authentication block. Consumes a v3 login envelope's
   * `data` (the SAME shape POST /auth/login returns), stores the user,
   * registers push, merges the guest cart, then navigates to /account.
   *
   * Used by both the OTP verify flow and the legacy password flow so the
   * proven success behaviour stays identical across login methods.
   */
  private onLoginSuccess(data: any) {
    // Transform v3 login response shape -> legacy single_user shape before
    // storage; fall back to data as-is for any unexpected payload.
    const userToStore = transformV3LoginResponse(data) ?? data;
    Preferences.set({
      key: 'user',
      value: JSON.stringify(userToStore)
    });

    // Phone-after-social gate: a freshly social-signed-in account has
    // is_phone_verified=false. Persist the session (above) but DON'T navigate
    // home yet — route into the phone-capture step instead, authenticated with
    // the just-issued token. Email/OTP/password logins always have a verified
    // phone, so they fall through to the normal success path below.
    if ((userToStore as any)?.is_phone_verified === false) {
      this.socialAuthToken = typeof (userToStore as any)?.token === 'string'
        ? (userToStore as any).token
        : '';
      // Best-effort: register push + merge cart now too (the account is
      // already valid), then drop the user into the phone gate.
      void this.pushManager.onSignedIn();
      this.ui_controls.login_loading = false;
      this.socialPhone = '';
      this.otp.code = '';
      this.view = 'social-phone';
      return;
    }

    // Request push permission + register this device's token. Fire-and-
    // forget: PushManager swallows errors and is a no-op on web.
    void this.pushManager.onSignedIn();

    // Merge the device-local guest cart into the server cart, then
    // navigate. Safe when local is empty; never blocks login.
    const authBody = {
      id: typeof (userToStore as any)?.id === 'number'
        ? (userToStore as any).id
        : 0,
      token: typeof (userToStore as any)?.token === 'string'
        ? (userToStore as any).token
        : '',
    };
    this.cartMerge.mergeIfAny(authBody).then((result) => {
      if (result.attempted && !result.success) {
        console.warn('[Login] cart merge failed (non-fatal)', result.error);
      }
      if (result.attempted && result.success && result.skipped.length > 0) {
        this.toast.info(this.i18n.t('text_cart_merge_skipped_some'));
      }
    }).catch((err) => {
      console.warn('[Login] cart merge threw (non-fatal)', err);
    });

    this.ui_controls.login_loading = false;
    this.router.navigate(['/account'], { replaceUrl: true });
    this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
  }

  // ===== Errors ===========================================================

  private guardOnline(): boolean {
    if (!this.isOnline) {
      this.show_error(this.i18n.t('text_offline_check_connection'));
      return false;
    }
    return true;
  }

  /** Map known v3 error codes to i18n strings; fall back to server message. */
  private mapErrorMessage(response: any): string {
    const code = response?.error_code;
    if (typeof code === 'string') {
      switch (code) {
        case 'OTP_VERIFICATION_FAILED':
          return this.i18n.t('text_otp_verification_failed');
        case 'OTP_RATE_LIMITED':
          return this.i18n.t('text_otp_rate_limited');
        case 'OTP_PROVIDER_ERROR':
          return this.i18n.t('text_otp_provider_error');
        default:
          break;
      }
    }
    return response?.message ?? this.i18n.t('text_request_failed');
  }

  private handleHttpError(e: any) {
    this.ui_controls.login_loading = false;
    const code = e?.error?.error?.code ?? e?.error?.error_code;
    if (typeof code === 'string') {
      if (code === 'OTP_RATE_LIMITED') {
        this.startLockout(this.readRetryAfter(e));
      }
      this.show_error(this.mapErrorMessage({ error_code: code }));
      return;
    }
    console.error(e);
    this.show_error(this.i18n.t('text_request_failed'));
  }

  // ===== Resend cooldown + rate-limit lockout =============================

  /**
   * Centralised OTP-error handler for the success-channel envelope.
   * On OTP_RATE_LIMITED it starts a lockout for `retry_after` seconds
   * (disabling Resend + Verify); all errors surface the mapped message.
   */
  private handleOtpError(response: any) {
    if (response?.error_code === 'OTP_RATE_LIMITED') {
      this.startLockout(this.readRetryAfter(response));
    }
    this.show_error(this.mapErrorMessage(response));
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

  show_error(message: string) {
    this.toast.error(message, {
      position: 'top-center'
    });
  }
  show_success(message: string, position: any) {
    this.toast.success(message, {
      position: 'top-center'
    });
  }
  success_notification(message: string) {
    this.toast.success(message, { position: 'top-center' });
  }

  user_register() {
    this.router.navigate(['/', 'register']);
  }
  forgot_password() {
    this.router.navigate(['/', 'reset']);
  }
  // ===== Native social sign-in (Google / Apple via Firebase) ==============

  /** Native Google sign-in -> Firebase ID token -> POST /auth/social. */
  google_signin(): void {
    void this.socialSignIn('google');
  }

  /** Native Apple sign-in -> Firebase ID token -> POST /auth/social. */
  apple_signin(): void {
    void this.socialSignIn('apple');
  }

  /**
   * Shared native social sign-in path.
   *
   * Runs the native Firebase sign-in for the chosen provider, reads the
   * Firebase ID token (NOT the provider token — our API verifies the
   * Firebase token), exchanges it at POST /auth/social for the standard
   * login envelope, then funnels into onLoginSuccess() — the SAME success
   * block the email/password + OTP flows use (persist user, push register,
   * cart merge, navigate / or the phone-after-social gate).
   *
   * User-cancelled sign-in is swallowed quietly (no error toast).
   */
  private async socialSignIn(provider: 'google' | 'apple'): Promise<void> {
    if (this.socialLoading || this.ui_controls.login_loading) return;
    if (!this.guardOnline()) return;

    this.socialLoading = true;
    try {
      if (provider === 'google') {
        await FirebaseAuthentication.signInWithGoogle();
      } else {
        await FirebaseAuthentication.signInWithApple();
      }

      // The Firebase ID token is what POST /auth/social verifies.
      const { token } = await FirebaseAuthentication.getIdToken();
      if (!token) {
        this.socialLoading = false;
        this.show_error(this.i18n.t('text_social_signin_failed'));
        return;
      }

      this.ui_controls.login_loading = true;
      this.networkAdapter.post_v3('POST /auth/social', { id_token: token }).subscribe({
        next: (response: any) => {
          this.socialLoading = false;
          if (response.response_code === 200 && response.status === 'success') {
            this.onLoginSuccess(response.data);
          } else {
            this.ui_controls.login_loading = false;
            this.show_error(response.message || this.i18n.t('text_social_signin_failed'));
          }
        },
        error: (e) => {
          this.socialLoading = false;
          this.handleHttpError(e);
        },
      });
    } catch (err) {
      this.socialLoading = false;
      const info = parseSocialAuthError(err);
      // User cancelled the native sheet — stay quiet (no error toast).
      if (info.cancelled) return;
      // Log the real code + message so the failure is diagnosable off-device,
      // and show the user a message that names the cause (or carries the code
      // for support) instead of a bare "try again".
      console.error('[Login] social sign-in failed', {
        provider,
        code: info.code,
        message: info.rawMessage,
      });
      this.show_error(socialAuthErrorMessage(info, (k) => this.i18n.t(k)));
    }
  }

  // ===== Phone-after-social gate (capture + verify phone) =================

  /** Send the OTP for the social user's phone. POST /me/phone. */
  sendSocialPhone(): void {
    if (this.ui_controls.login_loading) return;
    if (!this.guardOnline()) return;
    if (this.socialPhone.replace(/\D/g, '').length === 0) {
      this.show_error(this.i18n.t('text_phone_required'));
      return;
    }
    this.ui_controls.login_loading = true;
    this.networkAdapter.post_v3(
      'POST /me/phone',
      {
        phone: this.fullSocialPhone,
        country_code: mapDialToIso(this.countryCode),
      },
      { authToken: this.socialAuthToken },
    ).subscribe({
      next: (response: any) => {
        this.ui_controls.login_loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          // Capture the verification_id — POST /me/phone/verify REQUIRES it
          // alongside the code (same contract as the normal OTP flow).
          this.verificationId = response.data?.verification_id ?? '';
          this.otp.code = '';
          this.view = 'social-otp';
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
  verifySocialPhone(): void {
    if (this.otp.code.length === 0) {
      this.show_error(this.i18n.t('text_otp_required'));
      return;
    }
    this.ui_controls.login_loading = true;
    this.networkAdapter.post_v3(
      'POST /me/phone/verify',
      // verification_id is required by the API; strip any spaces the OTP
      // field may carry so the code matches the server's ^\d{4,6}$ rule.
      { verification_id: this.verificationId, code: this.otp.code.replace(/\D/g, '') },
      { authToken: this.socialAuthToken },
    ).subscribe({
      next: (response: any) => {
        this.ui_controls.login_loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          // Phone now verified — flip the cached flag and continue home,
          // reusing the shared post-auth navigation (cart merge already ran
          // at social login; keep it idempotent + non-blocking).
          this.finishSocialGate();
        } else {
          this.handleOtpError(response);
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  /** Re-send the social-gate OTP (re-call POST /me/phone). */
  resendSocialPhoneOtp(): void {
    if (this.otpCooldown > 0) return;
    this.sendSocialPhone();
  }

  /** Back from the social OTP screen to the phone-entry screen. */
  changeSocialPhone(): void {
    this.view = 'social-phone';
    this.otp.code = '';
    this.clearOtpTimer();
    this.otpCooldown = 0;
    this.otpLocked = false;
  }

  /** Full E.164-ish social phone for the API ("+971" + national digits). */
  get fullSocialPhone(): string {
    const national = this.socialPhone.replace(/^0+/, '').replace(/\D/g, '');
    return `${this.countryCode}${national}`;
  }

  /**
   * Mark the cached user's phone as verified and navigate to /account.
   * Updates the Preferences blob so a relaunch doesn't re-trigger the gate,
   * then reuses the standard navigation tail.
   */
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
      /* non-fatal: navigation still proceeds */
    }
    this.clearOtpTimer();
    this.router.navigate(['/account'], { replaceUrl: true });
    this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
  }

  goHome() {
    this.router.navigate(['/', 'home']);
  }
}
