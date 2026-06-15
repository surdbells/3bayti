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
    FormsModule,
    AxDataTableComponent,
    AxCellDirective,
    AxCanDirective,
    TranslatePipe,
    IconComponent,
  ],
  templateUrl: './users.component.html',
  styleUrl: './users.component.css',
})
export class UsersComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);
  protected readonly perms = inject(PermissionService);

  // Drawer visibility
  protected readonly createOpen = signal(false);
  protected readonly pwOpen = signal(false);
  protected readonly rolesOpen = signal(false);
  protected readonly roleEditorOpen = signal(false);

  // View toggle (Staff | Roles)
  protected readonly view = signal<'staff' | 'roles'>('staff');

  ui = {
    registering: false,
    updating_password: false,
    saving_roles: false,
    roles_loading: false,
    roles_list_loading: false,
    saving_role: false,
  };

  register = {
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    confirm_password: '',
  };

  // Password reset target
  pwTarget = { id: 0, first_name: '', last_name: '' };
  pwValue = '';

  // Role assignment state
  rolesTarget: StaffUser | null = null;
  allRoles: RoleOption[] = [];
  selectedRoleIds: number[] = [];

  // Roles management (matrix editor) state
  rolesList: RoleDetail[] = [];
  catalogModules: CatalogModule[] = [];
  catalogPresets: CatalogPreset[] = [];
  private catalogLoaded = false;
  editingRole: RoleDetail | null = null;
  roleForm = { name: '', description: '' };
  selectedPerms = new Set<string>();

  config!: AxDataTableConfig<StaffUser>;
  dataSource!: AxServerDataSource<StaffUser>;

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.perms.load();
    this.buildTable();
    this.loadRoles();
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
      catchError(() => {
        this.toast.error('Unable to load staff at this time.');
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
      error: () => this.toast.error('Unable to complete your request at this time.'),
    });
  }

  // ── Create staff ─────────────────────────────────────────────────────
  openCreate() {
    this.register = { first_name: '', last_name: '', email: '', password: '', confirm_password: '' };
    this.createOpen.set(true);
  }
  closeCreate() { this.createOpen.set(false); }

  user_register() {
    const r = this.register;
    if (!r.first_name) { this.toast.error('First name is required'); return; }
    if (!r.last_name) { this.toast.error('Last name is required'); return; }
    if (!r.email) { this.toast.error('Email address is required'); return; }
    if (!GlobalComponent.validateEmail(r.email)) { this.toast.error('Invalid email format provided'); return; }
    if (!r.password) { this.toast.error('Password is required'); return; }
    if (r.password !== r.confirm_password) { this.toast.error('Password does not match'); return; }

    this.ui.registering = true;
    this.adapter.post_v3('POST /admin/users', this.register).subscribe({
      next: (response: any) => {
        if (response) {
          this.toast.success('Staff member created. Assign roles to grant access.');
          this.register = { first_name: '', last_name: '', email: '', password: '', confirm_password: '' };
          this.createOpen.set(false);
          this.refresh();
        }
        this.ui.registering = false;
      },
      error: () => { this.toast.error('Unable to complete your request at this time.'); this.ui.registering = false; },
    });
  }

  // ── Roles ────────────────────────────────────────────────────────────
  private loadRoles() {
    this.ui.roles_loading = true;
    this.adapter.get_v3('GET /admin/roles').subscribe({
      next: (res: any) => {
        this.allRoles = (res?.data ?? []).map((r: any) => ({
          id: r.id, slug: r.slug, name: r.name, description: r.description ?? null, is_system: !!r.is_system,
        }));
        this.ui.roles_loading = false;
      },
      error: () => { this.ui.roles_loading = false; },
    });
  }

  openRoles(row: StaffUser) {
    this.rolesTarget = row;
    this.selectedRoleIds = (row.assigned_roles ?? []).map((r) => r.id);
    if (this.allRoles.length === 0) this.loadRoles();
    this.rolesOpen.set(true);
  }

  closeRoles() { this.rolesOpen.set(false); this.rolesTarget = null; }

  isRoleSelected(id: number): boolean { return this.selectedRoleIds.includes(id); }

  toggleRole(id: number) {
    this.selectedRoleIds = this.isRoleSelected(id)
      ? this.selectedRoleIds.filter((x) => x !== id)
      : [...this.selectedRoleIds, id];
  }

  saveRoles() {
    if (!this.rolesTarget) return;
    this.ui.saving_roles = true;
    this.adapter.post_v3('POST /admin/users/:id/roles', { role_ids: this.selectedRoleIds }, { params: { id: String(this.rolesTarget.id) } }).subscribe({
      next: (r: any) => {
        if (r) { this.toast.success('Roles updated.'); this.closeRoles(); this.refresh(); }
        this.ui.saving_roles = false;
      },
      error: () => { this.toast.error('Unable to update roles at this time.'); this.ui.saving_roles = false; },
    });
  }

  // ── Password reset ───────────────────────────────────────────────────
  openPassword(row: StaffUser) {
    this.pwTarget = { id: row.id, first_name: row.first_name, last_name: row.last_name };
    this.pwValue = '';
    this.pwOpen.set(true);
  }

  closePassword() { this.pwOpen.set(false); }

  update_password() {
    if (!this.pwValue) { this.toast.error('New password is required.'); return; }
    this.ui.updating_password = true;
    this.adapter.patch_v3('PATCH /admin/users/:id/password', { password: this.pwValue }, { params: { id: String(this.pwTarget.id) } }).subscribe({
      next: (response: any) => {
        if (response) { this.toast.success('Password updated.'); this.pwValue = ''; this.pwOpen.set(false); }
        this.ui.updating_password = false;
      },
      error: () => { this.toast.error('Unable to complete your request at this time.'); this.ui.updating_password = false; },
    });
  }

  // ── Roles management (matrix editor) ─────────────────────────────────
  switchView(v: 'staff' | 'roles') {
    this.view.set(v);
    if (v === 'roles') {
      this.loadRolesList();
      this.loadCatalog();
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
        // Keep the assign-roles drawer options in sync with any new/edited roles.
        this.allRoles = this.rolesList.map((r) => ({
          id: r.id, slug: r.slug, name: r.name, description: r.description, is_system: r.is_system,
        }));
        this.ui.roles_list_loading = false;
      },
      error: () => { this.toast.error('Unable to load roles at this time.'); this.ui.roles_list_loading = false; },
    });
  }

  private loadCatalog() {
    if (this.catalogLoaded) return;
    this.adapter.get_v3('GET /admin/permission-catalog').subscribe({
      next: (res: any) => {
        const d = res?.data ?? {};
        this.catalogModules = (d.modules ?? []).map((m: any) => ({
          module: m.module, label: m.label,
          permissions: (m.permissions ?? []).map((p: any) => ({ key: p.key, label: p.label })),
        }));
        this.catalogPresets = (d.presets ?? []).map((p: any) => ({
          slug: p.slug, name: p.name, description: p.description ?? null,
          permissions: Array.isArray(p.permissions) ? p.permissions : [],
        }));
        this.catalogLoaded = true;
      },
      error: () => { this.toast.error('Unable to load the permission catalog.'); },
    });
  }

  openRoleEditor(role?: RoleDetail) {
    this.loadCatalog();
    if (role) {
      this.editingRole = role;
      this.roleForm = { name: role.name, description: role.description ?? '' };
      this.selectedPerms = new Set(role.permissions);
    } else {
      this.editingRole = null;
      this.roleForm = { name: '', description: '' };
      this.selectedPerms = new Set<string>();
    }
    this.roleEditorOpen.set(true);
  }

  closeRoleEditor() { this.roleEditorOpen.set(false); this.editingRole = null; }

  isPermSelected(key: string): boolean { return this.selectedPerms.has(key); }

  togglePerm(key: string) {
    const next = new Set(this.selectedPerms);
    if (next.has(key)) next.delete(key); else next.add(key);
    this.selectedPerms = next;
  }

  moduleSelectedCount(m: CatalogModule): number {
    return m.permissions.filter((p) => this.selectedPerms.has(p.key)).length;
  }

  moduleAllSelected(m: CatalogModule): boolean {
    return m.permissions.length > 0 && m.permissions.every((p) => this.selectedPerms.has(p.key));
  }

  toggleModule(m: CatalogModule) {
    const all = this.moduleAllSelected(m);
    const next = new Set(this.selectedPerms);
    for (const p of m.permissions) { if (all) next.delete(p.key); else next.add(p.key); }
    this.selectedPerms = next;
  }

  applyPreset(p: CatalogPreset) {
    this.selectedPerms = new Set(p.permissions);
    this.toast.success(`Applied the "${p.name}" preset.`);
  }

  saveRole() {
    const name = this.roleForm.name.trim();
    if (!name) { this.toast.error('A role name is required.'); return; }
    this.ui.saving_role = true;
    const payload = { name, description: this.roleForm.description.trim(), permissions: Array.from(this.selectedPerms) };
    const done = () => { this.ui.saving_role = false; };
    if (this.editingRole) {
      this.adapter.put_v3('PUT /admin/roles/:id', payload, { params: { id: String(this.editingRole.id) } }).subscribe({
        next: (r: any) => { if (r) { this.toast.success('Role updated.'); this.roleEditorOpen.set(false); this.loadRolesList(); } done(); },
        error: () => { this.toast.error('Unable to save the role.'); done(); },
      });
    } else {
      this.adapter.post_v3('POST /admin/roles', payload).subscribe({
        next: (r: any) => { if (r) { this.toast.success('Role created.'); this.roleEditorOpen.set(false); this.loadRolesList(); } done(); },
        error: () => { this.toast.error('Unable to create the role.'); done(); },
      });
    }
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
        error: () => { this.toast.error('Unable to delete the role.'); },
      });
    });
  }

  goBack() { this.router.navigate(['/backend']); }
}
