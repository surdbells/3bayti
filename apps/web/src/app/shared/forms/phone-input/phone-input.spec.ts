import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Component } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { PhoneInputComponent, parseE164, PHONE_COUNTRIES, DEFAULT_COUNTRY_CODE } from './phone-input';

/* ===================================================================
   parseE164 unit tests (pure function)
   =================================================================== */
describe('parseE164', () => {
  it('parses a UAE number', () => {
    const result = parseE164('+971501234567');
    expect(result?.country.code).toBe('AE');
    expect(result?.nationalDigits).toBe('501234567');
  });

  it('parses a Saudi number', () => {
    const result = parseE164('+966501234567');
    expect(result?.country.code).toBe('SA');
    expect(result?.nationalDigits).toBe('501234567');
  });

  it('disambiguates +1 by picking the first match (US wins by list order)', () => {
    /* Per PHONE_COUNTRIES order, US is listed before CA, so a bare
       +1 number maps to US. The dropdown lets the user re-select. */
    const result = parseE164('+15551234567');
    expect(result?.country.code).toBe('US');
    expect(result?.nationalDigits).toBe('5551234567');
  });

  it('returns null when the value does not start with +', () => {
    expect(parseE164('971501234567')).toBeNull();
    expect(parseE164('501234567')).toBeNull();
  });

  it('returns null when no country matches', () => {
    expect(parseE164('+999123456')).toBeNull();
  });

  it('strips non-digit characters from the national portion', () => {
    expect(parseE164('+971 50 123 4567')?.nationalDigits).toBe('501234567');
    expect(parseE164('+971-50-1234567')?.nationalDigits).toBe('501234567');
  });

  it('picks the longest matching dial code (greedy match)', () => {
    /* +1 matches both US and +1242 doesn't exist in our list, so US
       wins. The test here is illustrative of the sort-by-length behaviour
       for codes like +1 (US/CA) vs +1XXX (which we don't carry).
       More importantly: '+971' (3 chars) must NOT be matched by '+97'
       (2 chars) if such a country existed. */
    const result = parseE164('+971501234567');
    expect(result?.country.code).toBe('AE');
    expect(result?.country.dialCode).toBe('+971');
  });
});

/* ===================================================================
   PhoneInputComponent integration
   =================================================================== */
@Component({
  standalone: true,
  imports: [PhoneInputComponent, ReactiveFormsModule],
  template: `<form [formGroup]="form">
    <ui-phone-input formControlName="phone" inputId="ph"></ui-phone-input>
  </form>`,
})
class HostComponent {
  form = new FormGroup({
    phone: new FormControl('', [Validators.required]),
  });
}

