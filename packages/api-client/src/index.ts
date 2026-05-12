/**
 * @3bayti/api-client
 *
 * Typed HTTP client with auth, retry, response normalisation, and
 * feature-flag-driven endpoint routing. Used by all three frontends
 * (web, mobile, portal).
 *
 * Quick start
 * -----------
 *
 *   import { createClient, createBearerProvider } from '@3bayti/api-client';
 *
 *   const client = createClient({
 *     oldBaseUrl: 'https://api.3bayti.ae',
 *     newBaseUrl: 'https://api-v3.3bayti.ae',
 *     authProvider: createBearerProvider(() => localStorage.getItem('access_token')),
 *   });
 *
 *   // The "GET /products" string is a routing KEY -- the actual URL
 *   // (old or new backend) is resolved by ENDPOINT_ROUTING.
 *   const result = await client.request<Product[]>('GET /products', {
 *     query: { limit: 24, category: 'abayas' },
 *   });
 *
 *   console.log(result.data);        // Product[]
 *   console.log(result.meta?.total); // 1928 (only on paginated endpoints)
 *
 * Response shape
 * --------------
 *
 * All responses normalised to:
 *
 *     { data: T, meta?: { total, limit, offset, has_more } }
 *
 * Consumers don't need to know whether the legacy `{response_code,
 * status,data,message}` shape or the new `{data,meta?}` shape was
 * served. See response-normaliser.ts.
 */

export { createClient } from './client.js';
export type { ClientOptions, RequestOptions } from './client.js';

export { createBearerProvider } from './auth-interceptor.js';
export type { AuthHeaderProvider } from './auth-interceptor.js';

export { ENDPOINT_ROUTING, resolveUrl, resolveConfig } from './feature-flags.js';
export type { EndpointTarget, EndpointConfig } from './feature-flags.js';

export { normaliseError } from './error-normaliser.js';
export type { ApiError } from './error-normaliser.js';

export { normaliseResponse } from './response-normaliser.js';
export type {
  NormalisedResponse,
  PaginationMeta,
  ResponseShape,
} from './response-normaliser.js';

export { retryWithBackoff } from './retry.js';
export type { RetryOptions } from './retry.js';
