import {
  Component,
  ChangeDetectionStrategy,
  inject,
} from '@angular/core';
import { NgIf } from '@angular/common';
import { Router, RouterLink } from '@angular/router';
import { ReactiveFormsModule, FormBuilder, FormControl, FormGroup, Validators } from '@angular/forms';
import { TranslatePipe } from '@ngx-translate/core';
import { FormFieldComponent, ToastService, mapApiErrors, ApiErrorMapping } from '../../shared/forms';
import { ProfileService } from './profile.service';

/**
 * /account/password — change the account password.
 *
 * Requires the current password (server re-auths via current_password
 * → 401 AUTH_INVALID_CREDENTIALS on mismatch). new_password min 8
 * (API), confirm_password is a client-side match check only.
 *
 * On success the endpoint revokes all sessions + returns a fresh token
 * pair, which ProfileService.changePassword adopts so this session
 * survives. We then toast + route back to /account.
 */
const PASSWORD_ERROR_MAP: Record<string, ApiErrorMapping> = {
  AUTH_INVALID_CREDENTIALS: { field: 'current_password', key: 'wrongCurrent' },
};

@Component({
  selector: 'app-account-password',
  standalone: true,
  imports: [NgIf, RouterLink, ReactiveFormsModule, TranslatePipe, FormFieldComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="account-password" data-testid="account-password-page">
      <div class="account-password__container">
        <nav class="account-password__breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ 'account.password.title' | translate }}</span>
        </nav>

        <h1 class="account-password__title">{{ 'account.password.title' | translate }}</h1>
        <p class="account-password__intro">{{ 'account.password.intro' | translate }}</p>

        <form
          class="account-password__form"
          [formGroup]="form"
          (ngSubmit)="onSubmit()"
          novalidate
        >
          <ui-form-field
            [label]="'account.password.current'"
            fieldId="pw-current"
            [required]="true"
            [control]="form.controls.current_password"
            [errorMap]="errorKeys"
          >
            <input
              id="pw-current"
              type="password"
              autocomplete="current-password"
              formControlName="current_password"
              class="auth-input"
              data-testid="pw-current"
            />
          </ui-form-field>

          <ui-form-field
            [label]="'account.password.new'"
            fieldId="pw-new"
            [required]="true"
            [helper]="'account.password.newHint'"
            [control]="form.controls.new_password"
            [errorMap]="errorKeys"
          >
            <input
              id="pw-new"
              type="password"
              autocomplete="new-password"
              formControlName="new_password"
              class="auth-input"
              data-testid="pw-new"
            />
          </ui-form-field>

          <ui-form-field
            [label]="'account.password.confirm'"
            fieldId="pw-confirm"
            [required]="true"
            [control]="form.controls.confirm_password"
            [errorMap]="errorKeys"
          >
            <input
              id="pw-confirm"
              type="password"
              autocomplete="new-password"
              formControlName="confirm_password"
              class="auth-input"
              data-testid="pw-confirm"
            />
          </ui-form-field>

          <div class="account-password__actions">
            <a routerLink="/account" class="account-password__cancel">
              {{ 'common.cancel' | translate }}
            </a>
            <button
              type="submit"
              class="account-password__save"
              [disabled]="isSaving()"
              data-testid="pw-save"
            >
              {{ (isSaving() ? 'common.saving' : 'account.password.save') | translate }}
            </button>
          </div>
        </form>
      </div>
    </main>
  `,
  styleUrl: './account-password.scss',
})
export class AccountPasswordPageComponent {
  private readonly profileService = inject(ProfileService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);

  protected readonly isSaving = this.profileService.isSaving;

  protected readonly form: FormGroup<{
    current_password: FormControl<string>;
    new_password: FormControl<string>;
    confirm_password: FormControl<string>;
  }>;

  /** i18n error keys surfaced via FormFieldComponent. */
  protected readonly errorKeys: Record<string, string> = {
    required: 'account.password.errors.required',
    minlength: 'account.password.errors.tooShort',
    mismatch: 'account.password.errors.mismatch',
    wrongCurrent: 'account.password.errors.wrongCurrent',
    apiValidation: 'account.password.errors.invalid',
  };

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      current_password: fb.control('', [Validators.required]),
      new_password: fb.control('', [Validators.required, Validators.minLength(8)]),
      confirm_password: fb.control('', [Validators.required]),
    });
  }

  protected async onSubmit(): Promise<void> {
    if (this.isSaving()) return;

    /* Client-side confirm-match check before hitting the API. */
    const { new_password, confirm_password } = this.form.getRawValue();
    if (new_password !== confirm_password) {
      this.form.controls.confirm_password.setErrors({ mismatch: true });
      this.form.controls.confirm_password.markAsTouched();
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const { current_password } = this.form.getRawValue();
    try {
      await this.profileService.changePassword(current_password, new_password);
      this.toast.success('account.password.changed');
      await this.router.navigateByUrl('/account');
    } catch (err) {
      const result = mapApiErrors(err, this.form, PASSWORD_ERROR_MAP);
      if (result.isNetworkError) {
        this.toast.error('common.errors.network');
      } else if (result.unmapped.length > 0) {
        this.toast.error('account.password.errors.generic');
      }
    }
  }
}
