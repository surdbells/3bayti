import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../global-component';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TranslatePipe } from '../../translate.pipe';

import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
@Component({
  selector: 'app-user-security',
  standalone: true,
  imports: [
    VendorShellComponent,
    AdminShellComponent,
    CommonModule,
    FormsModule,
    TranslatePipe,
  ],
  templateUrl: './user-security.component.html',
  styleUrl: './user-security.component.css',
})
export class UserSecurityComponent implements OnInit {
  ui_controls = {
    is_loading: false,
    is_saving: false,
    nav_open: false,
  };

  update_password = {
    id: 0, token: '',
    first_name: '', email: '',
    password: '', confirm_password: '',
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    if (!this.user_session.is_active) {
      this.router.navigate(['/', '']).then(r => console.log(r));
      return;
    }
  }

  goBack() {
    if (this.user_session.is_vendor) this.router.navigate(['/account']).then(r => console.log(r));
    if (this.user_session.is_admin) this.router.navigate(['/backend']).then(r => console.log(r));
  }

  error_notification(message: string) { this.toast.error(message); }
  success_notification(message: string) { this.toast.success(message); }

  password_change() {
    this.update_password.id = this.user_session.id;
    this.update_password.token = this.user_session.token;
    this.update_password.first_name = this.user_session.first_name;
    this.update_password.email = this.user_session.email;

    if (!this.update_password.password) { this.error_notification('Password is required.'); return; }
    if (!this.update_password.confirm_password) { this.error_notification('Password does not match.'); return; }
    if (this.update_password.password !== this.update_password.confirm_password) {
      this.error_notification('Password does not match.');
      return;
    }

    this.ui_controls.is_saving = true;
    this.crudService.post_request(this.update_password, GlobalComponent.updateUserPassword).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.update_password.password = '';
          this.update_password.confirm_password = '';
        } else if (response.status === 'failed') {
          this.error_notification(response.message);
        }
        this.ui_controls.is_saving = false;
      },
      error: () => {
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_saving = false;
      },
    });
  }
}
