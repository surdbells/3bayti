import { Component, OnInit, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { TranslatePipe } from '../../translate.pipe';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';

export interface User extends Record<string, unknown> {
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
    AxDataTableComponent,
    AxCellDirective,
    TranslatePipe,
  ],
  templateUrl: './users.component.html',
  styleUrl: './users.component.css',
})
export class UsersComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);
  protected readonly open = signal(false);

  ui_controls = {
    is_loading: false,
    is_registering: false,
    is_updating_password: false,
    nav_open: false,
  };

  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  register = {
    first_name: '', last_name: '', email: '',
    password: '', confirm_password: '',
    is_finance: false, is_support: false, _sub_admin: false,
  };

  single: User = {
    id: 0, token: '',
    first_name: '', last_name: '', email: '',
    is_finance: false, is_support: false, is_sub_admin: false,
    last_login: '', status: false,
  };

  password_c = { id: 0, token: '', user: 0, password: '' };

  roleFilter = 'customer';

  config!: AxDataTableConfig<User>;
  dataSource!: AxServerDataSource<User>;

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(
      sessionStorage.getItem('SESSION') ?? '',
    );
    this.buildTable();
  }

  private buildTable() {
    this.dataSource = new AxServerDataSource<User>((q) => this.fetchUsers(q));
    this.config = {
      tableId: 'admin-users',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search users by name or email…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No users found',
      emptyDescription: 'No platform users match your current filters.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'platform-users' },
      columns: [
        { key: 'name', label: 'Name', sortable: true, sticky: 'left', width: '14rem',
          value: (r) => `${r.first_name} ${r.last_name}` },
        { key: 'email', label: 'Email' },
        { key: 'last_login', label: 'Last login', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
        { key: 'roles', label: 'Roles', align: 'left',
          value: (r) => [r.is_finance && 'Finance', r.is_sub_admin && 'Sub-admin', r.is_support && 'Support'].filter(Boolean).join(', ') || '—' },
        { key: 'status', label: 'Status', align: 'center',
          value: (r) => (r.status ? 'Active' : 'Inactive') },
      ],
      rowActions: [
        { id: 'activate', label: 'Activate', icon: 'check_circle', hidden: (r) => r.status },
        { id: 'deactivate', label: 'Deactivate', icon: 'block', variant: 'danger', hidden: (r) => !r.status },
        { id: 'edit', label: 'Edit', icon: 'edit' },
      ],
    };
  }

  private fetchUsers(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
      role: this.roleFilter,
    };
    if (query.search) q.search = query.search;
    return this.adapter.get_v3('GET /admin/users', { query: q }).pipe(
      map((response: any): AxServerFetchResult<User> => {
        const raw: any[] = response?.data ?? [];
        const rows = raw.map((u) => this.mapUser(u));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load users at this time.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<User>);
      }),
    );
  }

  /** Map publicProfile (roles[] array, last_login_at) into the flat row. */
  private mapUser(u: any): User {
    const roles: string[] = Array.isArray(u.roles) ? u.roles : [];
    return {
      ...u,
      id: u.id,
      first_name: u.first_name ?? '',
      last_name: u.last_name ?? '',
      email: u.email ?? '',
      last_login: u.last_login_at ?? u.last_login ?? '',
      is_finance: roles.includes('finance'),
      is_support: roles.includes('support'),
      is_sub_admin: roles.includes('sub_admin'),
      is_admin: roles.includes('admin'),
      status: u.is_store_active ?? u.is_active ?? true,
    } as User;
  }

  onRoleFilter(role: string) {
    this.roleFilter = role;
    this.dataSource.retry();
  }

  onRowAction(e: { action: { id: string }; row: User }) {
    const { action, row } = e;
    switch (action.id) {
      case 'activate': return this.startActivate(row);
      case 'deactivate': return this.startDeactivate(row);
      case 'edit': return this.start_edit(row);
    }
  }

  private refresh() { this.dataSource.retry(); }

  private startActivate(row: User) {
    this.confirm.confirm({
      title: 'Activate user', message: `${row.first_name}'s account will be activated.`,
      confirmLabel: 'Activate', cancelLabel: 'Cancel',
    }).then((ok) => { if (ok) this.activate_customer(row.id); });
  }

  private startDeactivate(row: User) {
    this.confirm.confirm({
      title: 'Deactivate user', message: `${row.first_name} will be deactivated.`,
      confirmLabel: 'Deactivate', cancelLabel: 'Cancel', variant: 'danger',
    }).then((ok) => { if (ok) this.deactivate_customer(row.id); });
  }

  private activate_customer(uid: number) {
    this.adapter.post_v3('POST /admin/users/:id/activate', {}, { params: { id: String(uid) } })
      .subscribe({ next: (r: any) => { if (r) { this.toast.success('User activated.'); this.refresh(); } } });
  }

  private deactivate_customer(uid: number) {
    this.adapter.post_v3('POST /admin/users/:id/deactivate', {}, { params: { id: String(uid) } })
      .subscribe({ next: (r: any) => { if (r) { this.toast.success('User deactivated.'); this.refresh(); } } });
  }

  user_register() {
    const r = this.register;
    if (!r.first_name) { this.toast.error('First name is required'); return; }
    if (!r.last_name) { this.toast.error('Last name is required'); return; }
    if (!r.email) { this.toast.error('Email address is required'); return; }
    if (!GlobalComponent.validateEmail(r.email)) { this.toast.error('Invalid email format provided'); return; }
    if (!r.password) { this.toast.error('Password is required'); return; }
    if (r.password !== r.confirm_password) { this.toast.error('Password does not match'); return; }

    this.ui_controls.is_registering = true;
    this.adapter.post_v3('POST /admin/users', this.register).subscribe({
      next: (response: any) => {
        if (response) {
          this.toast.success('User created successfully.');
          this.register = {
            first_name: '', last_name: '', email: '',
            password: '', confirm_password: '',
            is_finance: false, is_support: false, _sub_admin: false,
          };
          this.refresh();
        }
        this.ui_controls.is_registering = false;
      },
      error: () => {
        this.toast.error('Unable to complete your request at this time.');
        this.ui_controls.is_registering = false;
      },
    });
  }

  onCloseDrawer(): void { this.open.set(false); }

  start_edit(a_customer: User) {
    this.single = { ...a_customer };
    this.password_c.password = '';
    this.open.set(true);
  }

  update_user() {
    // Update-user endpoint not yet available; drawer retained for password reset.
  }

  update_password() {
    if (!this.password_c.password) { this.toast.error('New password is required.'); return; }
    this.password_c.user = this.single.id;
    this.ui_controls.is_updating_password = true;
    const pwUId = this.password_c.user ?? this.password_c.id;
    this.adapter.patch_v3('PATCH /admin/users/:id/password', this.password_c, { params: { id: String(pwUId) } }).subscribe({
      next: (response: any) => {
        if (response) {
          this.toast.success('Password updated.');
          this.password_c.password = '';
          this.open.set(false);
          this.refresh();
        }
        this.ui_controls.is_updating_password = false;
      },
      error: () => {
        this.toast.error('Unable to complete your request at this time.');
        this.ui_controls.is_updating_password = false;
      },
    });
  }

  goBack() { this.router.navigate(['/backend']); }
}
