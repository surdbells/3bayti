import { Component, inject, OnInit, signal } from '@angular/core';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
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
    private adapter: PortalCrudAdapter,
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
    this.get_users(0, '', 'customer');
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

  // Pagination & search state
  total = 0;
  pageSize = 20;
  pageIndex = 0;
  searchQuery = '';
  roleFilter = 'customer';

  get_users(page = 0, search = '', role = 'customer') {
    this.ui_controls.is_loading = true;
    this.pageIndex = page;
    this.searchQuery = search;
    this.roleFilter = role;
    const query: any = { limit: this.pageSize, offset: page * this.pageSize, role };
    if (search) query['search'] = search;
    this.adapter.get_v3('GET /admin/users', { query }).subscribe({
      next: (response: any) => {
        // v3 returns { data: [...], meta: { total, limit, offset } }
        const raw: any[] = response?.data ?? [];
        this.total = response?.meta?.total ?? raw.length;
        this.customers = raw;
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.ui_controls.is_loading = false;
      },
    });
  }

  get totalPages(): number { return Math.ceil(this.total / this.pageSize); }
  prevPage() { if (this.pageIndex > 0) this.get_users(this.pageIndex - 1, this.searchQuery, this.roleFilter); }
  nextPage() { if (this.pageIndex < this.totalPages - 1) this.get_users(this.pageIndex + 1, this.searchQuery, this.roleFilter); }
  onSearch(e: Event) { this.get_users(0, (e.target as HTMLInputElement).value, this.roleFilter); }
  onRoleFilter(role: string) { this.get_users(0, this.searchQuery, role); }

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
    const uid = this.activate.customer ?? this.activate.id;
    this.adapter.post_v3('POST /admin/users/:id/activate', {}, { params: { id: String(uid) } }).subscribe({
      next: (response: any) => {
        if (response) {
          this.success_notification(response.message);
          this.get_users(0, '', 'customer');
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
    const uid2 = this.deactivate.customer ?? this.deactivate.id;
    this.adapter.post_v3('POST /admin/users/:id/deactivate', {}, { params: { id: String(uid2) } }).subscribe({
      next: (response: any) => {
        if (response) {
          this.success_notification(response.message);
          this.get_users(0, '', 'customer');
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
    this.adapter.post_v3('POST /admin/users/create', this.register).subscribe({
      next: (response: any) => {
        if (response) {
          this.success_notification(response.message);
          this.register = {
            first_name: '', last_name: '', email: '',
            password: '', confirm_password: '',
            is_finance: false, is_support: false, _sub_admin: false,
          };
          this.get_users(0, '', 'customer');
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
    const pwUId = this.password_c.user ?? this.password_c.id;
    this.adapter.patch_v3('PATCH /admin/users/:id/password', this.password_c, { params: { id: String(pwUId) } }).subscribe({
      next: (response: any) => {
        if (response) {
          this.success_notification(response.message);
          this.password_c.password = '';
          this.open.set(false);
          this.get_users(0, '', 'customer');
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
