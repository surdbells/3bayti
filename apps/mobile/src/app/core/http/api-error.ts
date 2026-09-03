/**
 * Extract the best human-readable message from a failed HTTP call.
 *
 * The v3 API returns errors as:
 *   { "error": { "code": "...", "message": "...",
 *                "details"?: { "fields"?: { field: [msg] }, _root?: [msg] } } }
 *
 * Angular's HttpClient puts the parsed body on `HttpErrorResponse.error`, so the
 * envelope lives at `err.error.error`. This prefers the first field-level
 * validation message, then the top-level API message, and only falls back to the
 * caller's string (usually a localized `i18n.t(...)` generic) when the response
 * carries nothing usable, e.g. a real network failure.
 *
 * Rationale: a specific server message ("A measurement for size 'M' already
 * exists for your store.", a validation error, a business-rule violation) is far
 * more actionable than a generic "something went wrong", even when the API
 * message is English-only. Pass a localized fallback so offline/empty-body cases
 * still read naturally in the user's language.
 */
export function apiErrorMessage(err: any, fallback: string): string {
  // Network / connection failure, no server body to read; keep the (localized)
  // fallback the caller supplied.
  if (err?.status === 0) {
    return fallback;
  }

  const body = err?.error?.error ?? err?.error ?? err;

  // Field errors live at details.fields ({ field: [msg] }); root/business ones
  // at details._root ([msg]); older shapes were a flat { field: [msg] } map.
  // Dig recursively so a nested { fields: {...} } yields the actual message
  // instead of stringifying an object to "[object Object]".
  const details = body?.details;
  if (details && typeof details === 'object') {
    const found = firstMessage(details as Record<string, unknown>);
    if (found) return found;
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

/**
 * First human-readable leaf inside an error `details` object. Handles the
 * flat `{ field: [msg] }` shape, the nested `{ fields: { field: [msg] } }`
 * shape, and `{ _root: [msg] }`. Returns null when nothing usable is found.
 */
function firstMessage(obj: Record<string, unknown>): string | null {
  for (const value of Object.values(obj)) {
    if (typeof value === 'string' && value.trim() !== '') {
      return value;
    }
    if (Array.isArray(value)) {
      const first = value.find((v) => typeof v === 'string' && v.trim() !== '');
      if (first) return String(first);
    } else if (value && typeof value === 'object') {
      const nested = firstMessage(value as Record<string, unknown>);
      if (nested) return nested;
    }
  }
  return null;
}
