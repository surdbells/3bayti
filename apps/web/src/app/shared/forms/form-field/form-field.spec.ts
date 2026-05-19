import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Component } from '@angular/core';
import { FormBuilder, FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { FormFieldComponent } from './form-field';
import { provideI18n } from '../../../core/i18n';

/**
 * Host component for FormFieldComponent testing.
 *
 * Because FormFieldComponent uses ng-content for the projected input,
 * we need a real consumer to exercise the realistic flow.
 */
@Component({
  standalone: true,
  imports: [FormFieldComponent, ReactiveFormsModule],
  template: `
    <form [formGroup]="form">
      <ui-form-field
        label="auth.login.emailLabel"
        fieldId="test-email"
        helper="auth.register.passwordHint"
        [control]="form.controls['email']"
        [errorMap]="errorMap"
      >
        <input id="test-email" type="email" formControlName="email" />
      </ui-form-field>
    </form>
  `,
})
class HostComponent {
  form: FormGroup;
  errorMap: Record<string, string> = {
    required: 'auth.fields.required',
    email: 'auth.fields.email_invalid',
  };

  constructor(fb: FormBuilder) {
    this.form = fb.group({
      email: ['', [Validators.required, Validators.email]],
    });
  }
}

describe('FormFieldComponent', () => {
  let fixture: ComponentFixture<HostComponent>;
  let host: HostComponent;

  beforeEach(async () => {
    TestBed.configureTestingModule({
      imports: [HostComponent],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideI18n()],
    });
    fixture = TestBed.createComponent(HostComponent);
    host = fixture.componentInstance;
    fixture.detectChanges();
  });

  afterEach(() => {
    TestBed.resetTestingModule();
  });

  it('renders a label associated to the input via `for`', () => {
    const label: HTMLLabelElement | null = fixture.nativeElement.querySelector('label');
    expect(label).not.toBeNull();
    expect(label?.getAttribute('for')).toBe('test-email');
  });

  it('renders the helper text when no error is shown', () => {
    const helper: HTMLElement | null = fixture.nativeElement.querySelector('.form-field__helper');
    expect(helper).not.toBeNull();
    expect(helper?.id).toBe('test-email-helper');
  });

  it('does NOT render the error block when the control is untouched', () => {
    /* control is required + empty + untouched. invalid is true but
       shouldShowErrors() returns false until touched/dirty. */
    expect(host.form.controls['email'].invalid).toBe(true);
    const error = fixture.nativeElement.querySelector('.form-field__error');
    expect(error).toBeNull();
  });

  it('renders the error block after the control is marked touched', () => {
    host.form.controls['email'].markAsTouched();
    /* statusChanges only fires on status transitions; touched alone
       doesn't trigger it. Force CD ourselves to mirror what Angular
       does on user interaction. */
    fixture.detectChanges();
    const error: HTMLElement | null = fixture.nativeElement.querySelector('.form-field__error');
    expect(error).not.toBeNull();
    expect(error?.getAttribute('role')).toBe('alert');
    expect(error?.id).toBe('test-email-error');
  });

  it('sets aria-describedby to the helper id when no error is shown', () => {
    const control = fixture.nativeElement.querySelector('.form-field__control');
    expect(control?.getAttribute('aria-describedby')).toBe('test-email-helper');
  });

  it('maps the matching validator error to the supplied i18n key (resolveErrorKey)', () => {
    /* Touch + set an invalid email so the email validator fires. */
    host.form.controls['email'].setValue('not-an-email');
    host.form.controls['email'].markAsTouched();
    host.form.controls['email'].updateValueAndValidity();
    fixture.detectChanges();

    const error: HTMLElement | null = fixture.nativeElement.querySelector('.form-field__error');
    expect(error).not.toBeNull();
    /* We assert on the resolved i18n key (data-error-key) rather than
       textContent — the translate pipe needs HTTP-loaded translations
       which we don't wire up in unit tests. */
    expect(error?.getAttribute('data-error-key')).toBe('auth.fields.email_invalid');
  });

  it('falls back to errorMap._default when no validator key matches', () => {
    /* Set a custom error not in errorMap, with no _default → falls
       back to the hard-coded auth.fields.required. */
    host.form.controls['email'].setValue('valid@example.com');
    host.form.controls['email'].setErrors({ unknownError: true });
    host.form.controls['email'].markAsTouched();
    fixture.detectChanges();

    const error: HTMLElement | null = fixture.nativeElement.querySelector('.form-field__error');
    expect(error?.getAttribute('data-error-key')).toBe('auth.fields.required');
  });

  it('renders the required asterisk when the @Input is true', async () => {
    /* Need a separate host where required is set. */
    @Component({
      standalone: true,
      imports: [FormFieldComponent, ReactiveFormsModule],
      template: `
        <form [formGroup]="form">
          <ui-form-field
            label="x"
            fieldId="rq"
            [required]="true"
            [control]="form.controls['x']"
          >
            <input id="rq" formControlName="x" />
          </ui-form-field>
        </form>
      `,
    })
    class RequiredHost {
      form = new FormGroup({ x: new FormControl('') });
    }

    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [RequiredHost],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideI18n()],
    });
    const reqFixture = TestBed.createComponent(RequiredHost);
    reqFixture.detectChanges();

    const star = reqFixture.nativeElement.querySelector('.form-field__required');
    expect(star).not.toBeNull();
  });
});
