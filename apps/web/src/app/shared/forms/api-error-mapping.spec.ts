import { describe, it, expect } from 'vitest';
import { HttpErrorResponse } from '@angular/common/http';
import { FormGroup, FormControl } from '@angular/forms';
import { mapApiErrors, ApiErrorMapping } from './api-error-mapping';

function makeForm(): FormGroup {
  return new FormGroup({
    email: new FormControl(''),
    phone: new FormControl(''),
    password: new FormControl(''),
  });
}

const REGISTER_ERROR_MAP: Record<string, ApiErrorMapping> = {
  CONFLICT_EMAIL_TAKEN: { field: 'email', key: 'emailTaken' },
  CONFLICT_PHONE_TAKEN: { field: 'phone', key: 'phoneTaken' },
  OTP_RATE_LIMITED: { field: null, key: 'rateLimited' },
};

describe('mapApiErrors', () => {
  it('attaches a per-field error when the API code maps to a known field', () => {
    const form = makeForm();
    const err = new HttpErrorResponse({
      status: 409,
      error: { error_code: 'CONFLICT_EMAIL_TAKEN', message: 'Already registered.' },
    });
    const result = mapApiErrors(err, form, REGISTER_ERROR_MAP);

    expect(form.controls['email'].errors).toEqual({ emailTaken: true });
    expect(form.controls['email'].touched).toBe(true);
    expect(result.unmapped).toEqual([]);
    expect(result.isNetworkError).toBe(false);
  });

  it('reports the code in `unmapped` when the mapping field is null', () => {
    const form = makeForm();
    const err = new HttpErrorResponse({
      status: 429,
      error: { error_code: 'OTP_RATE_LIMITED', message: 'Too many.' },
    });
    const result = mapApiErrors(err, form, REGISTER_ERROR_MAP);

    expect(result.unmapped).toEqual(['OTP_RATE_LIMITED']);
    /* No control got an error attached. */
    expect(form.controls['email'].errors).toBeNull();
    expect(form.controls['phone'].errors).toBeNull();
  });

  it('reports the code in `unmapped` when there is no mapping at all', () => {
    const form = makeForm();
    const err = new HttpErrorResponse({
      status: 500,
      error: { error_code: 'UNKNOWN_THING_HAPPENED', message: 'Oops.' },
    });
    const result = mapApiErrors(err, form, REGISTER_ERROR_MAP);

    expect(result.unmapped).toEqual(['UNKNOWN_THING_HAPPENED']);
  });

  it('walks the `errors` object on VALIDATION_FAILED and attaches per-field errors', () => {
    const form = makeForm();
    const err = new HttpErrorResponse({
      status: 422,
      error: {
        error_code: 'VALIDATION_FAILED',
        message: 'Validation failed.',
        errors: {
          email: ['Email is not valid.'],
          password: ['Must be at least 8 characters.'],
        },
      },
    });
    const result = mapApiErrors(err, form, REGISTER_ERROR_MAP);

    expect(form.controls['email'].errors).toEqual({
      apiValidation: { message: 'Email is not valid.' },
    });
    expect(form.controls['password'].errors).toEqual({
      apiValidation: { message: 'Must be at least 8 characters.' },
    });
    expect(form.controls['email'].touched).toBe(true);
    expect(form.controls['password'].touched).toBe(true);
    expect(result.unmapped).toEqual([]);
  });

  it('ignores errors targeting unknown control names', () => {
    const form = makeForm();
    const err = new HttpErrorResponse({
      status: 422,
      error: {
        error_code: 'VALIDATION_FAILED',
        errors: { ssn: ['Invalid SSN.'] },
      },
    });
    /* Doesn't throw, doesn't mutate the form. */
    expect(() => mapApiErrors(err, form, REGISTER_ERROR_MAP)).not.toThrow();
    expect(form.controls['email'].errors).toBeNull();
  });

  it('returns isNetworkError=true for non-HttpErrorResponse inputs', () => {
    const result1 = mapApiErrors(new TypeError('fetch failed'), makeForm(), REGISTER_ERROR_MAP);
    expect(result1.isNetworkError).toBe(true);

    const result2 = mapApiErrors(null, makeForm(), REGISTER_ERROR_MAP);
    expect(result2.isNetworkError).toBe(true);
  });

  it('returns isNetworkError=true for status 0 (browser-side connect failure)', () => {
    const err = new HttpErrorResponse({ status: 0, error: null });
    const result = mapApiErrors(err, makeForm(), REGISTER_ERROR_MAP);
    expect(result.isNetworkError).toBe(true);
  });

  it('treats non-object error bodies (e.g. HTML from upstream proxy) as network-shaped', () => {
    const err = new HttpErrorResponse({
      status: 503,
      error: '<html>maintenance</html>',
    });
    const result = mapApiErrors(err, makeForm(), REGISTER_ERROR_MAP);
    expect(result.isNetworkError).toBe(true);
  });

  it('handles an API response with no error_code at all', () => {
    const err = new HttpErrorResponse({
      status: 500,
      error: { message: 'Something broke.' },
    });
    const result = mapApiErrors(err, makeForm(), REGISTER_ERROR_MAP);
    /* No code → nothing to map → unmapped is empty, not a network error. */
    expect(result.unmapped).toEqual([]);
    expect(result.isNetworkError).toBe(false);
  });
});
