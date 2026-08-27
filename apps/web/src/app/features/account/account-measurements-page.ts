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
import { ConfirmModalComponent } from '../../shared/ui';
import {
  MeasurementService,
  MEASUREMENT_FIELDS,
  MeasurementField,
  MeasurementUpsert,
} from './measurement.service';

/**
 * /account/measurements, the customer's default body measurements,
 * used to power tailored fit across the catalogue.
 *
 * Six standard tailoring fields (bust/chest, waist, hips, shoulder,
 * sleeve length, height), all in centimetres, plus a free-text notes
 * field. The API stores them as a free-form map; the web side owns the
 * canonical field list (MEASUREMENT_FIELDS).
 *
 * Behaviour
 * ---------
 *   - Loads the default set on init; empty form when none saved.
 *   - Save: sends only the fields with a value (omits blanks) +
 *     trimmed notes via PUT (upsert).
 *   - Clear: deletes the whole set (DELETE) behind a ConfirmModal,
 *     then resets the form.
 *
 * Validation: each field optional, numeric, 0–500 cm (matches the
 * API's per-value Range). Notes max 1000 (matches the API).
 */
@Component({
  selector: 'app-account-measurements',
  standalone: true,
  imports: [
    NgIf,
    NgFor,
    RouterLink,
    ReactiveFormsModule,
    TranslatePipe,
    FormFieldComponent,
    ConfirmModalComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="account-measurements" data-testid="account-measurements-page">
      <div class="account-measurements__container">
        <nav class="account-measurements__breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ 'account.measurements.title' | translate }}</span>
        </nav>

        <h1 class="account-measurements__title">{{ 'account.measurements.title' | translate }}</h1>
        <p class="account-measurements__intro">{{ 'account.measurements.intro' | translate }}</p>

        <ng-container *ngIf="!isLoading(); else loadingState">
          <form
            class="account-measurements__form"
            [formGroup]="form"
            (ngSubmit)="onSubmit()"
            novalidate
          >
            <div class="account-measurements__grid">
              <ui-form-field
                *ngFor="let field of fields"
                [label]="'account.measurements.fields.' + field"
                [fieldId]="'meas-' + field"
                [helper]="'account.measurements.cmHint'"
                [control]="form.controls[field]"
                [errorMap]="fieldErrors"
              >
                <input
                  [id]="'meas-' + field"
                  type="number"
                  inputmode="decimal"
                  min="0"
                  max="500"
                  step="0.1"
                  [formControlName]="field"
                  class="auth-input"
                  [attr.data-testid]="'meas-' + field"
                />
              </ui-form-field>
            </div>

            <ui-form-field
              [label]="'account.measurements.notes'"
              fieldId="meas-notes"
              [control]="form.controls.notes"
              [errorMap]="fieldErrors"
            >
              <textarea
                id="meas-notes"
                rows="3"
                formControlName="notes"
                class="auth-input account-measurements__notes"
                [placeholder]="'account.measurements.notesPlaceholder' | translate"
                data-testid="meas-notes"
              ></textarea>
            </ui-form-field>

            <div class="account-measurements__actions">
              <button
                *ngIf="hasSaved()"
                type="button"
                class="account-measurements__clear"
                (click)="onClearClick()"
                data-testid="meas-clear"
              >
                {{ 'account.measurements.clear' | translate }}
              </button>
              <button
                type="submit"
                class="account-measurements__save"
                [disabled]="isSaving()"
                data-testid="meas-save"
              >
                {{ (isSaving() ? 'common.saving' : 'account.measurements.save') | translate }}
              </button>
            </div>
          </form>
        </ng-container>

        <ng-template #loadingState>
          <div class="account-measurements__loading" data-testid="meas-loading">
            {{ 'common.loading' | translate }}
          </div>
        </ng-template>
      </div>

      <ui-confirm-modal
        [open]="showClearConfirm()"
        [title]="'account.measurements.clearConfirmTitle'"
        [message]="'account.measurements.clearConfirmMessage'"
        [confirmLabel]="'account.measurements.clearConfirmYes'"
        [cancelLabel]="'common.cancel'"
        [danger]="true"
        [busy]="isSaving()"
        (confirm)="onClearConfirmed()"
        (cancel)="onClearDismissed()"
      ></ui-confirm-modal>
    </main>
  `,
  styleUrl: './account-measurements.scss',
})
export class AccountMeasurementsPageComponent implements OnInit {
  private readonly measurementService = inject(MeasurementService);
  private readonly toast = inject(ToastService);

  protected readonly fields = MEASUREMENT_FIELDS;
  protected readonly isLoading = this.measurementService.isLoading;
  protected readonly isSaving = this.measurementService.isSaving;

  /** True once a set exists server-side (enables the Clear action). */
  private readonly _hasSaved = signal<boolean>(false);
  protected readonly hasSaved = this._hasSaved.asReadonly();

  private readonly _showClearConfirm = signal<boolean>(false);
  protected readonly showClearConfirm = this._showClearConfirm.asReadonly();

  protected readonly form: FormGroup<
    Record<MeasurementField, FormControl<string>> & { notes: FormControl<string> }
  >;

  protected readonly fieldErrors: Record<string, string> = {
    min: 'account.measurements.errors.range',
    max: 'account.measurements.errors.range',
    apiValidation: 'account.measurements.errors.invalid',
    maxlength: 'account.measurements.errors.notesTooLong',
  };

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    const controls = {} as Record<MeasurementField, FormControl<string>> & {
      notes: FormControl<string>;
    };
    for (const field of MEASUREMENT_FIELDS) {
      controls[field] = fb.control('', [Validators.min(0), Validators.max(500)]);
    }
    controls.notes = fb.control('', [Validators.maxLength(1000)]);
    this.form = fb.group(controls);
  }

  async ngOnInit(): Promise<void> {
    try {
      const measurement = await this.measurementService.getDefault();
      if (measurement !== null) {
        this._hasSaved.set(true);
        const patch: Record<string, string> = {};
        for (const field of MEASUREMENT_FIELDS) {
          const v = measurement.values[field];
          patch[field] = v !== undefined && v !== null ? String(v) : '';
        }
        patch['notes'] = measurement.notes ?? '';
        this.form.patchValue(patch);
      }
      this.form.markAsPristine();
    } catch {
      this.toast.error('account.measurements.errors.loadFailed');
    }
  }

  protected async onSubmit(): Promise<void> {
    if (this.isSaving()) return;
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const input = this.buildUpsert();
    try {
      const saved = await this.measurementService.upsertDefault(input);
      this._hasSaved.set(true);
      /* Re-seed the form from the persisted values so it reflects
         server-side normalisation, then mark pristine. */
      const patch: Record<string, string> = {};
      for (const field of MEASUREMENT_FIELDS) {
        const v = saved.values[field];
        patch[field] = v !== undefined && v !== null ? String(v) : '';
      }
      patch['notes'] = saved.notes ?? '';
      this.form.patchValue(patch);
      this.form.markAsPristine();
      this.toast.success('account.measurements.saved');
    } catch (err) {
      const result = mapApiErrors(err, this.form, {});
      if (result.isNetworkError) {
        this.toast.error('common.errors.network');
      } else if (result.unmapped.length > 0) {
        this.toast.error('account.measurements.errors.saveFailed');
      }
    }
  }

  protected onClearClick(): void {
    this._showClearConfirm.set(true);
  }

  protected onClearDismissed(): void {
    this._showClearConfirm.set(false);
  }

  protected async onClearConfirmed(): Promise<void> {
    try {
      await this.measurementService.clearDefault();
      this._hasSaved.set(false);
      this.form.reset();
      this.toast.success('account.measurements.cleared');
    } catch (err) {
      const result = mapApiErrors(err, this.form, {});
      if (result.isNetworkError) {
        this.toast.error('common.errors.network');
      } else {
        this.toast.error('account.measurements.errors.clearFailed');
      }
    } finally {
      this._showClearConfirm.set(false);
    }
  }

  /**
   * Build the upsert body: only fields with a parseable value are
   * sent; blanks are omitted (the API treats the values map as the
   * full set). Notes trimmed; empty → null.
   */
  private buildUpsert(): MeasurementUpsert {
    const raw = this.form.getRawValue();
    const values: Record<string, number> = {};
    for (const field of MEASUREMENT_FIELDS) {
      /* The number-input value accessor may hand back a number, an
         empty string, or null. Normalise to a trimmed string first. */
      const rawVal = raw[field] as unknown;
      const str = rawVal === null || rawVal === undefined ? '' : String(rawVal).trim();
      if (str === '') continue;
      const num = Number(str);
      if (!Number.isNaN(num)) {
        values[field] = num;
      }
    }
    const notesRaw = raw.notes as unknown;
    const notes = (notesRaw === null || notesRaw === undefined ? '' : String(notesRaw)).trim();
    return { values, notes: notes === '' ? null : notes };
  }
}
