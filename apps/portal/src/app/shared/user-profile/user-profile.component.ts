import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TranslatePipe } from '../../translate.pipe';

import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../icon/icon.component';
import { apiErrorMessage } from '../http/api-error';
@Component({
  selector: 'app-user-profile',
  standalone: true,
  imports: [
    VendorShellComponent,
    AdminShellComponent,
    CommonModule,
    FormsModule,
    TranslatePipe, IconComponent],
  templateUrl: './user-profile.component.html',
  styleUrl: './user-profile.component.css',
})
export class UserProfileComponent implements OnInit {
  base64String: any;

  ui_controls = {
    is_loading: false,
    is_saving: false,
    nav_open: false,
  };

  user_single = {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    avatar: '',
  };

  get_single = { id: 0, token: '' };
  // NOTE: phone is intentionally NOT part of the profile-update payload — the
  // profile endpoint ignores it. Phone changes go through the OTP-verified
  // change-phone flow below (POST /me/phone → POST /me/phone/verify).
  update_single = {
    id: 0, token: '',
    first_name: '', last_name: '', email: '', avatar: '',
  };

  // Change-phone flow state (inline, two-step: enter number → verify code).
  phone_change = {
    open: false,
    step: 'input' as 'input' | 'verify',
    new_phone: '',
    code: '',
    verification_id: '',
    is_sending: false,
    is_verifying: false,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '', avatar: '', id_front: '', id_back: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    if (!this.user_session.is_active) {
      this.router.navigate(['/', '']).then(r => console.log(r));
      return;
    }
    this.get_single.id = this.user_session.id;
    this.get_single.token = this.user_session.token;
    this.get_data();
  }

  goBack() {
    if (this.user_session.is_vendor) this.router.navigate(['/account']).then(r => console.log(r));
    if (this.user_session.is_admin) this.router.navigate(['/backend']).then(r => console.log(r));
  }

  error_notification(message: string) { this.toast.error(message); }
  success_notification(message: string) { this.toast.success(message); }

  get_data() {
    this.ui_controls.is_loading = true;
    this.adapter.get_v3('GET /me/profile').subscribe({
      next: (response: any) => {
        const u = response?.user ?? response?.data ?? {};
        this.user_single = {
          first_name: u.first_name ?? '',
          last_name: u.last_name ?? '',
          email: u.email ?? '',
          phone: u.phone ?? '',
          avatar: u.avatar_url ?? u.avatar ?? '',
        };
        this.ui_controls.is_loading = false;
      },
      error: (err: any) => {
        this.error_notification(apiErrorMessage(err, 'Unable to complete your request at this time.'));
        this.ui_controls.is_loading = false;
      },
    });
  }

  update_changes() {
    this.update_single.id = this.user_session.id;
    this.update_single.token = this.user_session.token;
    this.update_single.first_name = this.user_single.first_name;
    this.update_single.last_name = this.user_single.last_name;
    this.update_single.email = this.user_single.email;
    this.update_single.avatar = this.user_single.avatar;

    this.ui_controls.is_saving = true;
    this.adapter.patch_v3('PATCH /me/profile', this.update_single).subscribe({
      next: (response: any) => {
        if (response) {
          this.success_notification('Profile updated successfully.');
          this.get_data();
        }
        this.ui_controls.is_saving = false;
      },
      error: (err: any) => {
        this.error_notification(apiErrorMessage(err, 'Unable to complete your request at this time.'));
        this.ui_controls.is_saving = false;
      },
    });
  }

