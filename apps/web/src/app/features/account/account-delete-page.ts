import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
} from '@angular/core';
import { NgIf } from '@angular/common';
import { Router, RouterLink } from '@angular/router';
import { ReactiveFormsModule, FormBuilder, FormControl, FormGroup, Validators } from '@angular/forms';
import { TranslatePipe } from '@ngx-translate/core';
import { FormFieldComponent, ToastService, mapApiErrors, ApiErrorMapping } from '../../shared/forms';
import { ConfirmModalComponent } from '../../shared/ui';
import { ProfileService } from './profile.service';
import { AuthService } from '../../core/auth/auth.service';

/**
 * /account/delete, delete the account (the account "danger zone").
 *
 * Two-gate flow:
 *   1. The user types their current password (re-auth, Q6.2).
 *   2. Submitting opens a danger ConfirmModal; only on confirm do we
 *      call DELETE /v3/me.
 * On success: local logout (clears the now-revoked session) + redirect
 * home with a farewell toast.
 *
 * A wrong password is AUTH_INVALID_CREDENTIALS → surfaced on the
 * password field (the confirm modal closes so the user can correct it).
 */
const DELETE_ERROR_MAP: Record<string, ApiErrorMapping> = {
  AUTH_INVALID_CREDENTIALS: { field: 'current_password', key: 'wrongCurrent' },
};

@Component({
  selector: 'app-account-delete',
  standalone: true,
  imports: [
    NgIf,
    RouterLink,
    ReactiveFormsModule,
    TranslatePipe,
    FormFieldComponent,
    ConfirmModalComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="account-delete" data-testid="account-delete-page">
      <div class="account-delete__container">
        <nav class="account-delete__breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ 'account.delete.title' | translate }}</span>
        </nav>

        <h1 class="account-delete__title">{{ 'account.delete.title' | translate }}</h1>

        <div class="account-delete__warning" role="note" data-testid="delete-warning">
          <p class="account-delete__warning-lead">{{ 'account.delete.warningLead' | translate }}</p>
          <p class="account-delete__warning-body">{{ 'account.delete.warningBody' | translate }}</p>
        </div>

        <form
          class="account-delete__form"
          [formGroup]="form"
          (ngSubmit)="onSubmit()"
          novalidate
        >
          <ui-form-field
            [label]="'account.delete.confirmPassword'"
            fieldId="del-password"
            [required]="true"
            [helper]="'account.delete.confirmPasswordHint'"
            [control]="form.controls.current_password"
            [errorMap]="errorKeys"
          >
            <input
              id="del-password"
              type="password"
              autocomplete="current-password"
              formControlName="current_password"
              class="auth-input"
              data-testid="del-password"
            />
          </ui-form-field>

          <div class="account-delete__actions">
            <a routerLink="/account" class="account-delete__cancel">
              {{ 'common.cancel' | translate }}
            </a>
            <button
              type="submit"
              class="account-delete__submit"
              [disabled]="isSaving()"
              data-testid="del-submit"
            >
              {{ 'account.delete.submit' | translate }}
            </button>
          </div>
        </form>
      </div>

      <ui-confirm-modal
        [open]="showConfirm()"
        [title]="'account.delete.confirmTitle'"
        [message]="'account.delete.confirmMessage'"
        [confirmLabel]="'account.delete.confirmYes'"
        [cancelLabel]="'account.delete.confirmNo'"
        [danger]="true"
        [busy]="isSaving()"
        (confirm)="onConfirmed()"
        (cancel)="onDismissed()"
      ></ui-confirm-modal>
    </main>
  `,
  styleUrl: './account-delete.scss',
})
export class AccountDeletePageComponent {
  private readonly profileService = inject(ProfileService);
  private readonly auth = inject(AuthService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);

  protected readonly isSaving = this.profileService.isSaving;

  private readonly _showConfirm = signal<boolean>(false);
  protected readonly showConfirm = this._showConfirm.asReadonly();

  protected readonly form: FormGroup<{
    current_password: FormControl<string>;
  }>;

  protected readonly errorKeys: Record<string, string> = {
    required: 'account.delete.errors.required',
    wrongCurrent: 'account.delete.errors.wrongCurrent',
    apiValidation: 'account.delete.errors.invalid',
  };

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      current_password: fb.control('', [Validators.required]),
    });
  }

  /** Submit → validate the password field, then open the danger modal. */
  protected onSubmit(): void {
    if (this.isSaving()) return;
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this._showConfirm.set(true);
  }

  protected onDismissed(): void {
    this._showConfirm.set(false);
  }

  /** Confirmed in the modal, perform the deletion. */
  protected async onConfirmed(): Promise<void> {
    const { current_password } = this.form.getRawValue();
    try {
      await this.profileService.deleteAccount(current_password);
      /* Server already revoked the session; clear local state too,
         then leave the account area. logout() is best-effort. */
      this._showConfirm.set(false);
      await this.auth.logout();
      this.toast.success('account.delete.done');
      await this.router.navigateByUrl('/');
    } catch (err) {
      /* Close the modal so the user can see + fix the field error. */
      this._showConfirm.set(false);
      const result = mapApiErrors(err, this.form, DELETE_ERROR_MAP);
      if (result.isNetworkError) {
        this.toast.error('common.errors.network');
      } else if (result.unmapped.length > 0) {
        this.toast.error('account.delete.errors.generic');
      }
    }
  }
}
