import { Component, OnInit, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TranslatePipe } from '../../../translate.pipe';
import type { RoleOption, RoleRef } from '../users.component';

/**
 * /adminusers/:id/roles — assign roles to a staff member.
 *
 * Extracted from the users page's in-place "Manage roles" drawer into a routed
 * sub-page (adminGuard). Loads the target staff member + the role list, then
 * POSTs the selected role ids. On success it returns to /adminusers.
 */
@Component({
  selector: 'app-staff-roles',
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
            <h1 class="ax-page-title">{{ 'users.manage_roles_title' | translate }}</h1>
            <p class="ax-page-subtitle" *ngIf="targetName">{{ targetName }}</p>
          </div>
        </header>

        <div *ngIf="ui.loading" class="ax-flex ax-justify-center ax-py-8">
          <span class="ax-spinner ax-spinner-lg" aria-label="Loading"></span>
        </div>

        <section *ngIf="!ui.loading" class="ax-page-section">
          <div class="ax-card ax-p-5">
            <div *ngIf="allRoles.length === 0" class="ax-text-sm ax-text-tertiary ax-py-4 ax-text-center">
              {{ 'users.no_roles_yet' | translate }}
            </div>
            <label *ngFor="let role of allRoles" class="ax-switch ax-card ax-p-3 ax-mb-2" style="cursor: pointer; align-items: flex-start;">
              <input type="checkbox" [checked]="isRoleSelected(role.id)" (change)="toggleRole(role.id)" />
              <span class="ax-switch-track" style="margin-top:.125rem;"></span>
              <span class="ax-switch-label" style="flex:1;">
                <span class="ax-font-medium ax-text-primary">{{ role.name }}</span>
                <span *ngIf="role.is_system" class="ax-badge ax-badge-neutral" style="margin-left:.5rem;">{{ 'users.system_badge' | translate }}</span>
                <span class="ax-text-2xs ax-text-tertiary" style="display:block; margin-top:.125rem;">{{ role.description || ('users.no_description' | translate) }}</span>
              </span>
            </label>

            <div style="margin-top: 1rem; display: flex; gap: .5rem;">
              <button type="button" class="ax-btn ax-btn-ghost" (click)="cancel()">{{ 'cancel' | translate }}</button>
              <button type="button" class="ax-btn ax-btn-primary" style="flex:1;" (click)="saveRoles()" [disabled]="ui.saving">
                <span *ngIf="ui.saving" class="ax-spinner ax-spinner-sm" aria-hidden="true"></span>
                <app-icon name="check_circle" *ngIf="!ui.saving" aria-hidden="true"></app-icon>
                {{ 'users.save_roles' | translate }}
              </button>
            </div>
          </div>
        </section>
      </div>
    </app-admin-shell>
  `,
})
export class StaffRolesComponent implements OnInit {
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private adapter = inject(PortalCrudAdapter);
  private toast = inject(HotToastService);

  staffId = 0;
  targetName = '';
  allRoles: RoleOption[] = [];
  selectedRoleIds: number[] = [];
  ui = { loading: false, saving: false };

  ngOnInit(): void {
    this.staffId = Number(this.route.snapshot.paramMap.get('id'));
    if (!this.staffId) { this.toast.error('Staff member not found.'); this.cancel(); return; }
    this.loadRoles();
    this.loadStaff();
  }

  private loadRoles(): void {
    this.adapter.get_v3('GET /admin/roles').subscribe({
      next: (res: any) => {
        this.allRoles = (res?.data ?? []).map((r: any) => ({
          id: r.id, slug: r.slug, name: r.name, description: r.description ?? null, is_system: !!r.is_system,
        }));
      },
      error: () => { this.toast.error('Unable to load roles at this time.'); },
    });
  }

  private loadStaff(): void {
    this.ui.loading = true;
    this.adapter.get_v3('GET /admin/users/:id', { params: { id: String(this.staffId) } }).subscribe({
      next: (res: any) => {
        const u = res?.data ?? res ?? {};
        this.targetName = `${u.first_name ?? ''} ${u.last_name ?? ''}`.trim();
        const assigned: RoleRef[] = Array.isArray(u.assigned_roles) ? u.assigned_roles : [];
        this.selectedRoleIds = assigned.map((r) => r.id);
        this.ui.loading = false;
      },
      error: () => { this.toast.error('Unable to load the staff member.'); this.ui.loading = false; this.cancel(); },
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

  saveRoles(): void {
    this.ui.saving = true;
    this.adapter.post_v3('POST /admin/users/:id/roles', { role_ids: this.selectedRoleIds }, { params: { id: String(this.staffId) } }).subscribe({
      next: (r: any) => {
        if (r) { this.toast.success('Roles updated.'); this.router.navigate(['/adminusers']); }
        this.ui.saving = false;
      },
      error: () => { this.toast.error('Unable to update roles at this time.'); this.ui.saving = false; },
    });
  }
}
