import { Component, OnInit, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TranslatePipe } from '../../../translate.pipe';

/**
 * /adminusers/:id/reset-password — set a new password for a staff member.
 *
 * Extracted from the users page's in-place "Reset password" drawer into a
 * routed sub-page (adminGuard). Loads the target's name for context, then
 * PATCHes the new password. On success it returns to /adminusers.
 */
@Component({
  selector: 'app-staff-password',
  standalone: true,
  imports: [CommonModule, FormsModule, AdminShellComponent, IconComponent, TranslatePipe],
  template: `
    <app-admin-shell>
      <div class="ax-container" style="max-width: 34rem;">
        <header class="ax-page-header">
          <div class="ax-page-header-content">
            <button type="button" (click)="cancel()" class="ax-btn ax-btn-ghost ax-btn-sm ax-mb-2" style="align-self:flex-start">
              <app-icon name="arrow_back" aria-hidden="true"></app-icon> {{ 'users.back_to_users' | translate }}
            </button>
            <span class="ax-page-header-eyebrow">{{ 'users.access_control' | translate }}</span>
            <h1 class="ax-page-title">{{ 'users.reset_password_title' | translate }}</h1>
            <p class="ax-page-subtitle" *ngIf="targetName">{{ targetName }}</p>
          </div>
        </header>

        <div *ngIf="ui.loading" class="ax-flex ax-justify-center ax-py-8">
          <span class="ax-spinner ax-spinner-lg" aria-label="Loading"></span>
        </div>

        <section *ngIf="!ui.loading" class="ax-page-section">
          <div class="ax-card ax-p-5">
            <div class="ax-form-field ax-mb-3">
              <label class="ax-label ax-text-2xs ax-label-required" for="new_password">{{ 'users.new_password' | translate }}</label>
              <input id="new_password" class="ax-input ax-input-sm" type="text" [(ngModel)]="pwValue" name="new_password" autocomplete="new-password" />
            </div>
            <div class="ax-flex ax-gap-2">
              <button type="button" class="ax-btn ax-btn-ghost" (click)="cancel()">{{ 'cancel' | translate }}</button>
              <button type="button" class="ax-btn ax-btn-primary" style="flex:1;" (click)="update_password()" [disabled]="ui.updating_password">
                <span *ngIf="ui.updating_password" class="ax-spinner ax-spinner-sm" aria-hidden="true"></span>
                <app-icon name="key" *ngIf="!ui.updating_password" aria-hidden="true"></app-icon>
                {{ 'users.update_password' | translate }}
              </button>
            </div>
          </div>
        </section>
      </div>
    </app-admin-shell>
  `,
})
export class StaffPasswordComponent implements OnInit {
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private adapter = inject(PortalCrudAdapter);
  private toast = inject(HotToastService);

  staffId = 0;
  targetName = '';
  pwValue = '';
  ui = { loading: false, updating_password: false };

  ngOnInit(): void {
    this.staffId = Number(this.route.snapshot.paramMap.get('id'));
    if (!this.staffId) { this.toast.error('Staff member not found.'); this.cancel(); return; }
    this.loadStaff();
  }

  private loadStaff(): void {
    this.ui.loading = true;
    this.adapter.get_v3('GET /admin/users/:id', { params: { id: String(this.staffId) } }).subscribe({
      next: (res: any) => {
        const u = res?.data ?? res ?? {};
        this.targetName = `${u.first_name ?? ''} ${u.last_name ?? ''}`.trim();
        this.ui.loading = false;
      },
      error: () => { this.toast.error('Unable to load the staff member.'); this.ui.loading = false; this.cancel(); },
    });
  }

  cancel(): void {
    this.router.navigate(['/adminusers']);
  }

  update_password(): void {
    if (!this.pwValue) { this.toast.error('New password is required.'); return; }
    this.ui.updating_password = true;
    this.adapter.patch_v3('PATCH /admin/users/:id/password', { password: this.pwValue }, { params: { id: String(this.staffId) } }).subscribe({
      next: (response: any) => {
        if (response) { this.toast.success('Password updated.'); this.router.navigate(['/adminusers']); }
        this.ui.updating_password = false;
      },
      error: () => { this.toast.error('Unable to complete your request at this time.'); this.ui.updating_password = false; },
    });
  }
}