describe('PhoneInputComponent', () => {
  let fixture: ComponentFixture<HostComponent>;
  let host: HostComponent;

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [HostComponent] });
    fixture = TestBed.createComponent(HostComponent);
    host = fixture.componentInstance;
    fixture.detectChanges();
  });

  afterEach(() => {
    TestBed.resetTestingModule();
  });

  it('defaults to UAE country selection when control value is empty', () => {
    const select: HTMLSelectElement = fixture.nativeElement.querySelector('select');
    expect(select.value).toBe(DEFAULT_COUNTRY_CODE);
    expect(host.form.controls['phone'].value).toBe('');
  });

  it('emits a full E.164 string when the user types digits', () => {
    const input: HTMLInputElement = fixture.nativeElement.querySelector('input[type="tel"]');
    input.value = '501234567';
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(host.form.controls['phone'].value).toBe('+971501234567');
  });

  it('emits an empty string when digits are cleared (not a partial +971)', () => {
    const input: HTMLInputElement = fixture.nativeElement.querySelector('input[type="tel"]');
    input.value = '501234567';
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(host.form.controls['phone'].value).toBe('+971501234567');

    input.value = '';
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(host.form.controls['phone'].value).toBe('');
  });

  it('changes the dial code when the user picks a different country', () => {
    const input: HTMLInputElement = fixture.nativeElement.querySelector('input[type="tel"]');
    input.value = '501234567';
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    const select: HTMLSelectElement = fixture.nativeElement.querySelector('select');
    select.value = 'SA';
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    expect(host.form.controls['phone'].value).toBe('+966501234567');
  });

  it('strips non-digit input as the user types (spaces, dashes)', () => {
    const input: HTMLInputElement = fixture.nativeElement.querySelector('input[type="tel"]');
    input.value = '50 123 4567';
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(host.form.controls['phone'].value).toBe('+971501234567');
  });

  it('writeValue() parses an incoming E.164 and populates both selectors', () => {
    host.form.controls['phone'].setValue('+966501234567');
    fixture.detectChanges();

    const select: HTMLSelectElement = fixture.nativeElement.querySelector('select');
    const input: HTMLInputElement = fixture.nativeElement.querySelector('input[type="tel"]');
    expect(select.value).toBe('SA');
    expect(input.value).toBe('501234567');
  });

  it('writeValue(null) clears the digits without changing country selection', () => {
    host.form.controls['phone'].setValue('+971501234567');
    fixture.detectChanges();

    host.form.controls['phone'].setValue(null);
    fixture.detectChanges();

    const select: HTMLSelectElement = fixture.nativeElement.querySelector('select');
    const input: HTMLInputElement = fixture.nativeElement.querySelector('input[type="tel"]');
    /* Country stays at last user choice (AE). Digits clear. */
    expect(select.value).toBe('AE');
    expect(input.value).toBe('');
  });

  it('validate() returns null for empty value (lets Validators.required own emptiness)', () => {
    expect(host.form.controls['phone'].errors).toMatchObject({ required: true });
    /* phoneInvalid is NOT present, that's the assertion. */
    expect(host.form.controls['phone'].errors?.['phoneInvalid']).toBeUndefined();
  });

  it('validate() returns null for a correctly-shaped UAE number', () => {
    host.form.controls['phone'].setValue('+971501234567');
    expect(host.form.controls['phone'].errors).toBeNull();
  });

  it('validate() returns phoneInvalid for a too-short national number', () => {
    host.form.controls['phone'].setValue('+971501');
    expect(host.form.controls['phone'].errors).toMatchObject({ phoneInvalid: true });
  });

  it('validate() returns phoneInvalid for a too-long national number', () => {
    host.form.controls['phone'].setValue('+9715012345678901');
    expect(host.form.controls['phone'].errors).toMatchObject({ phoneInvalid: true });
  });

  it('validate() returns phoneInvalid for an unknown dial code', () => {
    host.form.controls['phone'].setValue('+999123456789');
    expect(host.form.controls['phone'].errors).toMatchObject({ phoneInvalid: true });
  });

  it('disables both controls when the form control is disabled', () => {
    host.form.controls['phone'].disable();
    fixture.detectChanges();

    const select: HTMLSelectElement = fixture.nativeElement.querySelector('select');
    const input: HTMLInputElement = fixture.nativeElement.querySelector('input[type="tel"]');
    expect(select.disabled).toBe(true);
    expect(input.disabled).toBe(true);
  });
});

/* ===================================================================
   Country list sanity
   =================================================================== */
describe('PHONE_COUNTRIES', () => {
  it('has UAE as the first entry (default-selected)', () => {
    expect(PHONE_COUNTRIES[0].code).toBe('AE');
  });

  it('contains all GCC countries', () => {
    const gcc = ['AE', 'SA', 'KW', 'BH', 'QA', 'OM'];
    for (const code of gcc) {
      expect(PHONE_COUNTRIES.some(c => c.code === code)).toBe(true);
    }
  });

  it('every country has a + leading dial code and a non-empty name', () => {
    for (const c of PHONE_COUNTRIES) {
      expect(c.dialCode.startsWith('+')).toBe(true);
      expect(c.name.length).toBeGreaterThan(0);
      expect(c.nationalDigits.min).toBeGreaterThanOrEqual(7);
      expect(c.nationalDigits.max).toBeLessThanOrEqual(15);
      expect(c.nationalDigits.min).toBeLessThanOrEqual(c.nationalDigits.max);
    }
  });
});
