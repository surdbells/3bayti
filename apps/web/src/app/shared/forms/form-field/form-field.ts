import {
  Component,
  ChangeDetectionStrategy,
  Input,
  inject,
  ChangeDetectorRef,
  OnInit,
  OnDestroy,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { AbstractControl, ValidationErrors } from '@angular/forms';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { Subscription } from 'rxjs';

/**
 * Form field — labelled control wrapper.
 *
 * Wraps a single FormControl (or any AbstractControl) with:
 *   - The visible label
 *   - Optional helper text below the input
 *   - Inline error rendering — appears only after the control is
 *     touched/dirty AND has errors
 *   - Full a11y wiring: aria-describedby pointing at the helper +
 *     error ids; aria-invalid driven by the control's state
 *
 * Why a single component rather than separate <label> / <error> primitives
 * -----------------------------------------------------------------------
 * The a11y wiring (matching for+id, aria-describedby IDs that change
 * with error state, aria-invalid bookkeeping) is fiddly enough that
 * doing it correctly per-page is error-prone. Encapsulating once means
 * every Y.1 page gets the same treatment for free.
 *
 * Why ng-content rather than passing a control reference
 * ------------------------------------------------------
 * Consumers project their own <input> / <select> via ng-content so the
 * input element type stays flexible (we don't dictate text vs email
 * vs select). The wrapper just needs the control's metadata (which
 * the consumer passes via [control]) for label-for / error visibility.
 *
 * Error map shape
 * ---------------
 * Reactive Forms expose errors as { [key: string]: unknown }. We map
 * each key to an i18n string via `errorMap` — a Record<string, string>
 * supplied by the consumer, where the value is an i18n key. Unmapped
 * errors fall back to errorMap['_default'] if present, otherwise the
 * generic auth.fields.required key.
 *
 * Usage
 * -----
 *   <ui-form-field
 *     label="auth.login.email_label"
 *     fieldId="login-email"
 *     [control]="form.controls.email"
 *     [errorMap]="{
 *       required: 'auth.fields.required',
 *       email:    'auth.fields.email_invalid',
 *       email_taken: 'auth.register.errors.email_taken'
 *     }">
 *     <input id="login-email" type="email" formControlName="email" />
 *   </ui-form-field>
 */
@Component({
  selector: 'ui-form-field',
  standalone: true,
  imports: [NgIf, NgFor, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="form-field" [class.form-field--invalid]="shouldShowErrors()">
      <label *ngIf="label" [attr.for]="fieldId" class="form-field__label">
        {{ label | translate }}
        <span *ngIf="required" class="form-field__required" aria-hidden="true">*</span>
      </label>

      <div class="form-field__control" [attr.aria-describedby]="describedBy">
        <ng-content></ng-content>
      </div>

      <p
        *ngIf="helper && !shouldShowErrors()"
        [id]="helperId"
        class="form-field__helper"
      >
        {{ helper | translate }}
      </p>

      <p
        *ngIf="shouldShowErrors()"
        [id]="errorId"
        class="form-field__error"
        role="alert"
        aria-live="polite"
        [attr.data-error-key]="resolveErrorKey()"
      >
        {{ resolveErrorKey() | translate : errorParams() }}
      </p>
    </div>
  `,
  styles: [
    `
      .form-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-bottom: 16px;
      }

      .form-field__label {
        font-size: 13px;
        font-weight: 500;
        color: var(--color-text-primary, #2e241c);
        letter-spacing: 0.02em;
      }

      .form-field__required {
        color: var(--color-danger-600, #b42218);
        margin-inline-start: 2px;
      }

      .form-field__control {
        display: block;
      }

      .form-field__helper {
        font-size: 12px;
        color: var(--color-text-muted, #6b6056);
        margin: 0;
      }

      .form-field__error {
        font-size: 12px;
        color: var(--color-danger-600, #b42218);
        margin: 0;
        font-weight: 500;
      }

      /* When the field is invalid, give projected inputs a danger ring.
         The :has() selector lets us style children without :host
         workarounds — supported in all evergreen browsers from 2023+.
         For older browsers we fall back to the class-based selector. */
      .form-field--invalid ::ng-deep input,
      .form-field--invalid ::ng-deep select,
      .form-field--invalid ::ng-deep textarea {
        border-color: var(--color-danger-600, #b42218);
        box-shadow: 0 0 0 1px var(--color-danger-600, #b42218);
      }
    `,
  ],
})
export class FormFieldComponent implements OnInit, OnDestroy {
  /** i18n key for the label (translated on render). */
  @Input() label = '';
  /** DOM id of the projected input element. Used for label-for + aria. */
  @Input() fieldId = '';
  /** The form control to observe for invalid / touched state. */
  @Input() control: AbstractControl | null = null;
  /** Marks the label with an asterisk (visual only — `required` validator
   *  drives the actual validation). */
  @Input() required = false;
  /** Optional helper text shown below the input when there's no error. */
  @Input() helper: string | null = null;
  /** Map from Angular validator key → i18n message key. */
  @Input() errorMap: Record<string, string> = {};

  /** Cached identifiers for aria-describedby — stable for the component's
   *  lifetime so the browser's accessibility tree doesn't churn. */
  protected helperId = '';
  protected errorId = '';
  protected describedBy: string | null = null;

  private statusSub: Subscription | null = null;
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly translate = inject(TranslateService);

  ngOnInit(): void {
    /* Build stable IDs from the fieldId. Fall back to a random suffix
       if the consumer forgot to pass fieldId — should be rare since
       label-for and aria-describedby both reference it. */
    const base = this.fieldId !== '' ? this.fieldId : `ff-${Math.random().toString(36).slice(2, 8)}`;
    this.helperId = `${base}-helper`;
    this.errorId = `${base}-error`;
    this.recomputeDescribedBy();

    /* Re-render the error block whenever control state changes.
       The `events` observable (Angular 17+) emits on value, status,
       AND touched/pristine transitions — covering all the cases that
       affect shouldShowErrors(). statusChanges alone misses the
       touched transition. */
    if (this.control !== null) {
      this.statusSub = this.control.events.subscribe(() => {
        this.recomputeDescribedBy();
        this.cdr.markForCheck();
      });
    }
  }

  ngOnDestroy(): void {
    this.statusSub?.unsubscribe();
  }

  /** Show errors only after the user has interacted with the control. */
  protected shouldShowErrors(): boolean {
    if (this.control === null) return false;
    return this.control.invalid && (this.control.dirty || this.control.touched);
  }

  /**
   * Pick the i18n key for the FIRST error in errorMap that matches one
   * of the control's actual errors. We prefer caller-supplied mappings
   * over generic fallbacks so error-code-specific copy wins.
   */
  protected resolveErrorKey(): string {
    if (this.control === null || this.control.errors === null) {
      return this.errorMap['_default'] ?? 'auth.fields.required';
    }
    for (const key of Object.keys(this.control.errors)) {
      if (this.errorMap[key] !== undefined) {
        return this.errorMap[key];
      }
    }
    return this.errorMap['_default'] ?? 'auth.fields.required';
  }

  /**
   * Pull parameters off the active validator error so the i18n string can
   * interpolate them. For example, minLength errors carry { requiredLength }
   * which the translation 'Must be at least {{ requiredLength }} characters'
   * uses.
   *
   * Returns an empty object when there's nothing to pass — safe for
   * translate's pure-key path.
   */
  protected errorParams(): Record<string, unknown> {
    if (this.control === null || this.control.errors === null) return {};
    /* Take the first error's value-object (Angular's convention). */
    const firstKey = Object.keys(this.control.errors)[0];
    if (firstKey === undefined) return {};
    const value = this.control.errors[firstKey];
    if (typeof value === 'object' && value !== null) {
      return value as Record<string, unknown>;
    }
    return {};
  }

  private recomputeDescribedBy(): void {
    /* aria-describedby points at whichever auxiliary element is
       currently rendered: error (if shown) or helper (if present).
       Pointing at non-existent IDs is harmless but ugly; we keep it
       clean. */
    const ids: string[] = [];
    if (this.shouldShowErrors()) {
      ids.push(this.errorId);
    } else if (this.helper !== null && this.helper !== '') {
      ids.push(this.helperId);
    }
    this.describedBy = ids.length > 0 ? ids.join(' ') : null;
  }
}
