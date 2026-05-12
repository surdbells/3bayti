/**
 * Response shape normalizer.
 *
 * The 10-day rollout uses a strangler-fig pattern (Decision 10): some
 * endpoints serve from legacy backend, some from the new v3 backend.
 * They return different shapes. This module collapses them to one
 * shape consumers can rely on.
 *
 * Input shapes
 * ------------
 *
 * Legacy ('v2' shape):
 *
 *     {
 *       response_code: number,    // HTTP-like (200, 404, ...)
 *       status: 'success' | 'error',
 *       data: T,                  // payload
 *       message: string
 *     }
 *
 * v3 envelope ('v3-envelope'):
 *
 *     {
 *       data: T,
 *       meta?: { total, limit, offset, has_more }
 *     }
 *
 * Raw ('raw'):
 *
 *     T  (the response IS the data, no wrapper)
 *
 * Output shape
 * ------------
 *
 * Always:
 *
 *     {
 *       data: T,
 *       meta?: PaginationMeta
 *     }
 *
 * Consumers always access `.data`. If pagination existed (only on
 * v3-envelope shapes), `.meta` is populated.
 *
 * Errors
 * ------
 *
 * Errors are NOT normalised here — they propagate through normaliseError
 * (separate module). This file only handles success responses.
 */

export interface PaginationMeta {
  total: number;
  limit: number;
  offset: number;
  has_more: boolean;
}

export interface NormalisedResponse<T = unknown> {
  data: T;
  meta?: PaginationMeta;
}

/**
 * Legacy v2 backend response wrapper.
 */
interface V2Wrapper<T> {
  response_code?: number;
  status?: string;
  data: T;
  message?: string;
}

/**
 * v3 envelope wrapper.
 */
interface V3Wrapper<T> {
  data: T;
  meta?: PaginationMeta;
}

export type ResponseShape = 'v2' | 'v3-envelope' | 'raw';

/**
 * Normalise a response to the unified `{ data, meta? }` shape.
 *
 * The shape hint comes from ENDPOINT_ROUTING; the client passes it
 * into here based on which endpoint was called.
 */
export function normaliseResponse<T>(
  raw: unknown,
  shape: ResponseShape = 'v3-envelope',
): NormalisedResponse<T> {
  switch (shape) {
    case 'v2': {
      // Legacy may sometimes omit the wrapper for malformed responses;
      // be defensive.
      if (raw !== null && typeof raw === 'object' && 'data' in raw) {
        const wrapper = raw as V2Wrapper<T>;
        return { data: wrapper.data };
      }
      // Fallback: treat the whole response as data.
      return { data: raw as T };
    }

    case 'v3-envelope': {
      if (raw !== null && typeof raw === 'object' && 'data' in raw) {
        const wrapper = raw as V3Wrapper<T>;
        const result: NormalisedResponse<T> = { data: wrapper.data };
        if (wrapper.meta) {
          result.meta = wrapper.meta;
        }
        return result;
      }
      // If v3 unexpectedly returned a raw shape, accept it.
      return { data: raw as T };
    }

    case 'raw':
    default: {
      return { data: raw as T };
    }
  }
}
