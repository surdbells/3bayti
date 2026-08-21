import { Component, OnInit, HostListener, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { apiErrorMessage } from '../../../shared/http/api-error';
import { AxConfirmService } from '../../../shared/overlays';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TranslatePipe } from '../../../translate.pipe';
import type { RoleDetail, CatalogModule, CatalogPreset } from '../users.component';

/**
 * /admin_role[?id=] — create or edit a role + its permission matrix.
 *
 * Production-grade permission matrix: per-module grouping with collapse, a
 * live permission search/filter over the full catalog, per-module select-all
 * (with an indeterminate "partial" header state), additive presets, a
 * dirty-state navigation guard, and explicit loading / catalog-error / empty
 * states so a failed catalog load can never be mistaken for a zero-permission
 * role. SYSTEM roles render read-only.
 *
 * On edit we hydrate the selection from GET /admin/roles/:id (params:{id})
 * directly, rather than fetching the whole list and find()-ing the row.
 */
@Component({
  selector: 'app-role-editor',
  standalone: true,
  imports: [CommonModule, FormsModule, AdminShellComponent, IconComponent, TranslatePipe],
  template: `
    <app-admin-shell>
      <div class="ax-container" style="max-width: 52rem;">
        <header class="ax-page-header">
          <div class="ax-page-header-content">
            <button type="button" (click)="cancel()" class="ax-btn ax-btn-ghost ax-btn-sm ax-mb-2" style="align-self:flex-start">
              <app-icon name="arrow_back" aria-hidden="true"></app-icon> {{ 'roles.back_to_users' | translate }}
            </button>
            <span class="ax-page-header-eyebrow">{{ 'roles.eyebrow' | translate }}</span>
            <h1 class="ax-page-title">
              {{ (editingRole ? 'roles.edit_title' : 'roles.create_title') | translate }}
            </h1>
            <p class="ax-page-subtitle">{{ editingRole ? editingRole.name : ('roles.create_subtitle' | translate) }}</p>
          </div>
        </header>

        <!-- Loading -->
        <div *ngIf="ui.loading" class="ax-flex ax-justify-center ax-py-8">
          <span class="ax-spinner ax-spinner-lg" [attr.aria-label]="'roles.loading' | translate"></span>
        </div>

        <!-- Catalog load error — distinct from an empty/zero-permission role -->
        <section *ngIf="!ui.loading && ui.catalogError" class="ax-page-section">
          <div class="ax-card ax-p-6 ax-text-center">
            <app-icon name="error" aria-hidden="true" class="ax-text-danger" style="font-size:2rem;"></app-icon>
            <p class="ax-text-primary ax-font-medium ax-mt-2 ax-mb-1">{{ 'roles.catalog_error_title' | translate }}</p>
            <p class="ax-text-2xs ax-text-tertiary ax-mb-3">{{ 'roles.catalog_error_body' | translate }}</p>
            <button type="button" class="ax-btn ax-btn-secondary ax-btn-sm" (click)="retryCatalog()">
              <app-icon name="refresh" aria-hidden="true"></app-icon> {{ 'roles.retry' | translate }}
            </button>
          </div>
        </section>

        <section *ngIf="!ui.loading && !ui.catalogError" class="ax-page-section">
          <!-- System role banner -->
          <div *ngIf="isSystem" class="ax-callout ax-callout-info ax-mb-3">
            <app-icon name="lock" aria-hidden="true"></app-icon>
            <div>
              <div class="ax-font-medium">{{ 'roles.system_banner_title' | translate }}</div>
              <div class="ax-text-2xs ax-text-tertiary">{{ 'roles.system_banner_body' | translate }}</div>
            </div>
          </div>

          <div class="ax-card ax-p-5">
            <div class="ax-form-field ax-mb-3">
              <label class="ax-label ax-text-2xs ax-label-required" for="role_name">{{ 'roles.name_label' | translate }}</label>
              <input id="role_name" class="ax-input ax-input-sm" type="text" [(ngModel)]="roleForm.name"
                     name="role_name" [disabled]="isSystem" [placeholder]="'roles.name_placeholder' | translate" />
            </div>
            <div class="ax-form-field ax-mb-4">
              <label class="ax-label ax-text-2xs" for="role_description">{{ 'roles.description_label' | translate }}</label>
              <input id="role_description" class="ax-input ax-input-sm" type="text" [(ngModel)]="roleForm.description"
                     name="role_description" [disabled]="isSystem" [placeholder]="'roles.description_placeholder' | translate" />
            </div>

            <!-- Presets (additive) -->
            <div *ngIf="catalogPresets.length && !isSystem" class="ax-mb-4">
              <div class="ax-text-2xs ax-text-tertiary ax-mb-2" style="text-transform: uppercase; letter-spacing: 0.06em;">
                {{ 'roles.presets_label' | translate }}
              </div>
              <div class="ax-flex ax-gap-1 ax-flex-wrap">
                <button *ngFor="let p of catalogPresets" type="button" class="ax-btn ax-btn-ghost ax-btn-sm"
                        (click)="applyPreset(p)" [title]="p.description || ''">
                  <app-icon name="add" aria-hidden="true"></app-icon> {{ p.name }}
                </button>
              </div>
            </div>

            <!-- Permission matrix toolbar -->
            <div class="ax-flex ax-items-center ax-justify-between ax-mb-2" style="gap:.5rem; flex-wrap:wrap;">
              <div class="ax-text-2xs ax-text-tertiary" style="text-transform: uppercase; letter-spacing: 0.06em;">
                {{ 'roles.permissions_label' | translate }}
                <span class="ax-badge ax-badge-info" style="margin-left:.375rem;">{{ selectedPerms.size }} {{ 'roles.selected_suffix' | translate }}</span>
              </div>
              <div class="ax-flex ax-gap-1 ax-items-center">
                <button type="button" class="ax-btn ax-btn-ghost ax-btn-sm" (click)="expandAll(true)">{{ 'roles.expand_all' | translate }}</button>
                <button type="button" class="ax-btn ax-btn-ghost ax-btn-sm" (click)="expandAll(false)">{{ 'roles.collapse_all' | translate }}</button>
              </div>
            </div>

            <!-- Search/filter -->
            <div class="ax-form-field ax-mb-3" style="position:relative;">
              <app-icon name="search" aria-hidden="true" style="position:absolute; left:.625rem; top:50%; transform:translateY(-50%); opacity:.5;"></app-icon>
              <input class="ax-input ax-input-sm" type="search" [(ngModel)]="search" name="perm_search"
                     style="padding-left:2rem;" [placeholder]="'roles.search_placeholder' | translate"
                     (ngModelChange)="onSearchChange()" />
            </div>

            <!-- Empty catalog (genuinely zero permissions in the system) -->
            <div *ngIf="catalogModules.length === 0" class="ax-card ax-p-5 ax-text-center">
              <p class="ax-text-secondary ax-m-0">{{ 'roles.catalog_empty' | translate }}</p>
            </div>

            <!-- No search matches -->
            <div *ngIf="catalogModules.length > 0 && visibleModules.length === 0"
                 class="ax-card ax-p-5 ax-text-center">
              <p class="ax-text-secondary ax-m-0">{{ 'roles.no_matches' | translate }}</p>
            </div>

            <!-- Module groups -->
            <div *ngFor="let m of visibleModules" class="ax-card ax-p-0 ax-mb-2" style="overflow:hidden;">
              <div class="ax-flex ax-items-center ax-p-3" style="gap:.5rem; background: var(--ax-color-bg-muted);">
                <button type="button" class="ax-btn ax-btn-ghost ax-btn-icon ax-btn-sm" (click)="toggleCollapse(m.module)"
                        [attr.aria-expanded]="!isCollapsed(m.module)" [attr.aria-label]="'roles.toggle_section' | translate">
                  <app-icon [name]="isCollapsed(m.module) ? 'chevron_right' : 'expand_more'" aria-hidden="true"></app-icon>
                </button>

                <!-- Module select-all toggle (indeterminate when partial) -->
                <label class="ax-switch ax-switch-sm" style="gap:.5rem;">
                  <input type="checkbox" [checked]="moduleAllSelected(m)"
                         [indeterminate]="modulePartiallySelected(m)"
                         [disabled]="isSystem" (change)="toggleModule(m)" />
                  <span class="ax-switch-track"></span>
                  <span class="ax-switch-label ax-font-medium ax-text-primary">{{ m.label }}</span>
                </label>

                <span class="ax-badge"
                      [class.ax-badge-success]="moduleAllSelected(m)"
                      [class.ax-badge-info]="modulePartiallySelected(m)"
                      [class.ax-badge-neutral]="moduleSelectedCount(m) === 0"
                      style="margin-left:auto; white-space:nowrap;">
                  {{ moduleSelectedCount(m) }}/{{ m.permissions.length }}
                </span>
              </div>

              <div *ngIf="!isCollapsed(m.module)" class="ax-grid ax-grid-cols-1 ax-md-grid-cols-2 ax-gap-1 ax-p-3">
                <label *ngFor="let p of visiblePermissions(m)" class="ax-switch ax-switch-sm">
                  <input type="checkbox" [checked]="isPermSelected(p.key)" [disabled]="isSystem" (change)="togglePerm(p.key)" />
                  <span class="ax-switch-track"></span>
                  <span class="ax-switch-label ax-text-sm">{{ p.label }}</span>
                </label>
              </div>
            </div>

            <div style="margin-top: 1rem; display: flex; gap: .5rem;">
              <button type="button" class="ax-btn ax-btn-ghost" (click)="cancel()">
                {{ (isSystem ? 'roles.close' : 'cancel') | translate }}
              </button>
              <button *ngIf="!isSystem" type="button" class="ax-btn ax-btn-primary" style="flex: 1;"
                      (click)="saveRole()" [disabled]="ui.saving || !dirty">
                <span *ngIf="ui.saving" class="ax-spinner ax-spinner-sm" aria-hidden="true"></span>
                <app-icon name="check_circle" *ngIf="!ui.saving" aria-hidden="true"></app-icon>
                {{ (editingRole ? 'roles.save_changes' : 'roles.create_role') | translate }}
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
  private confirm = inject(AxConfirmService);

  editingRole: RoleDetail | null = null;
  roleForm = { name: '', description: '' };
  selectedPerms = new Set<string>();
  catalogModules: CatalogModule[] = [];
  catalogPresets: CatalogPreset[] = [];

  search = '';
  /** Lower-cased search term, recomputed on change for cheap filtering. */
  private searchTerm = '';
  private collapsed = new Set<string>();

  ui = { loading: false, saving: false, catalogError: false };

  /** Snapshot of the saved state, for the dirty-guard comparison. */
  private baseline = { name: '', description: '', perms: '' };

  ngOnInit(): void {
    const id = Number(this.route.snapshot.queryParamMap.get('id'));
    if (id) {
      this.loadRole(id);
    } else {
      this.loadCatalog();
      this.captureBaseline();
    }
  }

  // ── Derived state ──────────────────────────────────────────────────────
  get isSystem(): boolean { return !!this.editingRole?.is_system; }

  get dirty(): boolean {
    if (this.isSystem) return false;
    return (
      this.roleForm.name.trim() !== this.baseline.name ||
      this.roleForm.description.trim() !== this.baseline.description ||
      this.permsSignature() !== this.baseline.perms
    );
  }

  /** Modules visible after applying the search filter. */
  get visibleModules(): CatalogModule[] {
    if (!this.searchTerm) return this.catalogModules;
    return this.catalogModules.filter((m) => this.visiblePermissions(m).length > 0);
  }

  visiblePermissions(m: CatalogModule): CatalogModule['permissions'] {
    if (!this.searchTerm) return m.permissions;
    const t = this.searchTerm;
    if (m.label.toLowerCase().includes(t)) return m.permissions;
    return m.permissions.filter(
      (p) => p.label.toLowerCase().includes(t) || p.key.toLowerCase().includes(t),
    );
  }

  // ── Data loading ───────────────────────────────────────────────────────
  retryCatalog(): void {
    const id = Number(this.route.snapshot.queryParamMap.get('id'));
    if (id) { this.loadRole(id); } else { this.loadCatalog(); }
  }

  private loadCatalog(): void {
    this.ui.catalogError = false;
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
      error: (err: any) => {
        this.ui.catalogError = true;
        this.toast.error(apiErrorMessage(err, 'Unable to load the permission catalog.'));
      },
    });
  }

  /** Edit: hydrate from GET /admin/roles/:id directly (no list + find). */
  private loadRole(id: number): void {
    this.ui.loading = true;
    this.ui.catalogError = false;
    this.loadCatalog();
    this.adapter.get_v3('GET /admin/roles/:id', { params: { id: String(id) } }).subscribe({
      next: (res: any) => {
        const r = res?.data ?? null;
        if (r && r.id != null) {
          this.editingRole = {
            id: r.id, slug: r.slug, name: r.name, description: r.description ?? null,
            is_system: !!r.is_system, permissions: Array.isArray(r.permissions) ? r.permissions : [],
          };
          this.roleForm = { name: r.name, description: r.description ?? '' };
          this.selectedPerms = new Set(this.editingRole.permissions);
          this.captureBaseline();
        } else {
          this.toast.error('Role not found.');
        }
        this.ui.loading = false;
      },
      error: (err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to load the role.')); this.ui.loading = false; },
    });
  }

  // ── Permission selection ───────────────────────────────────────────────
  isPermSelected(key: string): boolean { return this.selectedPerms.has(key); }

  togglePerm(key: string): void {
    if (this.isSystem) return;
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

  modulePartiallySelected(m: CatalogModule): boolean {
    const n = this.moduleSelectedCount(m);
    return n > 0 && n < m.permissions.length;
  }

  toggleModule(m: CatalogModule): void {
    if (this.isSystem) return;
    const all = this.moduleAllSelected(m);
    const next = new Set(this.selectedPerms);
    for (const p of m.permissions) { if (all) { next.delete(p.key); } else { next.add(p.key); } }
    this.selectedPerms = next;
  }

  /** Presets are ADDITIVE — they union onto the current selection rather than
   *  silently replacing it (the old behaviour wiped any custom picks). */
  applyPreset(p: CatalogPreset): void {
    if (this.isSystem) return;
    const next = new Set(this.selectedPerms);
    let added = 0;
    for (const key of p.permissions) { if (!next.has(key)) { next.add(key); added++; } }
    this.selectedPerms = next;
    this.toast.success(
      added > 0
        ? `Added ${added} permission${added === 1 ? '' : 's'} from "${p.name}".`
        : `"${p.name}" permissions were already selected.`,
    );
  }

  // ── Collapse / search ──────────────────────────────────────────────────
  isCollapsed(module: string): boolean { return this.collapsed.has(module); }

  toggleCollapse(module: string): void {
    const next = new Set(this.collapsed);
    if (next.has(module)) { next.delete(module); } else { next.add(module); }
    this.collapsed = next;
  }

  expandAll(expand: boolean): void {
    this.collapsed = expand ? new Set() : new Set(this.catalogModules.map((m) => m.module));
  }

  onSearchChange(): void {
    this.searchTerm = this.search.trim().toLowerCase();
    // While searching, auto-expand so matches are visible.
    if (this.searchTerm) this.collapsed = new Set();
  }

  // ── Dirty guard ────────────────────────────────────────────────────────
  private permsSignature(): string {
    return Array.from(this.selectedPerms).sort().join(',');
  }

  private captureBaseline(): void {
    this.baseline = {
      name: this.roleForm.name.trim(),
      description: this.roleForm.description.trim(),
      perms: this.permsSignature(),
    };
  }

  /** Warn on browser/tab close while there are unsaved edits. */
  @HostListener('window:beforeunload', ['$event'])
  onBeforeUnload(e: BeforeUnloadEvent): void {
    if (this.dirty) { e.preventDefault(); e.returnValue = ''; }
  }

  async cancel(): Promise<void> {
    if (this.dirty) {
      const ok = await this.confirm.confirm({
        title: 'Discard changes?',
        message: 'You have unsaved permission changes. Leaving will discard them.',
        confirmLabel: 'Discard', cancelLabel: 'Keep editing', variant: 'danger',
      });
      if (!ok) return;
    }
    void this.router.navigate(['/adminusers'], { queryParams: { view: 'roles' } });
  }

  saveRole(): void {
    if (this.isSystem) return;
    const name = this.roleForm.name.trim();
    if (!name) { this.toast.error('A role name is required.'); return; }
    this.ui.saving = true;
    const payload = { name, description: this.roleForm.description.trim(), permissions: Array.from(this.selectedPerms) };
    const done = () => { this.ui.saving = false; };
    const back = () => {
      this.captureBaseline(); // clears dirty so the guard doesn't fire on navigate
      void this.router.navigate(['/adminusers'], { queryParams: { view: 'roles' } });
    };
    if (this.editingRole) {
      this.adapter.put_v3('PUT /admin/roles/:id', payload, { params: { id: String(this.editingRole.id) } }).subscribe({
        next: (r: any) => { if (r) { this.toast.success('Role updated.'); back(); } done(); },
        error: (err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to save the role.')); done(); },
      });
    } else {
      this.adapter.post_v3('POST /admin/roles', payload).subscribe({
        next: (r: any) => { if (r) { this.toast.success('Role created.'); back(); } done(); },
        error: (err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to create the role.')); done(); },
      });
    }
  }
}
