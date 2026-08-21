/**
 * Extract the best human-readable message from a failed HTTP call.
 *
 * The v3 API returns errors as:
 *   { "error": { "code": "...", "message": "...", "details"?: { field: [msg] } } }
 *
 * Angular's HttpClient puts the parsed body on `HttpErrorResponse.error`, so the
 * envelope lives at `err.error.error`. This helper prefers the first field-level
 * validation message, then the top-level API message, and only falls back to the
 * caller's generic string when the response carries nothing usable (e.g. a real
 * network failure). Use it everywhere an HTTP `error:` handler shows a toast, so
 * a specific server message (a duplicate/conflict, a validation error, a
 * business-rule violation) is surfaced instead of a generic "try again".
 */
export function apiErrorMessage(err: any, fallback = 'Something went wrong. Please try again.'): string {
  // Network / connection failure — no server body to read.
  if (err?.status === 0) {
    return 'Network problem — check your connection and try again.';
  }

  // The parsed body. For the v3 envelope it's `{ error: {...} }`; some endpoints
  // (or proxies) return the inner object directly, so accept both.
  const body = err?.error?.error ?? err?.error ?? err;

  // Field-level validation details win — they're the most specific.
  const details = body?.details;
  if (details && typeof details === 'object') {
    for (const key of Object.keys(details)) {
      const v = (details as Record<string, unknown>)[key];
      const msg = Array.isArray(v) ? v[0] : v;
      if (msg) return String(msg);
    }
  }

  // A string body (plain-text error) is itself the message.
  if (typeof body === 'string' && body.trim() !== '') {
    return body;
  }

  const message = body?.message;
  if (typeof message === 'string' && message.trim() !== '') {
    return message;
  }

  return fallback;
}
