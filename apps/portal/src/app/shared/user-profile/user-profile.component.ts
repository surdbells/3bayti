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
@Component({
  selector: 'app-user-profile',
  standalone: true,
  imports: [
    VendorShellComponent,
    AdminShellComponent,
    CommonModule,
    FormsModule,
    TranslatePipe,
  ],
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
  update_single = {
    id: 0, token: '',
    first_name: '', last_name: '', email: '', phone: '', avatar: '',
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
        if (response) {
          this.user_single = response.user ?? response.data ?? {};
        }
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.error_notification('Unable to complete your request at this time.');
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
    this.update_single.phone = this.user_single.phone;
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
      error: () => {
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_saving = false;
      },
    });
  }

  select_avatar(event: any) {
    const file = event.target.files[0];
    const reader = new FileReader();
    reader.onloadend = () => {
      this.base64String = reader.result as string;
      this.user_single.avatar = this.base64String;
    };
    if (file) reader.readAsDataURL(file);
  }
}
