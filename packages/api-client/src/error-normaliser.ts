/**
 * Error normalisation.
 *
 * Both backends return error envelopes, but the OLD backend uses
 * `{response_code, status, message, data}` while the NEW backend (per
 * the OpenAPI contract) uses `{error: {code, message, details}}`.
 * This normaliser produces a single `ApiError` shape regardless of
 * which backend the response came from.
 */

export interface ApiError extends Error {
  status: number;
  /** Stable error code if the backend provides one (e.g. 'AUTH_INVALID_PASSWORD'). */
  code?: string;
  /** Server-side validation details, when available. */
  details?: unknown;
  /** Raw response body, useful for debugging. */
  raw?: string;
}

export function normaliseError(response: Response, rawBody: string): ApiError {
  let parsedBody: unknown = null;
  try {
    parsedBody = JSON.parse(rawBody);
  } catch {
    // Body wasn't JSON. Leave parsedBody as null.
  }

  const error: ApiError = Object.assign(new Error(), {
    name: 'ApiError',
    status: response.status,
    raw: rawBody,
  });

  if (parsedBody && typeof parsedBody === 'object') {
    const obj = parsedBody as Record<string, unknown>;

    // NEW backend shape: { error: { code, message, details } }
    if (obj.error && typeof obj.error === 'object') {
      const innerError = obj.error as Record<string, unknown>;
      error.message = String(innerError.message ?? `HTTP ${response.status}`);
      if (typeof innerError.code === 'string') {
        error.code = innerError.code;
      }
      if (innerError.details !== undefined) {
        error.details = innerError.details;
      }
      return error;
    }

    // OLD backend shape: { response_code, status, message, data }
    if ('response_code' in obj || 'status' in obj) {
      error.message = String(obj.message ?? `HTTP ${response.status}`);
      if (typeof obj.status === 'string' && obj.status !== 'success') {
        error.code = obj.status;
      }
      if (obj.data !== undefined) {
        error.details = obj.data;
      }
      return error;
    }
  }

  // Fallback when body isn't JSON or doesn't match either shape.
  error.message = `HTTP ${response.status}: ${response.statusText}`;
  return error;
}
