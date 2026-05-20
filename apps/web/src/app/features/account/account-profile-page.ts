import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ReactiveFormsModule, FormBuilder, FormControl, FormGroup, Validators } from '@angular/forms';
import { TranslatePipe } from '@ngx-translate/core';
import { FormFieldComponent, ToastService, mapApiErrors } from '../../shared/forms';
import { ProfileService, ProfileUpdate, ProfileGender } from './profile.service';
import type { AuthUser } from '../../core/auth/auth.types';

const GENDERS: ProfileGender[] = ['male', 'female', 'other', 'prefer_not_to_say'];
const LOCALES = ['en', 'ar', 'en-AE', 'ar-AE'];

/**
 * /account/profile — edit the authenticated user's profile.
 *
 * Editable (mirrors apps/api UpdateProfileInput exactly):
 *   first_name, last_name, gender, dob, locale.
 * Read-only display: email + phone (with verified badges) — changed
 * via dedicated verified-contact flows, not this endpoint.
 *
 * Submit sends ONLY changed fields (diff against the loaded baseline),
 * leaning on the API's JSON Merge Patch semantics. Empty text inputs
 * are sent as null (clears the field) when they differ from baseline.
 *
 * Errors route through mapApiErrors: per-field VALIDATION_FAILED lands
 * on the control; anything unmapped or a network failure becomes a
 * toast.
 */
