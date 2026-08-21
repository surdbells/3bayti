import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { AxCanDirective } from '../../shared/security/ax-can.directive';
import { PermissionService } from '../../services/permission.service';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';

export interface RoleRef {
  id: number;
  slug: string;
  name: string;
}

export interface StaffUser extends Record<string, unknown> {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  is_admin: boolean;
  last_login: string;
  status: boolean;
  assigned_roles: RoleRef[];
}

export interface RoleOption {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  is_system: boolean;
}

export interface RoleDetail extends RoleOption {
  permissions: string[];
}

export interface CatalogPermission { key: string; label: string; }
export interface CatalogModule { module: string; label: string; permissions: CatalogPermission[]; }
export interface CatalogPreset { slug: string; name: string; description: string | null; permissions: string[]; }

@Component({
  selector: 'app-users',
  standalone: true,
  imports: [
    AdminShellComponent,
    CommonModule,
    AxDataTableComponent,
    AxCellDirective,
    AxCanDirective,
    IconComponent,
  ],
  templateUrl: './users.component.html',
  styleUrl: './users.component.css',
})
export class UsersComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);
  protected readonly perms = inject(PermissionService);

  // View toggle (Staff | Roles)
  protected readonly view = signal<'staff' | 'roles'>('staff');

  ui = {
    roles_list_loading: false,
  };

  // Roles list (the matrix editor itself lives at /admin_role — RoleEditorComponent)
  rolesList: RoleDetail[] = [];

  config!: AxDataTableConfig<StaffUser>;
  dataSource!: AxServerDataSource<StaffUser>;

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.perms.load();
    this.buildTable();
    // Returning from the role editor (?view=roles) re-opens the roles view and
    // re-fetches the list, so new/edited roles appear without a manual reload.
    if (this.route.snapshot.queryParamMap.get('view') === 'roles') {
      this.switchView('roles');
    }
  }

  // ── Table ────────────────────────────────────────────────────────────
  private buildTable() {
    this.dataSource = new AxServerDataSource<StaffUser>((q) => this.fetchStaff(q));
    this.config = {
      tableId: 'admin-staff',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search staff by name or email…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No staff yet',
      emptyDescription: 'Admins and role-holders will appear here. Add a staff member to get started.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'admin-staff' },
      columns: [
        { key: 'name', label: 'Name', sortable: true, sticky: 'left', width: '14rem',
          value: (r) => `${r.first_name} ${r.last_name}` },
        { key: 'email', label: 'Email' },
        { key: 'roles', label: 'Roles', align: 'left',
          value: (r) => (r.is_admin ? 'Super Admin' : (r.assigned_roles ?? []).map((x) => x.name).join(', ') || '—') },
        { key: 'last_login', label: 'Last login', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
        { key: 'status', label: 'Status', align: 'center',
          value: (r) => (r.status ? 'Active' : 'Inactive') },
      ],
      rowActions: [
        { id: 'manage_roles', label: 'Manage roles', icon: 'admin_panel_settings',
          hidden: (r) => r.is_admin || !this.perms.can('users.manage_roles') },
        { id: 'activate', label: 'Activate', icon: 'check_circle',
          hidden: (r) => r.status || !this.perms.can('users.deactivate') },
        { id: 'deactivate', label: 'Deactivate', icon: 'block', variant: 'danger',
          hidden: (r) => !r.status || !this.perms.can('users.deactivate') },
        { id: 'password', label: 'Reset password', icon: 'key',
          hidden: () => !this.perms.can('users.edit') },
      ],
    };
  }

  private fetchStaff(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    return this.adapter.get_v3('GET /admin/users', { query: q }).pipe(
      map((response: any): AxServerFetchResult<StaffUser> => {
        const raw: any[] = response?.data ?? [];
        const rows = raw.map((u) => this.mapStaff(u));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load staff at this time.'));
        return of({ rows: [], total: 0 } as AxServerFetchResult<StaffUser>);
      }),
    );
  }

  /** Map the staff() shape into a flat row. */
  private mapStaff(u: any): StaffUser {
    return {
      ...u,
      id: u.id,
      first_name: u.first_name ?? '',
      last_name: u.last_name ?? '',
      email: u.email ?? '',
      is_admin: !!u.is_admin,
      last_login: u.last_login_at ?? '',
      status: u.is_active ?? true,
      assigned_roles: Array.isArray(u.assigned_roles) ? u.assigned_roles : [],
    } as StaffUser;
  }

  onRowAction(e: { action: { id: string }; row: StaffUser }) {
    const { action, row } = e;
    switch (action.id) {
      case 'manage_roles': return this.openRoles(row);
      case 'activate': return this.startActivate(row);
      case 'deactivate': return this.startDeactivate(row);
      case 'password': return this.openPassword(row);
    }
  }

  // ── Routed sub-pages (replace the old in-page drawers) ────────────────
  /** Open the routed create-staff page (/adminusers/new). */
  openCreate() { this.router.navigate(['/adminusers/new']); }

  /** Open the routed manage-roles page (/adminusers/:id/roles). */
  openRoles(row: StaffUser) { this.router.navigate(['/adminusers', row.id, 'roles']); }

  /** Open the routed reset-password page (/adminusers/:id/reset-password). */
  openPassword(row: StaffUser) { this.router.navigate(['/adminusers', row.id, 'reset-password']); }

  private refresh() { this.dataSource.retry(); }

  // ── Activate / deactivate ────────────────────────────────────────────
  private startActivate(row: StaffUser) {
    this.confirm.confirm({
      title: 'Activate staff member', message: `${row.first_name}'s account will be activated.`,
      confirmLabel: 'Activate', cancelLabel: 'Cancel',
    }).then((ok) => { if (ok) this.setActive(row.id, true); });
  }

  private startDeactivate(row: StaffUser) {
    this.confirm.confirm({
      title: 'Deactivate staff member', message: `${row.first_name} will lose access until reactivated.`,
      confirmLabel: 'Deactivate', cancelLabel: 'Cancel', variant: 'danger',
    }).then((ok) => { if (ok) this.setActive(row.id, false); });
  }

  private setActive(uid: number, active: boolean) {
    const key = active ? 'POST /admin/users/:id/activate' : 'POST /admin/users/:id/deactivate';
    this.adapter.post_v3(key, {}, { params: { id: String(uid) } }).subscribe({
      next: (r: any) => { if (r) { this.toast.success(active ? 'Staff member activated.' : 'Staff member deactivated.'); this.refresh(); } },
      error: (err: any) => this.toast.error(apiErrorMessage(err, 'Unable to complete your request at this time.')),
    });
  }

  // ── Roles management (matrix editor) ─────────────────────────────────
  switchView(v: 'staff' | 'roles') {
    this.view.set(v);
    if (v === 'roles') {
      this.loadRolesList();
    }
  }

  private loadRolesList() {
    this.ui.roles_list_loading = true;
    this.adapter.get_v3('GET /admin/roles').subscribe({
      next: (res: any) => {
        this.rolesList = (res?.data ?? []).map((r: any) => ({
          id: r.id, slug: r.slug, name: r.name, description: r.description ?? null,
          is_system: !!r.is_system, permissions: Array.isArray(r.permissions) ? r.permissions : [],
        }));
        this.ui.roles_list_loading = false;
      },
      error: (err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to load roles at this time.')); this.ui.roles_list_loading = false; },
    });
  }

  /** Open the deep-linkable role editor (/admin_role). Pass a role to edit it,
   *  or omit to create a new one. The matrix + save now live in RoleEditorComponent. */
  openRoleEditor(role?: RoleDetail) {
    this.router.navigate(['/admin_role'], role ? { queryParams: { id: role.id } } : {});
  }

  deleteRole(role: RoleDetail) {
    if (role.is_system) return;
    this.confirm.confirm({
      title: 'Delete role',
      message: `Delete the "${role.name}" role? Staff currently assigned will lose its permissions.`,
      confirmLabel: 'Delete role', cancelLabel: 'Cancel', variant: 'danger',
    }).then((ok) => {
      if (!ok) return;
      this.adapter.delete_v3('DELETE /admin/roles/:id', { params: { id: String(role.id) } }).subscribe({
        next: () => { this.toast.success('Role deleted.'); this.loadRolesList(); },
        error: (err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to delete the role.')); },
      });
    });
  }

  goBack() { this.router.navigate(['/backend']); }
}
