import { ChangeDetectionStrategy, ChangeDetectorRef, Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AxModalContainerComponent } from '../../../shared/overlays/ax-modal-container.component';
import { AxModalRef, AX_MODAL_DATA } from '../../../shared/overlays/ax-modal.service';
import { IconComponent } from '../../../shared/icon/icon.component';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { apiErrorMessage } from '../../../shared/http/api-error';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

export type VendorPasswordResetMode = 'generate' | 'manual';

export interface ResetVendorPasswordDialogData {
  vendorId: number;
  storeName: string;
  ownerName: string;
  ownerEmail: string;
}

/** Response of POST /v3/admin/vendors/:id/reset-password (envelope unwrapped). */
export interface ResetVendorPasswordResult {
  vendor_id: number;
  owner_user_id: number;
  mode: VendorPasswordResetMode;
  email: string;
  email_sent: boolean;
  must_change_password: boolean;
  sessions_revoked: number;
}

/** Mirrors the API policy (ResetVendorPasswordInput): 8–200 chars, not trimmed. */
export const VENDOR_PASSWORD_MIN = 8;
export const VENDOR_PASSWORD_MAX = 200;

/**
 * Admin "Reset vendor password" dialog, opened from Manage store.
 *
 * Two modes:
 *  - generate (default): the API mints a temporary password and emails it to
 *    the store owner. The admin never sees it.
 *  - manual: the admin types the password and hands it over themselves; the
 *    vendor only gets a security notice email.
 *
 * Either way the vendor is signed out everywhere and must choose a new
 * password on next sign-in, which the dialog spells out before the admin
 * commits. The dialog performs the request itself so validation / guard
 * errors render inline, and closes with the API result on success (or
 * `undefined` when dismissed).
 */