  select_avatar(event: any) {
    const input = event.target as HTMLInputElement;
    const file = input.files && input.files[0];
    input.value = ''; // allow re-picking the same file
    if (!file) {
      return;
    }
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
      this.error_notification('Please choose a JPEG, PNG, or WebP image.');
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      this.error_notification('Image is too large (max 5 MB).');
      return;
    }
    const reader = new FileReader();
    reader.onloadend = () => this.uploadAvatar(reader.result as string);
    reader.onerror = () => this.error_notification('Could not read the selected image.');
    reader.readAsDataURL(file);
  }

  uploadAvatar(dataUrl: string) {
    this.ui_controls.is_saving = true;
    this.adapter.post_v3('POST /me/avatar', { image: dataUrl }).subscribe({
      next: (response: any) => {
        const url = response?.data?.avatar_url ?? response?.avatar_url;
        if (url) {
          this.user_single.avatar = url;
          this.success_notification('Profile picture updated.');
        }
        this.ui_controls.is_saving = false;
      },
      error: (err: any) => {
        this.error_notification(apiErrorMessage(err, "Couldn't update your profile picture. Please try again."));
        this.ui_controls.is_saving = false;
      },
    });
  }

  // ── Change phone (OTP-verified) ──────────────────────────────────────

  private reset_phone_change() {
    this.phone_change = {
      open: false,
      step: 'input',
      new_phone: '',
      code: '',
      verification_id: '',
      is_sending: false,
      is_verifying: false,
    };
  }

  open_phone_change() {
    this.reset_phone_change();
    this.phone_change.open = true;
  }

  cancel_phone_change() {
    this.reset_phone_change();
  }

  /** Step 1: request a verification code for the new number. */
  send_phone_code() {
    const phone = (this.phone_change.new_phone || '').trim();
    if (!/^\+[1-9]\d{6,14}$/.test(phone)) {
      this.error_notification('Enter a valid phone number in international format, e.g. +9715XXXXXXXX.');
      return;
    }
    this.phone_change.new_phone = phone;
    this.phone_change.is_sending = true;
    this.adapter.post_v3('POST /me/phone', { phone }).subscribe({
      next: (response: any) => {
        const vid = response?.data?.verification_id ?? response?.verification_id ?? '';
        this.phone_change.verification_id = vid;
        this.phone_change.code = '';
        this.phone_change.step = 'verify';
        this.phone_change.is_sending = false;
        this.success_notification('We sent a 6-digit code to ' + phone + '.');
      },
      error: (err: any) => {
        this.error_notification(apiErrorMessage(err, 'Could not send the verification code. Please try again.'));
        this.phone_change.is_sending = false;
      },
    });
  }

  /** Re-request a code for the same number (verify step). */
  resend_phone_code() {
    const phone = (this.phone_change.new_phone || '').trim();
    if (!phone) {
      return;
    }
    this.phone_change.is_sending = true;
    this.adapter.post_v3('POST /me/phone', { phone }).subscribe({
      next: (response: any) => {
        const vid = response?.data?.verification_id ?? response?.verification_id;
        if (vid) {
          this.phone_change.verification_id = vid;
        }
        this.phone_change.is_sending = false;
        this.success_notification('A new code is on its way.');
      },
      error: (err: any) => {
        this.error_notification(apiErrorMessage(err, 'Could not resend the verification code. Please try again.'));
        this.phone_change.is_sending = false;
      },
    });
  }

  /** Step 2: verify the code and commit the new number. */
  verify_phone_code() {
    const code = (this.phone_change.code || '').trim();
    if (!/^\d{6}$/.test(code)) {
      this.error_notification('Enter the 6-digit code we sent you.');
      return;
    }
    if (!this.phone_change.verification_id) {
      this.error_notification('Your session expired — please request a new code.');
      return;
    }
    this.phone_change.is_verifying = true;
    this.adapter
      .post_v3('POST /me/phone/verify', {
        verification_id: this.phone_change.verification_id,
        code,
      })
      .subscribe({
        next: (response: any) => {
          const data = response?.data ?? response ?? {};
          this.user_single.phone = data.phone ?? this.phone_change.new_phone;
          this.success_notification('Your phone number has been updated.');
          this.reset_phone_change();
        },
        error: (err: any) => {
          this.error_notification(apiErrorMessage(err, 'Could not verify the code. Please try again.'));
          this.phone_change.is_verifying = false;
        },
      });
  }
}
