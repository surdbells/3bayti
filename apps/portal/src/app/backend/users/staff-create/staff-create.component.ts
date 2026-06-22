import { Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TranslatePipe } from '../../../translate.pipe';

/**
 * /adminusers/new — create a staff member.
 *
 * Extracted from the users page's in-place "Add staff" drawer into a routed
 * sub-page (adminGuard). Reuses the existing POST /admin/users endpoint; on
 * success it returns to /adminusers (which re-fetches the staff list).
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
export class StaffCreateComponent {
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

  ui = { registering: false };

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
        if (response) {
          this.toast.success('Staff member created. Assign roles to grant access.');
          this.router.navigate(['/adminusers']);
        }
        this.ui.registering = false;
      },
      error: () => { this.toast.error('Unable to complete your request at this time.'); this.ui.registering = false; },
    });
  }
}
