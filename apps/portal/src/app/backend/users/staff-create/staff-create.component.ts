import { Component, OnInit, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { apiErrorMessage } from '../../../shared/http/api-error';
import { GlobalComponent } from '../../../global-component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TranslatePipe } from '../../../translate.pipe';
import type { RoleOption } from '../users.component';

/**
 * /adminusers/new — create a staff member.
 *
 * Create → assign is reachable in one flow: the form includes a role picker
 * (GET /admin/roles). On a successful POST /admin/users we read the created
 * id from the response and chain POST /admin/users/:id/roles with the chosen
 * role_ids, so the new user lands holding a role and visible in the list. If
 * no role is picked we still create the account (it just starts with no
 * access), and if the role-assign call fails we keep the user and route them
 * to the manage-roles page to finish.
 */
@Component({
  selector: 'app-staff-create',
  standalone: true,
  imports: [CommonModule, FormsModule, AdminShellComponent, IconComponent, TranslatePipe],
  template: `
    <app-admin-shell>
      <div class="ax-container" style="max-width: 40rem;">
        <header class="ax-page-header">
          <div class="ax-page-header-content">
            <button type="button" (click)="cancel()" class="ax-btn ax-btn-ghost ax-btn-sm ax-mb-2" style="align-self:flex-start">
              <app-icon name="arrow_back" aria-hidden="true"></app-icon> {{ 'users.back_to_users' | translate }}
            </button>
            <span class="ax-page-header-eyebrow">{{ 'users.access_control' | translate }}</span>
            <h1 class="ax-page-title">{{ 'users.add_staff_title' | translate }}</h1>
            <p class="ax-page-subtitle">{{ 'users.add_staff_subtitle' | translate }}</p>
          </div>
        </header>

        <section class="ax-page-section">
          <div class="ax-card ax-p-5">
            <div class="ax-grid ax-grid-cols-1 ax-md-grid-cols-2 ax-gap-2 ax-mb-3">
              <div class="ax-form-field">
                <label class="ax-label ax-text-2xs ax-label-required" for="first_name">{{ 'first_name' | translate }}</label>
                <input id="first_name" autocomplete="off" [(ngModel)]="register.first_name" name="first_name" class="ax-input ax-input-sm" type="text" />
              </div>
              <div class="ax-form-field">
                <label class="ax-label ax-text-2xs ax-label-required" for="last_name">{{ 'last_name' | translate }}</label>
                <input id="last_name" autocomplete="off" [(ngModel)]="register.last_name" name="last_name" class="ax-input ax-input-sm" type="text" />
              </div>
            </div>
            <div class="ax-form-field ax-mb-3">
              <label class="ax-label ax-text-2xs ax-label-required" for="email">{{ 'email' | translate }}</label>
              <input id="email" autocomplete="off" [(ngModel)]="register.email" name="email" class="ax-input ax-input-sm" type="email" />
            </div>
            <div class="ax-grid ax-grid-cols-1 ax-md-grid-cols-2 ax-gap-2 ax-mb-3">
              <div class="ax-form-field">
                <label class="ax-label ax-text-2xs ax-label-required" for="password">{{ 'password' | translate }}</label>
                <input id="password" autocomplete="new-password" [(ngModel)]="register.password" name="password" class="ax-input ax-input-sm" type="password" />
              </div>
              <div class="ax-form-field">
                <label class="ax-label ax-text-2xs ax-label-required" for="confirm_password">{{ 'confirm_password' | translate }}</label>
                <input id="confirm_password" autocomplete="new-password" [(ngModel)]="register.confirm_password" name="confirm_password" class="ax-input ax-input-sm" type="password" />
              </div>
            </div>

            <!-- Role picker ─ create → assign in one flow -->
            <div class="ax-form-field ax-mb-3">
              <label class="ax-label ax-text-2xs">{{ 'users.assign_roles_label' | translate }}</label>

              <div *ngIf="ui.rolesLoading" class="ax-text-2xs ax-text-tertiary ax-py-2">
                <span class="ax-spinner ax-spinner-sm" aria-hidden="true"></span> {{ 'users.loading_roles' | translate }}
              </div>

              <div *ngIf="!ui.rolesLoading && roles.length === 0" class="ax-text-2xs ax-text-tertiary ax-py-2">
                {{ 'users.no_roles_to_assign' | translate }}
              </div>

              <div *ngIf="!ui.rolesLoading && roles.length > 0" class="ax-flex ax-flex-col ax-gap-1">
                <label *ngFor="let role of roles" class="ax-switch ax-card ax-p-2" style="cursor:pointer;">
                  <input type="checkbox" [checked]="isRoleSelected(role.id)" (change)="toggleRole(role.id)" />
                  <span class="ax-switch-track"></span>
                  <span class="ax-switch-label">
                    <span class="ax-font-medium ax-text-primary">{{ role.name }}</span>
                    <span *ngIf="role.is_system" class="ax-badge ax-badge-neutral" style="margin-left:.5rem;">{{ 'users.system_badge' | translate }}</span>
                    <span class="ax-text-2xs ax-text-tertiary" style="display:block;">{{ role.description || ('users.no_description' | translate) }}</span>
                  </span>
                </label>
              </div>
            </div>

            <div class="ax-callout ax-callout-info ax-text-2xs ax-mb-4">
              <app-icon name="admin_panel_settings" aria-hidden="true"></app-icon>
              {{ 'users.new_staff_hint' | translate }}
            </div>
            <div class="ax-flex ax-gap-2">
              <button type="button" class="ax-btn ax-btn-ghost" (click)="cancel()">{{ 'cancel' | translate }}</button>
              <button type="button" class="ax-btn ax-btn-primary" style="flex:1;" (click)="user_register()" [disabled]="ui.registering">
                <span *ngIf="ui.registering" class="ax-spinner ax-spinner-sm" aria-hidden="true"></span>
                <app-icon name="person_add" *ngIf="!ui.registering" aria-hidden="true"></app-icon>
                {{ 'users.create_staff' | translate }}
              </button>
            </div>
          </div>
        </section>
      </div>
    </app-admin-shell>
  `,
})
export class StaffCreateComponent implements OnInit {
  private router = inject(Router);
  private adapter = inject(PortalCrudAdapter);
  private toast = inject(HotToastService);

  register = {
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    confirm_password: '',
  };

  roles: RoleOption[] = [];
  selectedRoleIds: number[] = [];

  ui = { registering: false, rolesLoading: false };

  ngOnInit(): void {
    this.loadRoles();
  }

  private loadRoles(): void {
    this.ui.rolesLoading = true;
    this.adapter.get_v3('GET /admin/roles').subscribe({
      next: (res: any) => {
        this.roles = (res?.data ?? []).map((r: any) => ({
          id: r.id, slug: r.slug, name: r.name, description: r.description ?? null, is_system: !!r.is_system,
        }));
        this.ui.rolesLoading = false;
      },
      error: (err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to load roles at this time.')); this.ui.rolesLoading = false; },
    });
  }

  isRoleSelected(id: number): boolean { return this.selectedRoleIds.includes(id); }

  toggleRole(id: number): void {
    this.selectedRoleIds = this.isRoleSelected(id)
      ? this.selectedRoleIds.filter((x) => x !== id)
      : [...this.selectedRoleIds, id];
  }

  cancel(): void {
    this.router.navigate(['/adminusers']);
  }

  user_register(): void {
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
        const newId = Number(response?.data?.id ?? response?.id);
        if (!newId) {
          // Created but we can't resolve the id — fall back to the list.
          this.toast.success('Staff member created.');
          this.ui.registering = false;
          this.router.navigate(['/adminusers']);
          return;
        }
        if (this.selectedRoleIds.length === 0) {
          this.toast.success('Staff member created. Assign roles to grant access.');
          this.ui.registering = false;
          this.router.navigate(['/adminusers']);
          return;
        }
        this.assignRoles(newId);
      },
      error: (err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to complete your request at this time.')); this.ui.registering = false; },
    });
  }

  /** Chain the role assignment onto the freshly-created user id. */
  private assignRoles(userId: number): void {
    this.adapter.post_v3('POST /admin/users/:id/roles', { role_ids: this.selectedRoleIds }, { params: { id: String(userId) } }).subscribe({
      next: () => {
        this.toast.success('Staff member created and roles assigned.');
        this.ui.registering = false;
        this.router.navigate(['/adminusers']);
      },
      error: (err: any) => {
        // The account exists; only the assignment failed. Send the admin to the
        // manage-roles page to finish rather than losing the chosen roles.
        this.toast.error(apiErrorMessage(err, 'Account created, but assigning roles failed. Finish on the roles page.'));
        this.ui.registering = false;
        this.router.navigate(['/adminusers', userId, 'roles']);
      },
    });
  }
}