@Component({
  selector: 'app-account-profile',
  standalone: true,
  imports: [NgIf, NgFor, RouterLink, ReactiveFormsModule, TranslatePipe, FormFieldComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="account-profile" data-testid="account-profile-page">
      <div class="account-profile__container">
        <nav class="account-profile__breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ 'account.profile.title' | translate }}</span>
        </nav>

        <h1 class="account-profile__title">{{ 'account.profile.title' | translate }}</h1>

        <ng-container *ngIf="!isLoading(); else loadingState">
          <form
            class="account-profile__form"
            [formGroup]="form"
            (ngSubmit)="onSubmit()"
            novalidate
          >
            <div class="account-profile__row-2col">
              <ui-form-field
                [label]="'account.profile.firstName'"
                fieldId="prof-first"
                [control]="form.controls.first_name"
                [errorMap]="fieldErrors"
              >
                <input
                  id="prof-first"
                  type="text"
                  autocomplete="given-name"
                  formControlName="first_name"
                  class="auth-input"
                  data-testid="prof-first"
                />
              </ui-form-field>

              <ui-form-field
                [label]="'account.profile.lastName'"
                fieldId="prof-last"
                [control]="form.controls.last_name"
                [errorMap]="fieldErrors"
              >
                <input
                  id="prof-last"
                  type="text"
                  autocomplete="family-name"
                  formControlName="last_name"
                  class="auth-input"
                  data-testid="prof-last"
                />
              </ui-form-field>
            </div>

            <div class="account-profile__row-2col">
              <ui-form-field
                [label]="'account.profile.gender'"
                fieldId="prof-gender"
                [control]="form.controls.gender"
                [errorMap]="fieldErrors"
              >
                <select
                  id="prof-gender"
                  formControlName="gender"
                  class="auth-input"
                  data-testid="prof-gender"
                >
                  <option value="">{{ 'account.profile.genderUnset' | translate }}</option>
                  <option *ngFor="let g of genders" [value]="g">
                    {{ 'account.profile.genderOptions.' + g | translate }}
                  </option>
                </select>
              </ui-form-field>

              <ui-form-field
                [label]="'account.profile.dob'"
                fieldId="prof-dob"
                [control]="form.controls.dob"
                [errorMap]="fieldErrors"
              >
                <input
                  id="prof-dob"
                  type="date"
                  formControlName="dob"
                  class="auth-input"
                  data-testid="prof-dob"
                />
              </ui-form-field>
            </div>

            <ui-form-field
              [label]="'account.profile.locale'"
              fieldId="prof-locale"
              [control]="form.controls.locale"
              [errorMap]="fieldErrors"
            >
              <select
                id="prof-locale"
                formControlName="locale"
                class="auth-input"
                data-testid="prof-locale"
              >
                <option *ngFor="let l of locales" [value]="l">
                  {{ 'account.profile.localeOptions.' + l | translate }}
                </option>
              </select>
            </ui-form-field>

            <!-- Read-only contact info -->
            <div class="account-profile__readonly" data-testid="prof-readonly">
              <div class="account-profile__readonly-row">
                <span class="account-profile__readonly-label">{{ 'account.profile.email' | translate }}</span>
                <span class="account-profile__readonly-value">
                  {{ email() }}
                  <span
                    *ngIf="emailVerified()"
                    class="account-profile__verified"
                    [attr.title]="'account.profile.verified' | translate"
                    data-testid="prof-email-verified"
                  >✓</span>
                </span>
              </div>
              <div class="account-profile__readonly-row">
                <span class="account-profile__readonly-label">{{ 'account.profile.phone' | translate }}</span>
                <span class="account-profile__readonly-value">
                  {{ phone() }}
                  <span
                    *ngIf="phoneVerified()"
                    class="account-profile__verified"
                    [attr.title]="'account.profile.verified' | translate"
                    data-testid="prof-phone-verified"
                  >✓</span>
                </span>
              </div>
            </div>

            <div class="account-profile__actions">
              <button
                type="submit"
                class="account-profile__save"
                [disabled]="isSaving()"
                data-testid="prof-save"
              >
                {{ (isSaving() ? 'common.saving' : 'account.profile.save') | translate }}
              </button>
            </div>
          </form>
        </ng-container>

        <ng-template #loadingState>
          <div class="account-profile__loading" data-testid="prof-loading">
            {{ 'common.loading' | translate }}
          </div>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './account-profile.scss',
})
export class AccountProfilePageComponent implements OnInit {
  private readonly profileService = inject(ProfileService);
  private readonly toast = inject(ToastService);

  protected readonly genders = GENDERS;
  protected readonly locales = LOCALES;

  protected readonly isLoading = this.profileService.isLoading;
  protected readonly isSaving = this.profileService.isSaving;

  private readonly _user = signal<AuthUser | null>(null);
  protected email = (): string => this._user()?.email ?? '';
  protected phone = (): string => this._user()?.phone ?? '';
  protected emailVerified = (): boolean => this._user()?.is_email_verified ?? false;
  protected phoneVerified = (): boolean => this._user()?.is_phone_verified ?? false;

  protected readonly form: FormGroup<{
    first_name: FormControl<string>;
    last_name: FormControl<string>;
    gender: FormControl<string>;
    dob: FormControl<string>;
    locale: FormControl<string>;
  }>;

  /** Per-field i18n error keys surfaced by FormFieldComponent. */
  protected readonly fieldErrors: Record<string, string> = {
    apiValidation: 'account.profile.errors.invalid',
    maxlength: 'account.profile.errors.tooLong',
  };

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      first_name: fb.control('', [Validators.maxLength(100)]),
      last_name: fb.control('', [Validators.maxLength(100)]),
      gender: fb.control(''),
      dob: fb.control(''),
      locale: fb.control('en'),
    });
  }

  async ngOnInit(): Promise<void> {
    try {
      const user = await this.profileService.getProfile();
      this._user.set(user);
      this.form.patchValue({
        first_name: user.first_name ?? '',
        last_name: user.last_name ?? '',
        gender: user.gender ?? '',
        dob: user.dob ?? '',
        locale: user.locale ?? 'en',
      });
      this.form.markAsPristine();
    } catch {
      this.toast.error('account.profile.errors.loadFailed');
    }
  }

  protected async onSubmit(): Promise<void> {
    if (this.isSaving()) return;
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const patch = this.buildPatch();
    if (Object.keys(patch).length === 0) {
      /* Nothing changed — no API call, gentle confirmation. */
      this.toast.info('account.profile.noChanges');
      return;
    }

    try {
      const updated = await this.profileService.updateProfile(patch);
      this._user.set(updated);
      this.form.markAsPristine();
      this.toast.success('account.profile.saved');
    } catch (err) {
      const result = mapApiErrors(err, this.form, {});
      if (result.isNetworkError) {
        this.toast.error('common.errors.network');
      } else if (result.unmapped.length > 0) {
        this.toast.error('account.profile.errors.saveFailed');
      }
    }
  }

  /**
   * Diff the form against the loaded baseline; include only changed
   * fields. Empty text → null (clear). Mirrors the API's Merge Patch
   * contract: omitted keys are left untouched.
   */
  private buildPatch(): ProfileUpdate {
    const user = this._user();
    const v = this.form.getRawValue();
    const patch: ProfileUpdate = {};

    const normFirst = v.first_name.trim() === '' ? null : v.first_name.trim();
    if (normFirst !== (user?.first_name ?? null)) patch.first_name = normFirst;

    const normLast = v.last_name.trim() === '' ? null : v.last_name.trim();
    if (normLast !== (user?.last_name ?? null)) patch.last_name = normLast;

    const normGender = v.gender === '' ? null : (v.gender as ProfileGender);
    if (normGender !== (user?.gender ?? null)) patch.gender = normGender;

    const normDob = v.dob === '' ? null : v.dob;
    if (normDob !== (user?.dob ?? null)) patch.dob = normDob;

    if (v.locale !== (user?.locale ?? null)) patch.locale = v.locale;

    return patch;
  }
}
