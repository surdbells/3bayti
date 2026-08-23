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

  // Field-level validation details win — they're the most specific. The v3
  // envelope nests them as details.fields.<field> = ["message"] (see
  // HttpException::validation), while some errors put arrays directly on
  // details. Dig through whichever shape to the FIRST usable string — never
  // stringify a raw object, which is what surfaced "[object Object]" to users
  // (details.fields is an object, and String({...}) === "[object Object]").
  const details = body?.details;
  if (details && typeof details === 'object') {
    const msg = firstString((details as Record<string, unknown>)['fields'] ?? details);
    if (msg) return msg;
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

/**
 * Recursively find the first non-empty string inside a value (string, number,
 * array, or nested object), so a validation payload like
 * `{ fields: { logo: ["Too long"] } }` yields "Too long" instead of a
 * stringified object. Depth-bounded to avoid pathological structures.
 */
function firstString(val: unknown, depth = 0): string | null {
  if (val == null || depth > 5) return null;
  if (typeof val === 'string') return val.trim() !== '' ? val : null;
  if (typeof val === 'number' || typeof val === 'boolean') return String(val);
  if (Array.isArray(val)) {
    for (const item of val) {
      const s = firstString(item, depth + 1);
      if (s) return s;
    }
    return null;
  }
  if (typeof val === 'object') {
    for (const key of Object.keys(val as Record<string, unknown>)) {
      const s = firstString((val as Record<string, unknown>)[key], depth + 1);
      if (s) return s;
    }
  }
  return null;
}
