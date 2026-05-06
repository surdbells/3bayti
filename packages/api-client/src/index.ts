/**
 * @3bayti/api-client
 *
 * Typed HTTP client with auth, retry, and feature-flag-driven endpoint
 * routing. Used by all three frontends (web, mobile, portal).
 *
 * Quick start:
 *
 *   import { createClient, createBearerProvider } from '@3bayti/api-client';
 *
 *   const client = createClient({
 *     oldBaseUrl: 'https://api.3bayti.ae',
 *     newBaseUrl: 'https://api-v3.3bayti.ae',
 *     authProvider: createBearerProvider(() => localStorage.getItem('access_token')),
 *   });
 *
 *   // The "GET /health" string is a routing key — the actual URL
 *   // (old or new backend) is resolved by ENDPOINT_ROUTING.
 *   const health = await client.request('GET /health');
 */

export { createClient } from './client.js';
export type { ClientOptions, RequestOptions } from './client.js';

export { createBearerProvider } from './auth-interceptor.js';
export type { AuthHeaderProvider } from './auth-interceptor.js';

export { ENDPOINT_ROUTING, resolveUrl } from './feature-flags.js';
export type { EndpointTarget, EndpointConfig } from './feature-flags.js';

export { normaliseError } from './error-normaliser.js';
export type { ApiError } from './error-normaliser.js';

export { retryWithBackoff } from './retry.js';
export type { RetryOptions } from './retry.js';
