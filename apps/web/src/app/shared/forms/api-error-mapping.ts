import { HttpErrorResponse } from '@angular/common/http';
import { FormGroup } from '@angular/forms';

/**
 * mapApiErrors — translate an API error response into FormControl errors.
 *
 * Why
 * ---
 * Reactive Forms' `control.setErrors({ key: true })` is how inline
 * field errors render via the FormFieldComponent. But the API speaks
 * error_code strings, not Angular validator keys. This helper bridges
 * the two so pages can write:
 *
 *   try {
 *     await auth.register(...);
 *   } catch (err) {
 *     mapApiErrors(err, form, {
 *       CONFLICT_EMAIL_TAKEN: { field: 'email',  key: 'email_taken' },
 *       CONFLICT_PHONE_TAKEN: { field: 'phone',  key: 'phone_taken' },
 *       OTP_RATE_LIMITED:     { field: null,     key: 'rate_limited' },
 *     });
 *   }
 *
 * Returns
 * -------
 * The list of UNMAPPED error codes, so the calling page can decide
 * how to surface them (toast, banner, generic toast, etc.). If the
 * caller wants a single "unmapped" key to land on the toast, they can
 * pass the toast service and call `toast.error('auth.x.errors.generic')`
 * themselves when the returned array is non-empty.
 *
 * Shape of API error
 * ------------------
 * The API returns errors as:
 *   {
 *     error_code: 'CONFLICT_EMAIL_TAKEN',
 *     message: 'Email already registered.',
 *     errors?: { email: ['Already registered.'] }   // optional, on VALIDATION_FAILED
 *   }
 *
 * For VALIDATION_FAILED we additionally walk the `errors` object and
 * surface per-field validation errors directly on the matching
 * FormControl.
 */

export interface ApiErrorMapping {
  /** FormControl name to attach the error to. null = unmapped → caller toast. */
  field: string | null;
  /** Key written into setErrors({ [key]: true }). Used by FormFieldComponent
   *  to resolve the i18n message via errorMap. */
  key: string;
}

export interface MapApiErrorsResult {
  /** Codes from the API that did not have a mapping. */
  unmapped: string[];
  /** True if the error was a network / non-HttpErrorResponse case —
   *  callers usually present a generic "network unreachable" toast. */
  isNetworkError: boolean;
}

interface ApiErrorBody {
  error_code?: string;
  message?: string;
  errors?: Record<string, string[]>;
}

export function mapApiErrors(
  err: unknown,
  form: FormGroup,
  map: Record<string, ApiErrorMapping>,
): MapApiErrorsResult {
  /* Non-HTTP error — network failure, timeout, etc. Caller surfaces
     a generic toast. */
  if (!(err instanceof HttpErrorResponse)) {
    return { unmapped: [], isNetworkError: true };
  }

  /* 0 status from HttpClient = browser couldn't even establish a
     connection. Treat as network failure. */
  if (err.status === 0) {
    return { unmapped: [], isNetworkError: true };
  }

  const body = err.error as ApiErrorBody | string | null;
  /* Some upstream proxies return HTML/text bodies; those won't have a
     code. Surface as network-shaped. */
  if (body === null || typeof body !== 'object') {
    return { unmapped: [], isNetworkError: true };
  }

  const code = body.error_code;
  const unmapped: string[] = [];

  /* VALIDATION_FAILED brings field-level errors in `errors`. Walk them
     first so per-field copy wins over the generic mapping. */
  if (code === 'VALIDATION_FAILED' && body.errors !== undefined) {
    for (const [field, messages] of Object.entries(body.errors)) {
      const control = form.get(field);
      if (control !== null && messages.length > 0) {
        /* The first message wins; UI shows one line at a time. */
        control.setErrors({ apiValidation: { message: messages[0] } });
        control.markAsTouched();
      }
    }
    /* After per-field handling, return — don't double-emit. */
    return { unmapped: [], isNetworkError: false };
  }

  /* Single-code path. */
  if (code !== undefined && map[code] !== undefined) {
    const mapping = map[code];
    if (mapping.field !== null) {
      const control = form.get(mapping.field);
      if (control !== null) {
        control.setErrors({ [mapping.key]: true });
        control.markAsTouched();
      }
    } else {
      unmapped.push(code);
    }
  } else if (code !== undefined) {
    unmapped.push(code);
  }

  return { unmapped, isNetworkError: false };
}
