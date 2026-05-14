import {Component, OnDestroy, OnInit} from '@angular/core';
import {
  IonContent,
  IonCol,
  IonGrid,
  IonRow,
  IonText,
  Platform,
  IonButton,
} from '@ionic/angular/standalone';
import { ConnectionService } from '../../service/connection.service';
import { Subscription } from 'rxjs';

import { FormsModule } from '@angular/forms';
import {Router} from "@angular/router";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";
import {GlobalComponent} from "../../global-component";
import {Preferences} from "@capacitor/preferences";

import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';

import {transformV3LoginResponse} from "../login/login-response.transform";

/**
 * Password-reset page — restructured in M3.1.4d for v3.
 *
 * Flow (v3, replacing the legacy 3-stage MessageCentral + resetMobile flow)
 * =========================================================================
 *
 *   Stage 1: enter email (single screen)
 *     - email input
 *     - On [Send code]: POST /v3/auth/reset via adapter with {email}
 *     - v3 server (looks up user by email, sends OTP to stored phone)
 *       returns {verification_id} — always 200 even if email isn't
 *       registered (anti-enumeration per ResetController docblock)
 *     - On success: store verification_id, advance to Stage 2
 *
 *   Stage 2: enter code + new password (single screen, combined)
 *     - 6-digit code input + new password + confirm password
 *     - On [Reset]: POST /v3/auth/reset/confirm via adapter with
 *       {verification_id, code, new_password}
 *     - v3 server verifies code, sets new password, issues token pair,
 *       returns same shape as /v3/auth/login
 *     - On success: transformV3LoginResponse + Preferences.set + auto
 *       login (navigate to /account). Replaces legacy "navigate back to
 *       /login screen for the user to log in manually".
 *     - [Resend code] secondary button: POST /v3/auth/reset again with
 *       {email}; updates local verification_id
 *     - [Change email] link: return to Stage 1
 *
 * Stages tracked via `ui_controls.stage` enum (1 or 2). Replaces the
 * previous otpSent/otpValidated boolean pair.
 *
 * Why the identifier changed from phone to email
 * ===============================================
 * Legacy reset took {phone, password, confirm_password} and looked up
 * the user by phone. v3 takes email (Decision A.6.5 in v3 design —
 * email is the canonical identifier across the auth surface; phone is
 * for SMS only). Users still receive the OTP on their stored phone —
 * v3 looks it up by email and sends to whatever phone is on record.
 *
 * Why validate+set-password merged into one call
 * ===============================================
 * Legacy split this into two endpoints (customer/validateOTP, then
 * users/resetMobile). v3 combines them into /v3/auth/reset/confirm
 * which takes {verification_id, code, new_password} together. Saves
 * a round-trip and reduces window for invalidation races.
 *
 * Why auto-login on success
 * =========================
 * v3 /reset/confirm returns the same {access_token, refresh_token, user}
 * shape as /login does. Storing and navigating to /account is one more
 * convenient step than legacy's "go back to /login and log in again",
 * and the user just demonstrated phone-possession + chose a new
 * password — they're clearly the account owner.
 *
 * What was deleted vs M3.1.3-era reset.page.ts
 * =============================================
 *   - getAuthToken() — MessageCentral session token fetch
 *   - send_otp() (legacy) — MessageCentral-direct send
 *   - validate_otp() (legacy) — MessageCentral validate
 *   - reset_password() (legacy) — separate users/resetMobile call
 *   - showResendToken flag, smsToken, sendOTP, validateOtp,
 *     r_response, v_response field declarations
 *
 * Error handling
 * ==============
 * Per the ResetController's anti-enumeration design, /reset returns
 * 200 with a fake verification_id even for unregistered emails. Users
 * who don't have an account will get stuck in Stage 2 (the fake vid
 * won't validate). The error from /reset/confirm will surface as
 * AUTH_INVALID_CREDENTIALS or VERIFICATION_FAILED. We map this to a
 * single i18n string that's vague enough not to leak the cause but
 * actionable enough to guide a real user to recheck their email +
 * code.
 */
@Component({
  selector: 'app-reset',
  templateUrl: './reset.page.html',
  styleUrls: ['./reset.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonGrid,
    IonCol,
    IonRow,
    FormsModule,
    IonText,
    IonButton,
    TranslatePipe,
    AxLoaderComponent,
    AxTextFieldComponent,
  ]
})
export class ResetPage implements OnInit, OnDestroy {
  isOnline = true;
  private sub: Subscription;

  constructor(
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private i18n: I18nService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }

  ngOnInit() {
    // No pre-fetched MessageCentral token under v3 — /v3/auth/reset
    // sends OTP internally. Page starts immediately ready for input.
  }

