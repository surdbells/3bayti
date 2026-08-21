/**
 * Extract the best human-readable message from a failed HTTP call.
 *
 * The v3 API returns errors as:
 *   { "error": { "code": "...", "message": "...", "details"?: { field: [msg] } } }
 *
 * Angular's HttpClient puts the parsed body on `HttpErrorResponse.error`, so the
 * envelope lives at `err.error.error`. This prefers the first field-level
 * validation message, then the top-level API message, and only falls back to the
 * caller's string (usually a localized `i18n.t(...)` generic) when the response
 * carries nothing usable — e.g. a real network failure.
 *
 * Rationale: a specific server message ("A measurement for size 'M' already
 * exists for your store.", a validation error, a business-rule violation) is far
 * more actionable than a generic "something went wrong", even when the API
 * message is English-only. Pass a localized fallback so offline/empty-body cases
 * still read naturally in the user's language.
 */
export function apiErrorMessage(err: any, fallback: string): string {
  // Network / connection failure — no server body to read; keep the (localized)
  // fallback the caller supplied.
  if (err?.status === 0) {
    return fallback;
  }

  const body = err?.error?.error ?? err?.error ?? err;

  const details = body?.details;
  if (details && typeof details === 'object') {
    for (const key of Object.keys(details)) {
      const v = (details as Record<string, unknown>)[key];
      const msg = Array.isArray(v) ? v[0] : v;
      if (msg) return String(msg);
    }
  }

  if (typeof body === 'string' && body.trim() !== '') {
    return body;
  }

  const message = body?.message;
  if (typeof message === 'string' && message.trim() !== '') {
    return message;
  }

  return fallback;
}