@Component({
  selector: 'app-reset-vendor-password-dialog',
  standalone: true,
  imports: [CommonModule, FormsModule, AxModalContainerComponent, IconComponent, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-ax-modal-container
      [title]="'vendor_reset_password.title' | translate"
      [subtitle]="data.storeName"
      (closed)="cancel()">
      <form id="vendor-reset-password-form" class="rvp" (ngSubmit)="submit()" novalidate>
        <p class="rvp-target">
          <app-icon name="mail" aria-hidden="true"></app-icon>
          <span>
            {{ 'vendor_reset_password.account' | translate }}
            <strong>{{ data.ownerName || data.ownerEmail }}</strong>
            <span *ngIf="data.ownerName && data.ownerEmail" class="rvp-muted">({{ data.ownerEmail }})</span>
          </span>
        </p>

        <fieldset class="rvp-modes" [disabled]="submitting">
          <legend class="ax-label ax-text-2xs">{{ 'vendor_reset_password.method' | translate }}</legend>

          <label class="rvp-mode" [class.rvp-mode-active]="mode === 'generate'">
            <span class="ax-radio">
              <input type="radio" name="mode" value="generate" [(ngModel)]="mode" (ngModelChange)="onModeChange()" />
              <span class="ax-radio-dot" aria-hidden="true"></span>
            </span>
            <span class="rvp-mode-text">
              <span class="rvp-mode-title">{{ 'vendor_reset_password.mode_generate' | translate }}</span>
              <span class="rvp-mode-desc">{{ 'vendor_reset_password.mode_generate_desc' | translate }}</span>
            </span>
          </label>

          <label class="rvp-mode" [class.rvp-mode-active]="mode === 'manual'">
            <span class="ax-radio">
              <input type="radio" name="mode" value="manual" [(ngModel)]="mode" (ngModelChange)="onModeChange()" />
              <span class="ax-radio-dot" aria-hidden="true"></span>
            </span>
            <span class="rvp-mode-text">
              <span class="rvp-mode-title">{{ 'vendor_reset_password.mode_manual' | translate }}</span>
              <span class="rvp-mode-desc">{{ 'vendor_reset_password.mode_manual_desc' | translate }}</span>
            </span>
          </label>
        </fieldset>

        <div *ngIf="mode === 'manual'" class="ax-form-field">
          <label class="ax-label ax-text-2xs ax-label-required" for="rvp-password">
            {{ 'vendor_reset_password.new_password' | translate }}
          </label>
          <div class="rvp-password">
            <input
              id="rvp-password"
              name="password"
              class="ax-input ax-input-sm"
              [class.ax-invalid]="passwordTouched && passwordError"
              [type]="showPassword ? 'text' : 'password'"
              autocomplete="new-password"
              spellcheck="false"
              [attr.maxlength]="maxLength"
              [attr.aria-invalid]="passwordTouched && !!passwordError"
              aria-describedby="rvp-password-hint"
              [disabled]="submitting"
              [(ngModel)]="password"
              (blur)="passwordTouched = true" />
            <button
              type="button"
              class="ax-btn ax-btn-ghost ax-btn-sm rvp-toggle"
              [attr.aria-pressed]="showPassword"
              [attr.aria-controls]="'rvp-password'"
              (click)="showPassword = !showPassword">
              <app-icon name="visibility" aria-hidden="true"></app-icon>
              {{ (showPassword ? 'vendor_reset_password.hide' : 'vendor_reset_password.show') | translate }}
            </button>
          </div>
          <p *ngIf="passwordTouched && passwordError; else hint" class="ax-error" id="rvp-password-hint" role="alert">
            {{ passwordError | translate: { min: minLength, max: maxLength } }}
          </p>
          <ng-template #hint>
            <p class="ax-hint" id="rvp-password-hint">
              {{ 'vendor_reset_password.password_hint' | translate: { min: minLength } }}
            </p>
          </ng-template>
        </div>

        <div class="ax-form-field">
          <label class="ax-label ax-text-2xs" for="rvp-reason">{{ 'vendor_reset_password.reason' | translate }}</label>
          <textarea
            id="rvp-reason"
            name="reason"
            class="ax-textarea ax-textarea-sm"
            rows="2"
            maxlength="1000"
            [placeholder]="'vendor_reset_password.reason_placeholder' | translate"
            [disabled]="submitting"
            [(ngModel)]="reason"></textarea>
        </div>

        <div class="rvp-notice" role="note">
          <app-icon name="info" aria-hidden="true"></app-icon>
          <ul>
            <li>{{ 'vendor_reset_password.effect_signout' | translate }}</li>
            <li>{{ 'vendor_reset_password.effect_must_change' | translate }}</li>
            <li *ngIf="mode === 'generate'">{{ 'vendor_reset_password.effect_email_generate' | translate }}</li>
            <li *ngIf="mode === 'manual'">{{ 'vendor_reset_password.effect_email_manual' | translate }}</li>
            <li>{{ 'vendor_reset_password.effect_logged' | translate }}</li>
          </ul>
        </div>

        <p *ngIf="serverError" class="ax-error rvp-server-error" role="alert">
          <app-icon name="error" aria-hidden="true"></app-icon> {{ serverError }}
        </p>
      </form>

      <ng-container footer>
        <button type="button" class="ax-btn ax-btn-ghost" (click)="cancel()" [disabled]="submitting">
          {{ 'cancel' | translate }}
        </button>
        <button
          type="submit"
          form="vendor-reset-password-form"
          class="ax-btn ax-btn-danger"
          [disabled]="submitting || !canSubmit">
          <span *ngIf="submitting" class="ax-spinner ax-spinner-sm" aria-hidden="true"></span>
          <app-icon *ngIf="!submitting" name="key" aria-hidden="true"></app-icon>
          {{ 'vendor_reset_password.submit' | translate }}
        </button>
      </ng-container>
    </app-ax-modal-container>
  `,
  styles: [`
    .rvp { display: flex; flex-direction: column; gap: var(--ax-space-4); }
    .rvp-target {
      display: flex; align-items: center; gap: var(--ax-space-2); margin: 0;
      font-size: var(--ax-fs-sm); color: var(--ax-color-text-secondary);
    }
    .rvp-target strong { color: var(--ax-color-text-primary); font-weight: var(--ax-fw-semibold); }
    .rvp-muted { color: var(--ax-color-text-tertiary); margin-inline-start: var(--ax-space-1); }
    .rvp-modes { border: 0; margin: 0; padding: 0; display: grid; gap: var(--ax-space-2); min-width: 0; }
    .rvp-modes legend { padding: 0; margin-bottom: var(--ax-space-2); }
    .rvp-mode {
      display: flex; align-items: flex-start; gap: var(--ax-space-3);
      padding: var(--ax-space-3) var(--ax-space-4);
      border: 1px solid var(--ax-color-border-default);
      border-radius: var(--ax-radius-md);
      background: var(--ax-color-bg-surface);
      cursor: pointer;
      transition: border-color var(--ax-duration-fast, 120ms) ease, background-color var(--ax-duration-fast, 120ms) ease;
    }
    .rvp-mode:hover { border-color: var(--ax-color-border-strong); }
    .rvp-mode-active { border-color: var(--ax-color-border-brand); background: var(--ax-color-bg-brand-subtle); }
    .rvp-mode:has(input:focus-visible) { outline: 2px solid var(--ax-color-border-focus); outline-offset: 2px; }
    .rvp-mode .ax-radio { padding-top: 2px; }
    .rvp-mode-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .rvp-mode-title { font-size: var(--ax-fs-sm); font-weight: var(--ax-fw-semibold); color: var(--ax-color-text-primary); }
    .rvp-mode-desc { font-size: var(--ax-fs-xs); color: var(--ax-color-text-secondary); line-height: 1.45; }
    .rvp-password { display: flex; gap: var(--ax-space-2); align-items: stretch; }
    .rvp-password .ax-input { flex: 1 1 auto; min-width: 0; }
    .rvp-toggle { flex-shrink: 0; }
    .rvp-notice {
      display: flex; gap: var(--ax-space-2); align-items: flex-start;
      padding: var(--ax-space-3) var(--ax-space-4);
      border-radius: var(--ax-radius-md);
      background: var(--ax-color-bg-warning-subtle);
      color: var(--ax-color-text-primary);
      font-size: var(--ax-fs-xs); line-height: 1.5;
    }
    .rvp-notice ul { margin: 0; padding-inline-start: var(--ax-space-4); display: grid; gap: 2px; }
    .rvp-server-error { margin: 0; }
  `],
})
export class ResetVendorPasswordDialogComponent {
  readonly data = inject(AX_MODAL_DATA) as ResetVendorPasswordDialogData;
  private readonly modalRef =
    inject<AxModalRef<ResetVendorPasswordDialogComponent, ResetVendorPasswordResult>>(AxModalRef);
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly i18n = inject(I18nService);
  private readonly cdr = inject(ChangeDetectorRef);

  readonly minLength = VENDOR_PASSWORD_MIN;
  readonly maxLength = VENDOR_PASSWORD_MAX;

  mode: VendorPasswordResetMode = 'generate';
  password = '';
  reason = '';
  showPassword = false;
  passwordTouched = false;
  submitting = false;
  serverError = '';

  /** i18n key of the current password problem, or '' when valid / not applicable. */
  get passwordError(): string {
    if (this.mode !== 'manual') return '';
    if (!this.password) return 'vendor_reset_password.password_required';
    if (this.password.length < VENDOR_PASSWORD_MIN) return 'vendor_reset_password.password_too_short';
    if (this.password.length > VENDOR_PASSWORD_MAX) return 'vendor_reset_password.password_too_long';
    return '';
  }

  get canSubmit(): boolean {
    return this.mode === 'generate' || !this.passwordError;
  }

  onModeChange(): void {
    this.serverError = '';
    this.passwordTouched = false;
    if (this.mode === 'generate') {
      // Never keep an admin-typed secret around once it's no longer used.
      this.password = '';
      this.showPassword = false;
    }
  }

  submit(): void {
    if (this.submitting) return;
    this.passwordTouched = true;
    if (!this.canSubmit) return;

    const body: Record<string, string> = { mode: this.mode };
    if (this.mode === 'manual') body['password'] = this.password; // not trimmed, matches API policy
    const reason = this.reason.trim();
    if (reason) body['reason'] = reason;

    this.submitting = true;
    this.serverError = '';
    this.adapter
      .post_v3('POST /admin/vendors/:id/reset-password', body, { params: { id: String(this.data.vendorId) } })
      .subscribe({
        next: (res: any) => {
          this.submitting = false;
          const result = (res?.data ?? res) as ResetVendorPasswordResult | null;
          if (!result) {
            this.serverError = this.i18n.t('vendor_reset_password.error');
            this.cdr.markForCheck();
            return;
          }
          this.password = '';
          this.modalRef.close(result);
        },
        error: (err: unknown) => {
          this.submitting = false;
          this.serverError = apiErrorMessage(err, this.i18n.t('vendor_reset_password.error'));
          this.cdr.markForCheck();
        },
      });
  }

  cancel(): void {
    if (this.submitting) return;
    this.password = '';
    this.modalRef.close(undefined);
  }
}
