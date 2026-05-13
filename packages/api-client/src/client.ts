/**
 * Thin typed fetch wrapper with strangler-fig routing + envelope
 * normalisation.
 *
 * Wraps `fetch` with:
 *   - Endpoint routing via ENDPOINT_ROUTING ('old' vs 'new' per endpoint)
 *   - Auth header injection (delegates to AuthInterceptor)
 *   - Response shape normalisation (legacy v2 + v3-envelope -> unified)
 *   - Error normalisation (delegates to errorNormaliser)
 *   - Retry on transient errors (delegates to retry helper)
 *
 * This is a runtime-agnostic implementation. Angular consumers wrap
 * it in an HttpClient adapter so they get DI + the interceptor
 * ecosystem; that lives in apps/web/src/app/core/http/ (created on
 * Day 5). Mobile + portal use the same Angular adapter via their
 * HttpClient.
 *
 * Usage
 * -----
 *
 *   import { createClient } from '@3bayti/api-client';
 *
 *   const client = createClient({
 *     oldBaseUrl: 'https://api.3bayti.ae',
 *     newBaseUrl: 'https://api-v3.3bayti.ae',
 *     authProvider: () => `Bearer ${localStorage.getItem('access_token')}`,
 *   });
 *
 *   // Returns NormalisedResponse<Product[]> - always { data, meta? }
 *   const products = await client.request('GET /products', {
 *     query: { limit: 24, category: 'abayas' },
 *   });
 *
 *   console.log(products.data);       // Product[]
 *   console.log(products.meta?.total);// 1928
 */

import { resolveConfig, resolveUrl } from './feature-flags.js';
import { normaliseError } from './error-normaliser.js';
import { normaliseResponse, type NormalisedResponse } from './response-normaliser.js';
import { retryWithBackoff } from './retry.js';
import type { AuthHeaderProvider } from './auth-interceptor.js';

export interface ClientOptions {
  /** Base URL for the LEGACY backend (e.g. https://api.3bayti.ae) */
  oldBaseUrl: string;
  /** Base URL for the NEW backend (e.g. https://api-v3.3bayti.ae) */
  newBaseUrl: string;
  /**
   * Provides the current Authorization header value, or null when
   * unauthenticated. Called fresh on each request so the client picks
   * up token rotations without restarting.
   */
  authProvider?: AuthHeaderProvider;
  /** Maximum retries on transient failures. Default 2. */
  maxRetries?: number;
}

export interface RequestOptions {
  /** Path parameters to interpolate (e.g. {slug: 'red-abaya'}). */
  params?: Record<string, string | number>;
  /** Query string parameters. */
  query?: Record<string, string | number | boolean | undefined>;
  /** Request body. JSON-encoded automatically. */
  body?: unknown;
  /** Extra headers. Authorization is injected automatically - don't set it here. */
  headers?: Record<string, string>;
  /** Abort signal for cancellation. */
  signal?: AbortSignal;
}

export function createClient(options: ClientOptions) {
  const bases = { old: options.oldBaseUrl, new: options.newBaseUrl };
  const maxRetries = options.maxRetries ?? 2;

  async function request<TData>(
    routeKey: string,
    requestOptions: RequestOptions = {},
  ): Promise<NormalisedResponse<TData>> {
    const config = resolveConfig(routeKey);
    const url = resolveUrl(routeKey, bases, requestOptions.params);
    const finalUrl = appendQuery(url, requestOptions.query);

    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(requestOptions.headers ?? {}),
    };

    const authHeader = options.authProvider?.();
    if (authHeader) {
      headers['Authorization'] = authHeader;
    }

    const [method] = routeKey.split(' ');

    const fetchOptions: RequestInit = {
      method,
      headers,
      signal: requestOptions.signal,
    };

    if (requestOptions.body !== undefined && method !== 'GET' && method !== 'HEAD') {
      fetchOptions.body = JSON.stringify(requestOptions.body);
    }

    const performRequest = async (): Promise<NormalisedResponse<TData>> => {
      const response = await fetch(finalUrl, fetchOptions);

      if (!response.ok) {
        throw normaliseError(response, await safeReadText(response));
      }

      // 204 No Content
      if (response.status === 204) {
        return { data: undefined as TData };
      }

      const raw = await response.json();
      return normaliseResponse<TData>(raw, config.shape ?? 'v3-envelope');
    };

    return retryWithBackoff(performRequest, { maxRetries });
  }

  return { request };
}

function appendQuery(
  url: string,
  query?: Record<string, string | number | boolean | undefined>,
): string {
  if (!query) return url;
  const entries = Object.entries(query).filter(([, v]) => v !== undefined);
  if (entries.length === 0) return url;
  const qs = entries
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
    .join('&');
  return url + (url.includes('?') ? '&' : '?') + qs;
}

async function safeReadText(response: Response): Promise<string> {
  try {
    return await response.text();
  } catch {
    return '';
  }
}
