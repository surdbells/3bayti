import {Component, OnDestroy, OnInit} from '@angular/core';
import {
  IonContent,
  IonButton,
  Platform, IonText
} from '@ionic/angular/standalone';
import { Preferences } from '@capacitor/preferences';
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

    /** Active view state. */
    view: 'choice' | 'phone' | 'email' | 'otp' | 'password' = 'choice';

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
      this.success_notification(this.i18n.t('text_otp_sent'));
    } else {
      this.show_error(this.mapErrorMessage(response));
    }
  }

  /** Re-issue the OTP (re-call send for the active channel). */
  resendOtp() {
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
          this.show_error(this.mapErrorMessage(response));
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
    const code = e?.error?.error?.code;
    if (typeof code === 'string') {
      this.show_error(this.mapErrorMessage({ error_code: code }));
      return;
    }
    console.error(e);
    this.show_error(this.i18n.t('text_request_failed'));
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
  google_signin(): void {
    this.show_error(this.i18n.t('text_google_signin_unavailable'));
  }
  apple_signin(): void {
    this.show_error(this.i18n.t('text_apple_signin_unavailable'));
  }

  goHome() {
    this.router.navigate(['/', 'home']);
  }
}
