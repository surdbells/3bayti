import { HttpErrorResponse } from '@angular/common/http';
import { FormGroup } from '@angular/forms';

/**
 * mapApiErrors, translate an API error response into FormControl errors.
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
  /** True if the error was a network / non-HttpErrorResponse case -
   *  callers usually present a generic "network unreachable" toast. */
  isNetworkError: boolean;
}

interface ApiErrorBody {
  error_code?: string;
  code?: string;
  message?: string;
  errors?: Record<string, string[]>;
  /* Seconds until the client may retry, present on OTP_RATE_LIMITED (429).
     Clients enter a lockout for this many seconds. */
  retry_after?: number;
  /* The API's native envelope nests the code under `error`; the BFF
     proxy passes some responses through in that shape. Read both. */
  error?: {
    code?: string;
    message?: string;
    errors?: Record<string, string[]>;
    retry_after?: number;
  };
}

/** Fallback lockout window when the 429 body omits `retry_after`. */
export const DEFAULT_OTP_LOCKOUT_SECONDS = 60;

/**
 * Read `retry_after` (seconds) from a rate-limit (429) error body.
 *
 * The OTP rate-limit contract is HTTP 429 with body
 *   { error_code: 'OTP_RATE_LIMITED', retry_after: <integer seconds> }
 * where retry_after is the number of seconds until the client may retry.
 * On OTP_RATE_LIMITED the client should lock out Resend (and Verify) for
 * this window. When the field is absent / non-positive we fall back to
 * DEFAULT_OTP_LOCKOUT_SECONDS (~60s) so the UI still degrades safely.
 *
 * Reads both the flat shape and the nested `error` envelope (the BFF proxy
 * passes some responses through in the native nested form).
 */
export function readRetryAfterSeconds(
  err: unknown,
  fallback: number = DEFAULT_OTP_LOCKOUT_SECONDS,
): number {
  if (err instanceof HttpErrorResponse) {
    const body = err.error as ApiErrorBody | string | null;
    if (body !== null && typeof body === 'object') {
      const raw = body.retry_after ?? body.error?.retry_after;
      if (typeof raw === 'number' && Number.isFinite(raw) && raw > 0) {
        return Math.ceil(raw);
      }
    }
  }
  return fallback;
}

export function mapApiErrors(
  err: unknown,
  form: FormGroup,
  map: Record<string, ApiErrorMapping>,
): MapApiErrorsResult {
  /* Non-HTTP error, network failure, timeout, etc. Caller surfaces
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

  const code = body.error_code ?? body.error?.code ?? body.code;
  const fieldErrors = body.errors ?? body.error?.errors;
  const unmapped: string[] = [];

  /* VALIDATION_FAILED brings field-level errors in `errors`. Walk them
     first so per-field copy wins over the generic mapping. */
  if (code === 'VALIDATION_FAILED' && fieldErrors !== undefined) {
    for (const [field, messages] of Object.entries(fieldErrors)) {
      const control = form.get(field);
      if (control !== null && messages.length > 0) {
        /* The first message wins; UI shows one line at a time. */
        control.setErrors({ apiValidation: { message: messages[0] } });
        control.markAsTouched();
      }
    }
    /* After per-field handling, return, don't double-emit. */
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
