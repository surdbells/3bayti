import { Component, inject, OnInit, signal } from '@angular/core';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { CommonModule } from '@angular/common';
import { GlobalComponent } from '../../global-component';
import { FormsModule } from '@angular/forms';
import { TranslatePipe } from '../../translate.pipe';

import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
export interface User {
  id: number;
  token: string;
  first_name: string;
  last_name: string;
  email: string;
  is_finance: boolean;
  is_support: boolean;
  is_sub_admin: boolean;
  last_login: string;
  status: boolean;
}

@Component({
  selector: 'app-users',
  standalone: true,
  imports: [
    AdminShellComponent,
    CommonModule,
    FormsModule,
    TranslatePipe,
  ],
  templateUrl: './users.component.html',
  styleUrl: './users.component.css',
})
export class UsersComponent implements OnInit {
  customers?: User[];
  private readonly confirm = inject(AxConfirmService);
  protected readonly open = signal(false);

  ui_controls = {
    is_loading: false,
    is_registering: false,
    is_updating_password: false,
    nav_open: false,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  register = {
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    confirm_password: '',
    is_finance: false,
    is_support: false,
    _sub_admin: false,
  };

  single: User = {
    id: 0, token: '',
    first_name: '', last_name: '', email: '',
    is_finance: false, is_support: false, is_sub_admin: false,
    last_login: '', status: false,
  };

  password_c = { id: 0, token: '', user: 0, password: '' };
  get_data = { id: 0, token: '' };
  activate = { id: 0, token: '', customer: 0, status: true };
  deactivate = { id: 0, token: '', customer: 0, status: false };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.get_data.id = this.user_session.id;
    this.get_data.token = this.user_session.token;
    this.activate.id = this.user_session.id;
    this.activate.token = this.user_session.token;
    this.deactivate.id = this.user_session.id;
    this.deactivate.token = this.user_session.token;
    this.password_c.id = this.user_session.id;
    this.password_c.token = this.user_session.token;
    this.single.id = this.user_session.id;
    this.single.token = this.user_session.token;
    this.get_users();
  }

  goBack() {
    this.router.navigate(['/backend']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_users() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.get_data, GlobalComponent.getUsers).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.customers = response.data;
        }
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.ui_controls.is_loading = false;
      },
    });
  }

  start_activate(customer: number, name: string) {
    this.confirm
      .confirm({
        title: 'Confirm',
        message: `${name} account will be activated?`,
        confirmLabel: 'Activate',
        cancelLabel: 'Cancel'
      })
      .then((response) => {
        if (response) this.activate_customer(customer, name);
      });
  }

  activate_customer(customer: number, _name: string) {
    this.ui_controls.is_loading = true;
    this.activate.customer = customer;
    this.crudService.post_request(this.activate, GlobalComponent.activateCustomer).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.get_users();
        }
        this.ui_controls.is_loading = false;
      },
    });
  }

  start_deactivate(customer: number, name: string) {
    this.confirm
      .confirm({
        title: 'Confirm',
        message: `${name} will be deactivated?`,
        confirmLabel: 'Deactivate',
        cancelLabel: 'Cancel',
        variant: 'danger'
      })
      .then((response) => {
        if (response) this.deactivate_customer(customer, name);
      });
  }

  deactivate_customer(customer: number, _name: string) {
    this.ui_controls.is_loading = true;
    this.deactivate.customer = customer;
    this.crudService.post_request(this.deactivate, GlobalComponent.deactivateCustomer).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.get_users();
        }
        this.ui_controls.is_loading = false;
      },
    });
  }

  user_register() {
    if (this.register.first_name.length === 0) { this.error_notification('First name is required'); return; }
    if (this.register.last_name.length === 0) { this.error_notification('Last name is required'); return; }
    if (this.register.email.length === 0) { this.error_notification('Email address is required'); return; }
    if (!GlobalComponent.validateEmail(this.register.email)) { this.error_notification('Invalid email format provided'); return; }
    if (this.register.password.length === 0) { this.error_notification('Password is required'); return; }
    if (this.register.confirm_password.length === 0) { this.error_notification('Password does not match'); return; }
    if (this.register.password !== this.register.confirm_password) { this.error_notification('Password does not match'); return; }

    this.ui_controls.is_registering = true;
    this.crudService.post_request(this.register, GlobalComponent.AdminUserRegister).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.register = {
            first_name: '', last_name: '', email: '',
            password: '', confirm_password: '',
            is_finance: false, is_support: false, _sub_admin: false,
          };
          this.get_users();
        } else {
          this.error_notification(response.message);
        }
        this.ui_controls.is_registering = false;
      },
      error: () => {
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_registering = false;
      },
    });
  }

  onCloseDrawer(): void {
    this.open.set(false);
  }

  start_edit(a_customer: User) {
    this.single = { ...a_customer };
    this.password_c.password = '';
    this.open.set(true);
  }

  update_user() {
    // API for update user not yet wired in the legacy implementation either.
    // Keep the form available for when the endpoint is added.
  }

  update_password() {
    if (!this.password_c.password || this.password_c.password.length === 0) {
      this.error_notification('New password is required.');
      return;
    }
    this.password_c.user = this.single.id;
    this.ui_controls.is_updating_password = true;
    this.crudService.post_request(this.password_c, GlobalComponent.AdminUserPassword).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.password_c.password = '';
          this.open.set(false);
          this.get_users();
        } else {
          this.error_notification(response.message);
        }
        this.ui_controls.is_updating_password = false;
      },
      error: () => {
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_updating_password = false;
      },
    });
  }
}
