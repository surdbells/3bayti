import {Component, OnDestroy, OnInit} from '@angular/core';
import {
  IonContent,
  IonCol,
  IonGrid,
  IonRow,
  IonText,
  Platform,
  IonLabel,
  IonButton,
  IonSegmentButton,
  IonSegment,
  IonCheckbox,
  IonCard,
  IonCardHeader,
  IonCardTitle,
  IonCardContent
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
import {BlockerService} from "../../blocker.service";

import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';

import {transformLegacyRegisterRequest} from "./register-request.transform";
import {transformV3LoginResponse} from "../login/login-response.transform";
import {CartMergeService} from "../../core/services/cart-merge.service";

/**
 * Mobile registration page — restructured in M3.1.4c for v3.
 *
 * Flow (v3, replacing the legacy 3-stage MessageCentral flow)
 * ============================================================
 *
 *   Stage 1: collect all user info (single screen)
 *     - first_name, last_name, email, phone, password, confirm_password,
 *       accept_terms checkbox
 *     - On [Register]: client-side validate, transform body to v3 shape,
 *       POST /v3/auth/register via adapter
 *     - v3 server creates user (unverified), sends OTP to phone, returns
 *       {verification_id, user (minimal)}
 *     - On success: store verification_id, advance to Stage 2
 *
 *   Stage 2: confirm OTP (separate screen)
 *     - 6-digit code input
 *     - On [Confirm]: POST /v3/auth/confirm via adapter with
 *       {verification_id, code}
 *     - v3 server verifies code, marks phone verified, returns token pair
 *       and full user payload (same shape as /v3/auth/login)
 *     - On success: transformV3LoginResponse + Preferences.set, navigate
 *       to /account
 *     - [Resend code] button: POST /v3/auth/send-otp via adapter with
 *       {email}
 *     - [Back] link: return to Stage 1, preserving entered form data
 *
 * Stages tracked via `ui_controls.stage` enum (1 or 2). Replaces the
 * previous otpSent/otpValidated boolean pair.
 *
 * Why phone validation moved to Stage 1
 * ======================================
 * Legacy validated phone BEFORE collecting other info — phone was the
 * first thing the user saw. v3 collects everything at once and validates
 * phone via OTP AFTER registration (because v3 binds the OTP to the
 * user row, not to a standalone phone). The new UX is fewer steps
 * (2 vs 3) and closer to industry standard (Gmail, banks, etc).
 *
 * What was deleted vs M3.1.3-era register.page.ts
 * ================================================
 *   - getAuthToken() — was fetching a MessageCentral session token
 *     on ngOnInit. v3 doesn't need this.
 *   - send_otp() — was MessageCentral-direct via customer/sendOTP.
 *     Replaced by resend_otp() calling /v3/auth/send-otp.
 *   - validate_otp() — was MessageCentral validate via customer/validateOTP.
 *     Replaced by confirm_otp() calling /v3/auth/confirm.
 *   - signIn() — was auto-login after register. v3 doesn't need this:
 *     /v3/auth/confirm returns the same token pair /v3/auth/login does,
 *     so confirm IS the login.
 *   - smsToken, sendOTP, validateOtp, r_response, v_response — all
 *     MessageCentral-shape declarations. No longer used.
 *   - this.login — was the body for the post-register auto-signin.
 *
 * Error handling
 * ==============
 * The adapter normalizes v3 errors to the legacy envelope shape
 * `{response_code, status: 'error', message, error_code, data: null}`.
 * Specific v3 error codes are mapped to user-friendly i18n strings
 * where possible; otherwise the server-provided message is shown.
 */
@Component({
  selector: 'app-register',
  templateUrl: './register.page.html',
  styleUrls: ['./register.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    FormsModule,
    IonCol,
    IonGrid,
    IonRow,
    IonText,
    IonLabel,
    IonButton,
    IonSegmentButton,
    IonSegment,
    IonCheckbox,
    IonCard,
    IonCardHeader,
    IonCardTitle,
    IonCardContent,
    TranslatePipe,
    AxLoaderComponent,
    AxTextFieldComponent,
    AxBottomSheetComponent,
  ]
})
export class RegisterPage implements OnInit, OnDestroy {
  isOnline = true;
  private sub: Subscription;
  isTermsOpen = false; // controls the terms-of-service modal visibility

  constructor(
    private net: ConnectionService,
    private platform: Platform,
    private blocker: BlockerService,
    private router: Router,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private i18n: I18nService,
    private cartMerge: CartMergeService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
    this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
  }

  ngOnInit() {
    // No pre-fetched MessageCentral token under v3 — register endpoint
    // sends OTP internally. Page starts immediately ready for input.
  }

  /**
   * UI flow state.
   *   stage 1: collect form
   *   stage 2: confirm OTP code
   */
  ui_controls = {
    loading: false,
    stage: 1 as 1 | 2,
    termsArabic: true,
    termsEnglish: false,
  };

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

  /**
   * OTP confirmation state. Populated after Stage 1's /register call
   * succeeds; consumed by Stage 2's /confirm call.
   */
  otp = {
    verification_id: "",
    code: "",
  };

  /**
   * Stage 1: validate the form + POST /v3/auth/register.
   *
   * On success, stash the verification_id and advance to Stage 2.
   * Mobile UI bound to ui_controls.stage flips screens.
   */
  user_register() {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }

    // Client-side validation. Now includes phone (was in legacy send_otp).
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
    if (this.register.phone.length === 0) {
      this.error_notification(this.i18n.t('text_phone_required'));
      return;
    }
    if (this.register.password.length === 0) {
      this.error_notification(this.i18n.t('text_password_required'));
      return;
    }
    if (this.register.confirm_password.length === 0) {
      this.error_notification(this.i18n.t('text_passwords_do_not_match'));
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

    const v3Body = transformLegacyRegisterRequest(this.register);

    this.ui_controls.loading = true;
    // Direct v3 (POST /v3/auth/register). Route was already target='new';
    // v3Body is already the v3-shaped payload, so this is behaviour-preserving.
    this.networkAdapter.post_v3('POST /auth/register', v3Body)
      .subscribe({
        next: (response: any) => {
          this.ui_controls.loading = false;
          if (response.response_code === 200 && response.status === 'success') {
            // v3 register returns 201 Created on the wire; the adapter
            // normalises to 200 in the envelope (see mobile-network-
            // adapter.ts:112). Either way response.status === 'success'.
            const vid = response.data?.verification_id;
            if (typeof vid !== 'string' || vid.length === 0) {
              // Defensive: v3 always returns verification_id on success.
              // If it's missing, surface a clear error rather than
              // advancing into an unrecoverable Stage 2.
              this.error_notification(this.i18n.t('text_request_failed'));
              return;
            }
            this.otp.verification_id = vid;
            this.otp.code = '';
            this.ui_controls.stage = 2;
          } else {
            this.error_notification(this.mapErrorMessage(response));
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification(e?.toString() ?? this.i18n.t('text_request_failed'));
          this.ui_controls.loading = false;
        },
      });
  }

  /**
   * Stage 2: POST /v3/auth/confirm with verification_id + code.
   *
   * On success, the response has the same shape as /v3/auth/login —
   * we reuse the M3.1.3 transform and store the legacy single_user
   * blob in Preferences.
   */
  confirm_otp() {
    if (this.otp.code.length === 0) {
      this.error_notification(this.i18n.t('text_otp_required'));
      return;
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/confirm', {
      verification_id: this.otp.verification_id,
      code: this.otp.code,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const userToStore = transformV3LoginResponse(response.data) ?? response.data;
          Preferences.set({ key: 'user', value: JSON.stringify(userToStore) });
          // Merge the device-local guest cart into the server cart so a guest
          // who REGISTERS keeps everything they added (parity with login —
          // login.page already does this; register didn't). Non-blocking.
          const authBody = {
            id: typeof (userToStore as any)?.id === 'number' ? (userToStore as any).id : 0,
            token: typeof (userToStore as any)?.token === 'string' ? (userToStore as any).token : '',
          };
          this.cartMerge.mergeIfAny(authBody).catch((err) => console.warn('[Register] cart merge failed (non-fatal)', err));
          this.router.navigate(['/account'], { replaceUrl: true });
          this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
        } else {
          this.error_notification(this.mapErrorMessage(response));
        }
      },
      error: (e) => {
        console.error(e);
        this.error_notification(e?.toString() ?? this.i18n.t('text_request_failed'));
        this.ui_controls.loading = false;
      },
    });
  }

  /**
   * Re-send the registration OTP via /v3/auth/send-otp.
   *
   * v3 takes {email} (not phone — it looks up the user's stored phone
   * by email). Per the SendOtpController docblock, response is always
   * 200 with a verification_id-shaped string regardless of whether the
   * user exists (anti-enumeration). We update our local verification_id
   * with whatever the server returns.
   */
  resend_otp() {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/send-otp', {
      email: this.register.email,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const vid = response.data?.verification_id;
          if (typeof vid === 'string' && vid.length > 0) {
            this.otp.verification_id = vid;
            this.otp.code = '';
          }
          this.success_notification(this.i18n.t('text_otp_sent'));
        } else {
          this.error_notification(this.mapErrorMessage(response));
        }
      },
      error: (e) => {
        console.error(e);
        this.error_notification(e?.toString() ?? this.i18n.t('text_request_failed'));
        this.ui_controls.loading = false;
      },
    });
  }

  /**
   * Stage 2 -> Stage 1: let the user fix typos in their info before
   * confirming. We keep the form data intact so they don't re-type
   * everything. The verification_id stays around but will be re-issued
   * on the next /register call.
   */
  back_to_form() {
    this.ui_controls.stage = 1;
    this.otp.code = '';
  }

  /**
   * Map known v3 error codes to user-friendly i18n strings, falling
   * back to the server-provided message for anything we don't have a
   * specific translation for.
   */
  private mapErrorMessage(response: any): string {
    const code = response?.error_code;
    if (typeof code === 'string') {
      switch (code) {
        case 'CONFLICT_EMAIL_TAKEN':
          return this.i18n.t('text_email_already_registered');
        case 'CONFLICT_PHONE_TAKEN':
          return this.i18n.t('text_phone_already_registered');
        case 'OTP_RATE_LIMITED':
          return this.i18n.t('text_otp_rate_limited');
        case 'OTP_PROVIDER_ERROR':
          return this.i18n.t('text_otp_provider_error');
        // Generic VALIDATION_FAILED, AUTH_INVALID_CREDENTIALS, etc.:
        // fall through to server message.
        default:
          break;
      }
    }
    return response?.message ?? this.i18n.t('text_request_failed');
  }

  error_notification(message: string) {
    this.toast.error(message, {
      position: 'top-center',
    });
  }

  success_notification(message: string) {
    this.toast.success(message, {
      position: 'top-center',
    });
  }

  sign_in() {
    this.router.navigate(['/', 'login']);
  }

  forgot_password() {
    this.router.navigate(['/', 'reset']);
  }
}
