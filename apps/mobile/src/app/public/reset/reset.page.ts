import {Component, OnDestroy, OnInit} from '@angular/core';
import {
  IonContent,
  Platform,
  IonButton,
  IonText,
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

import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
import { AxIconComponent } from '../../shared/ax-mobile/icon';

import {DIAL_CODES, DialCode} from "../shared/dial-codes";

/**
 * Password-reset page — rebuilt for the v3 TWO-OPTION reset flow.
 *
 * Step 1: channel toggle "Email | Phone".
 *   Email mode -> email input. Phone mode -> dial-code + phone input.
 *   [Send code] -> POST /auth/reset { channel, email|phone }
 *     -> data { verification_id }. Stash, advance to Step 2.
 *   (Anti-enumeration: always 200 with a vid for valid input.)
 *   OTP_RATE_LIMITED (429) / OTP_PROVIDER_ERROR (502) -> mapped error.
 *
 * Step 2: OTP + new_password + confirm_password.
 *   [Reset] -> POST /auth/reset/confirm { verification_id, code, new_password }
 *     -> data { tokens, user }. On success we DO NOT auto-login / store
 *     tokens (product requirement); show success toast + NAVIGATE TO /login.
 *   Resend -> re-call /auth/reset with same channel/identifier.
 *   Change -> back to Step 1.
 *   OTP_VERIFICATION_FAILED (401) -> specific error.
 *
 * Errors arrive on the SUCCESS channel as
 * { response_code, status: 'error', message, error_code }; we read
 * response.error_code / response.message and defensively read
 * err?.error?.error?.code on the rxjs error cb.
 */
@Component({
  selector: 'app-reset',
  templateUrl: './reset.page.html',
  styleUrls: ['./reset.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    FormsModule,
    IonText,
    IonButton,
    TranslatePipe,
    AxLoaderComponent,
    AxTextFieldComponent,
    AxBottomSheetComponent,
    AxIconComponent,
  ]
})
export class ResetPage implements OnInit, OnDestroy {
  isOnline = true;
  private sub: Subscription;
  isCountryCodeOpen = false;

  readonly dialCodes: DialCode[] = DIAL_CODES;
  codeSearch = '';

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
    // v3 /auth/reset sends OTP internally; page starts ready for input.
  }

  /**
   * UI flow state.
   *   step 1: choose channel + identifier
   *   step 2: code + new password
   */
  ui_controls = {
    loading: false,
    step: 1 as 1 | 2,
    channel: 'email' as 'email' | 'phone',
  };

  reset_p = {
    email: "",
    phone: "",
    countryCode: "+971",
    code: "",
    new_password: "",
    confirm_password: "",
  };

  /** Set by /auth/reset; consumed by /auth/reset/confirm. */
  verification_id = "";

  get selectedDial(): DialCode | undefined {
    return this.dialCodes.find(c => c.code === this.reset_p.countryCode);
  }

  get fullPhone(): string {
    const national = this.reset_p.phone.replace(/^0+/, '').replace(/\D/g, '');
    return `${this.reset_p.countryCode}${national}`;
  }

  /** "Sent to" line value reflecting the chosen channel. */
  get sentTo(): string {
    return this.ui_controls.channel === 'phone' ? this.fullPhone : this.reset_p.email;
  }

  filteredDialCodes(): DialCode[] {
    const q = this.codeSearch.trim().toLowerCase();
    if (q.length === 0) return this.dialCodes;
    return this.dialCodes.filter(c =>
      c.name.toLowerCase().includes(q) || c.code.includes(q));
  }

  selectCode(c: DialCode) {
    this.reset_p.countryCode = c.code;
  }

  setChannel(channel: 'email' | 'phone') {
    this.ui_controls.channel = channel;
  }

  /** Build the channel-specific request body for /auth/reset. */
  private resetBody(): { channel: 'email' | 'phone'; email?: string; phone?: string } {
    if (this.ui_controls.channel === 'phone') {
      return { channel: 'phone', phone: this.fullPhone };
    }
    return { channel: 'email', email: this.reset_p.email };
  }

  // --- Step 1: send code -------------------------------------------------

  send_reset_otp() {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    if (this.ui_controls.channel === 'email') {
      if (this.reset_p.email.length === 0) {
        this.error_notification(this.i18n.t('text_email_required'));
        return;
      }
      if (!GlobalComponent.validateEmail(this.reset_p.email)) {
        this.error_notification(this.i18n.t('text_invalid_email_format'));
        return;
      }
    } else {
      if (this.reset_p.phone.replace(/\D/g, '').length === 0) {
        this.error_notification(this.i18n.t('text_phone_required'));
        return;
      }
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/reset', this.resetBody()).subscribe({
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
          this.ui_controls.step = 2;
          this.success_notification(this.i18n.t('text_otp_sent'));
        } else {
          this.error_notification(this.mapErrorMessage(response));
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  // --- Step 2: confirm ---------------------------------------------------

  reset_password() {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    if (this.reset_p.code.length === 0) {
      this.error_notification(this.i18n.t('text_otp_required'));
      return;
    }
    if (this.reset_p.new_password.length < 8) {
      this.error_notification(this.i18n.t('text_password_min_length'));
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
          // Product requirement: DO NOT auto-login. Discard the returned
          // tokens; send the user to log in fresh.
          this.success_notification(this.i18n.t('text_password_reset_success'));
          this.router.navigate(['/login'], { replaceUrl: true });
        } else {
          this.error_notification(this.mapErrorMessage(response));
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  // --- Resend / change ---------------------------------------------------

  resend_otp() {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/reset', this.resetBody()).subscribe({
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
      error: (e) => this.handleHttpError(e),
    });
  }

  back_to_identifier() {
    this.ui_controls.step = 1;
    this.reset_p.code = '';
    this.reset_p.new_password = '';
    this.reset_p.confirm_password = '';
  }

  // --- Error mapping -----------------------------------------------------

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
    this.ui_controls.loading = false;
    const code = e?.error?.error?.code;
    if (typeof code === 'string') {
      this.error_notification(this.mapErrorMessage({ error_code: code }));
      return;
    }
    console.error(e);
    this.error_notification(this.i18n.t('text_request_failed'));
  }

  error_notification(message: string) {
    this.toast.error(message, { position: 'top-center' });
  }

  success_notification(message: string) {
    this.toast.success(message, { position: 'top-center' });
  }

  sign_in() {
    this.router.navigate(['/', 'login']);
  }

  user_register() {
    this.router.navigate(['/', 'register']);
  }
}
