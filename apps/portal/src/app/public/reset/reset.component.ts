import {Component, OnInit} from '@angular/core';
import {PortalCrudAdapter} from '../../services/portal-crud-adapter';
import {Router, RouterLink} from '@angular/router';
import {CookieService} from 'ngx-cookie-service';
import {HotToastService} from '../../shared/toast/toast.service';
import {GlobalComponent} from '../../global-component';
import {FormsModule} from '@angular/forms';
import {CommonModule} from '@angular/common';
import {TranslatePipe} from '../../translate.pipe';
import {LanguageSwitcherComponent} from '../../language-switcher.component';

import { IconComponent } from '../../shared/icon/icon.component';
@Component({
  selector: 'app-reset',
  imports: [
    FormsModule,
    CommonModule,
    RouterLink,
    TranslatePipe,
    LanguageSwitcherComponent, IconComponent],
  standalone: true,
  templateUrl: './reset.component.html',
  styleUrl: './reset.component.css'
})
export class ResetComponent implements OnInit{
  constructor(
    private adapter: PortalCrudAdapter,
    private router: Router,
    private cookieService: CookieService,
    private toast: HotToastService
  ) {}
  fieldTextType: boolean = false;
  /** v3 reset is 2-step: POST /auth/reset returns this id; POST
   *  /auth/reset/confirm consumes it with the OTP + new password. */
  private verificationId = '';
  ui_controls = {
    loading: false,
    confirmed: false,
    validated: false
  };
  ngOnInit(): void {

  }
  reset = {
    email: ""
  };
  confirm = {
    otp: "",
    input_otp: "",
    expires_at: 0,
    email: ""
  };
  r_password = {
    email: "",
    password: "",
    confirm_password: ""
  };
  r_response = {
    otp: "",
    expires_at: 0
  };
  user_confirm() {
    if (this.reset.email.length === 0) {
      this.error_notification("Email address is required");
      return;
    }
    if (!GlobalComponent.validateEmail(this.reset.email)) {
      this.error_notification("Invalid email format provided");
      return;
    }
    this.ui_controls.loading = true;
    this.adapter.post_v3('POST /auth/reset', { email: this.reset.email })
      .subscribe({
        next: (response: any) => {
          this.ui_controls.loading = false;
          // v3 always returns 200 with a verification_id (anti-enumeration).
          const vid = response?.data?.verification_id ?? response?.verification_id;
          if (vid) {
            this.verificationId = vid;
            this.confirm.email = this.reset.email;
            this.ui_controls.confirmed = true;
            this.success_notification('If that email is registered, a reset code has been sent.');
          } else {
            this.error_notification('Unable to start password reset. Please try again.');
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification('Unable to complete your request at this time.');
          this.ui_controls.loading = false;
        },
      });
  }
  user_validate() {
    if (this.confirm.input_otp.length === 0) {
      this.error_notification("OTP is required");
      return;
    }
    // v3 verifies the OTP atomically during /auth/reset/confirm (together
    // with the new password), so this step only captures the code and
    // advances the UI. An invalid code surfaces at the final step.
    this.r_password.email = this.reset.email;
    this.ui_controls.validated = true;
  }
  reset_password() {
    if (this.r_password.password.length === 0) {
      this.error_notification("Password is required");
      return;
    }
    if (this.r_password.confirm_password.length === 0) {
      this.error_notification("Password does not match");
      return;
    }
    if (this.r_password.password != this.r_password.confirm_password) {
      this.error_notification("Password does not match");
      return;
    }
    this.ui_controls.loading = true;
    this.adapter.post_v3('POST /auth/reset/confirm', {
      verification_id: this.verificationId,
      code: this.confirm.input_otp,
      new_password: this.r_password.password,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response?.data || response?.access_token) {
          this.success_notification('Your password has been reset. Please sign in.');
          this.router.navigate(['/login']);
        } else {
          this.error_notification('Unable to reset password. Please try again.');
        }
      },
      error: (e) => {
        console.error(e);
        // 400/422 here usually means a wrong/expired OTP.
        const msg = e?.error?.error?.message
          ?? 'The code may be invalid or expired. Please request a new one.';
        this.error_notification(msg);
        this.ui_controls.loading = false;
      },
    });
  }
  toggleFieldTextType() {
    this.fieldTextType = !this.fieldTextType;
  }
  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }
}
