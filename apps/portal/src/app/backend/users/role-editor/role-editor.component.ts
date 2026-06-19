import { Component, OnInit, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import type { RoleDetail, CatalogModule, CatalogPreset } from '../users.component';

/**
 * /admin_role[?id=] — create or edit a role + its permission matrix.
 *
 * Extracted from the users page's in-place drawer into a deep-linkable
 * routed page (the role editor is a full sub-page's worth of UI). Reuses the
 * existing role/catalog endpoints; on save it returns to /adminusers.
 */
@Component({
  selector: 'app-role-editor',
  standalone: true,
  imports: [CommonModule, FormsModule, AdminShellComponent, IconComponent],
  template: `
    <app-admin-shell>
      <div class="ax-container" style="max-width: 48rem;">
        <header class="ax-page-header">
          <div class="ax-page-header-content">
            <button type="button" (click)="cancel()" class="ax-btn ax-btn-ghost ax-btn-sm ax-mb-2" style="align-self:flex-start">
              <app-icon name="arrow_back" aria-hidden="true"></app-icon> Back to users
            </button>
            <span class="ax-page-header-eyebrow">Roles &amp; permissions</span>
            <h1 class="ax-page-title">{{ editingRole ? 'Edit role' : 'Create role' }}</h1>
            <p class="ax-page-subtitle">{{ editingRole ? editingRole.name : 'Define a role and the permissions it grants.' }}</p>
          </div>
        </header>

        <div *ngIf="ui.loading" class="ax-flex ax-justify-center ax-py-8">
          <span class="ax-spinner ax-spinner-lg" aria-label="Loading role"></span>
        </div>

        <section *ngIf="!ui.loading" class="ax-page-section">
          <div class="ax-card ax-p-5">
            <div class="ax-form-field ax-mb-3">
              <label class="ax-label ax-text-2xs ax-label-required" for="role_name">Role name</label>
              <input id="role_name" class="ax-input ax-input-sm" type="text" [(ngModel)]="roleForm.name" name="role_name" placeholder="e.g. Refund Desk" />
            </div>
            <div class="ax-form-field ax-mb-4">
              <label class="ax-label ax-text-2xs" for="role_description">Description</label>
              <input id="role_description" class="ax-input ax-input-sm" type="text" [(ngModel)]="roleForm.description" name="role_description" placeholder="What this role is for" />
            </div>

            <div *ngIf="catalogPresets.length" class="ax-mb-4">
              <div class="ax-text-2xs ax-text-tertiary ax-mb-2" style="text-transform: uppercase; letter-spacing: 0.06em;">Start from a preset</div>
              <div class="ax-flex ax-gap-1 ax-flex-wrap">
                <button *ngFor="let p of catalogPresets" type="button" class="ax-btn ax-btn-ghost ax-btn-sm" (click)="applyPreset(p)">{{ p.name }}</button>
              </div>
            </div>

            <div class="ax-text-2xs ax-text-tertiary ax-mb-2" style="text-transform: uppercase; letter-spacing: 0.06em;">Permissions</div>
            <div *ngFor="let m of catalogModules" class="ax-card ax-p-3 ax-mb-2">
              <label class="ax-check" style="display: flex; align-items: center; gap: .5rem; margin-bottom: .5rem;">
                <input type="checkbox" class="ax-check-box" [checked]="moduleAllSelected(m)" (change)="toggleModule(m)" />
                <span class="ax-font-medium ax-text-primary">{{ m.label }}</span>
                <span class="ax-text-2xs ax-text-tertiary" style="margin-left: auto;">{{ moduleSelectedCount(m) }}/{{ m.permissions.length }}</span>
              </label>
              <div class="ax-grid ax-grid-cols-1 ax-md-grid-cols-2 ax-gap-1" style="padding-left: 1.5rem;">
                <label *ngFor="let p of m.permissions" class="ax-check" style="display: flex; align-items: center; gap: .5rem;">
                  <input type="checkbox" class="ax-check-box" [checked]="isPermSelected(p.key)" (change)="togglePerm(p.key)" />
                  <span class="ax-check-label ax-text-sm">{{ p.label }}</span>
                </label>
              </div>
            </div>

            <div style="margin-top: 1rem; display: flex; gap: .5rem;">
              <button type="button" class="ax-btn ax-btn-ghost" (click)="cancel()">Cancel</button>
              <button type="button" class="ax-btn ax-btn-primary" style="flex: 1;" (click)="saveRole()" [disabled]="ui.saving">
                <span *ngIf="ui.saving" class="ax-spinner ax-spinner-sm" aria-hidden="true"></span>
                <app-icon name="check_circle" *ngIf="!ui.saving" aria-hidden="true"></app-icon>
                {{ editingRole ? 'Save changes' : 'Create role' }}
              </button>
            </div>
          </div>
        </section>
      </div>
    </app-admin-shell>
  `,
})
export class RoleEditorComponent implements OnInit {
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private adapter = inject(PortalCrudAdapter);
  private toast = inject(HotToastService);

  editingRole: RoleDetail | null = null;
  roleForm = { name: '', description: '' };
  selectedPerms = new Set<string>();
  catalogModules: CatalogModule[] = [];
  catalogPresets: CatalogPreset[] = [];
  ui = { loading: false, saving: false };

  ngOnInit(): void {
    this.loadCatalog();
    const id = Number(this.route.snapshot.queryParamMap.get('id'));
    if (id) this.loadRole(id);
  }

  private loadCatalog(): void {
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
      },
      error: () => this.toast.error('Unable to load the permission catalog.'),
    });
  }

  private loadRole(id: number): void {
    this.ui.loading = true;
    this.adapter.get_v3('GET /admin/roles').subscribe({
      next: (res: any) => {
        const r = (res?.data ?? []).find((x: any) => x.id === id);
        if (r) {
          this.editingRole = {
            id: r.id, slug: r.slug, name: r.name, description: r.description ?? null,
            is_system: !!r.is_system, permissions: Array.isArray(r.permissions) ? r.permissions : [],
          };
          this.roleForm = { name: r.name, description: r.description ?? '' };
          this.selectedPerms = new Set(this.editingRole.permissions);
        } else {
          this.toast.error('Role not found.');
        }
        this.ui.loading = false;
      },
      error: () => { this.toast.error('Unable to load the role.'); this.ui.loading = false; },
    });
  }

  isPermSelected(key: string): boolean { return this.selectedPerms.has(key); }

  togglePerm(key: string): void {
    const next = new Set(this.selectedPerms);
    if (next.has(key)) { next.delete(key); } else { next.add(key); }
    this.selectedPerms = next;
  }

  moduleSelectedCount(m: CatalogModule): number {
    return m.permissions.filter((p) => this.selectedPerms.has(p.key)).length;
  }

  moduleAllSelected(m: CatalogModule): boolean {
    return m.permissions.length > 0 && m.permissions.every((p) => this.selectedPerms.has(p.key));
  }

  toggleModule(m: CatalogModule): void {
    const all = this.moduleAllSelected(m);
    const next = new Set(this.selectedPerms);
    for (const p of m.permissions) { if (all) { next.delete(p.key); } else { next.add(p.key); } }
    this.selectedPerms = next;
  }

  applyPreset(p: CatalogPreset): void {
    this.selectedPerms = new Set(p.permissions);
    this.toast.success(`Applied the "${p.name}" preset.`);
  }

  cancel(): void {
    this.router.navigate(['/adminusers']);
  }

  saveRole(): void {
    const name = this.roleForm.name.trim();
    if (!name) { this.toast.error('A role name is required.'); return; }
    this.ui.saving = true;
    const payload = { name, description: this.roleForm.description.trim(), permissions: Array.from(this.selectedPerms) };
    const done = () => { this.ui.saving = false; };
    const back = () => this.router.navigate(['/adminusers']);
    if (this.editingRole) {
      this.adapter.put_v3('PUT /admin/roles/:id', payload, { params: { id: String(this.editingRole.id) } }).subscribe({
        next: (r: any) => { if (r) { this.toast.success('Role updated.'); void back(); } done(); },
        error: () => { this.toast.error('Unable to save the role.'); done(); },
      });
    } else {
      this.adapter.post_v3('POST /admin/roles', payload).subscribe({
        next: (r: any) => { if (r) { this.toast.success('Role created.'); void back(); } done(); },
        error: () => { this.toast.error('Unable to create the role.'); done(); },
      });
    }
  }
}