  /**
   * UI flow state.
   *   stage 1: collect email
   *   stage 2: code + new password
   */
  ui_controls = {
    loading: false,
    stage: 1 as 1 | 2,
  };

  /**
   * Stage 1 input (email) + Stage 2 inputs (code + new password +
   * confirm). The two stages share a single state object because the
   * email value is needed in Stage 2 for the [Resend code] button.
   */
  reset_p = {
    email: "",
    code: "",
    new_password: "",
    confirm_password: "",
  };

  /** Set by /v3/auth/reset response; consumed by /v3/auth/reset/confirm. */
  verification_id = "";

  /**
   * Stage 1: POST /v3/auth/reset with {email}.
   *
   * Per ResetController docblock, v3 always returns 200 with a
   * verification_id-shaped string regardless of whether the email is
   * registered (anti-enumeration). So success here doesn't mean an
   * account exists — it just means the request was well-formed.
   */
  send_reset_otp() {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    if (this.reset_p.email.length === 0) {
      this.error_notification(this.i18n.t('text_email_required'));
      return;
    }
    if (!GlobalComponent.validateEmail(this.reset_p.email)) {
      this.error_notification(this.i18n.t('text_invalid_email_format'));
      return;
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/reset', {
      email: this.reset_p.email,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const vid = response.data?.verification_id;
          if (typeof vid !== 'string' || vid.length === 0) {
            this.error_notification(this.i18n.t('text_request_failed'));
            return;
          }
          this.verification_id = vid;
          this.reset_p.code = '';
          this.reset_p.new_password = '';
          this.reset_p.confirm_password = '';
          this.ui_controls.stage = 2;
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
   * Stage 2: POST /v3/auth/reset/confirm with {verification_id, code,
   * new_password}.
   *
   * On success, response has the same shape as /v3/auth/login. We
   * reuse the M3.1.3 transform to store the legacy single_user blob
   * and navigate to /account, mirroring login behaviour.
   */
  reset_password() {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    if (this.reset_p.code.length === 0) {
      this.error_notification(this.i18n.t('text_otp_required'));
      return;
    }
    if (this.reset_p.new_password.length === 0) {
      this.error_notification(this.i18n.t('text_password_required'));
      return;
    }
    if (this.reset_p.confirm_password.length === 0) {
      this.error_notification(this.i18n.t('text_passwords_do_not_match'));
      return;
    }
    if (this.reset_p.new_password !== this.reset_p.confirm_password) {
      this.error_notification(this.i18n.t('text_passwords_do_not_match'));
      return;
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/reset/confirm', {
      verification_id: this.verification_id,
      code: this.reset_p.code,
      new_password: this.reset_p.new_password,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const userToStore = transformV3LoginResponse(response.data) ?? response.data;
          Preferences.set({ key: 'user', value: JSON.stringify(userToStore) });
          this.success_notification(this.i18n.t('text_password_reset_success'));
          this.router.navigate(['/account'], { replaceUrl: true });
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
   * Stage 2: re-send the reset OTP. Calls /v3/auth/reset again with
   * the same email; v3 issues a fresh verification_id which we adopt
   * locally. Clears the code field so the user re-enters the new one.
   */
  resend_otp() {
    // Same surface as send_reset_otp — just re-invoke. send_reset_otp
    // already handles email validation, stage advancement, etc. But
    // we don't want to demote stage back to 1, so we inline a slim
    // version that just refreshes the verification_id.
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/reset', {
      email: this.reset_p.email,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const vid = response.data?.verification_id;
          if (typeof vid === 'string' && vid.length > 0) {
            this.verification_id = vid;
            this.reset_p.code = '';
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
   * Stage 2 -> Stage 1: let the user fix typos in the email before
   * confirming. We keep the email value intact.
   */
  back_to_email() {
    this.ui_controls.stage = 1;
    this.reset_p.code = '';
    this.reset_p.new_password = '';
    this.reset_p.confirm_password = '';
  }

  /**
   * Map known v3 error codes to i18n strings; otherwise use the
   * server message.
   *
   * For reset-confirm specifically: v3 may return AUTH_INVALID_CREDENTIALS
   * (verification_id wasn't valid — could mean code wrong, or
   * verification_id was the fake one issued for an unregistered email).
   * We surface a single generic message rather than distinguishing,
   * to preserve the anti-enumeration property.
   */
  private mapErrorMessage(response: any): string {
    const code = response?.error_code;
    if (typeof code === 'string') {
      switch (code) {
        case 'AUTH_INVALID_CREDENTIALS':
        case 'VERIFICATION_FAILED':
          return this.i18n.t('text_reset_verification_failed');
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

  user_register() {
    this.router.navigate(['/', 'register']);
  }
}
