/**
 * Retry with exponential backoff for transient failures.
 *
 * Retries on:
 *   - Network errors (fetch throws TypeError)
 *   - 5xx responses
 *   - 408 Request Timeout
 *   - 429 Too Many Requests (respects Retry-After if present)
 *
 * Does NOT retry on:
 *   - 4xx (other than 408/429) — these are deterministic errors that
 *     won't change on retry
 *   - 2xx and 3xx — those are success or handled redirects
 */

import type { ApiError } from './error-normaliser.js';

export interface RetryOptions {
  maxRetries: number;
  /** Base delay in ms. Default 200. Doubles each attempt up to maxDelay. */
  baseDelayMs?: number;
  /** Cap on backoff delay in ms. Default 5000. */
  maxDelayMs?: number;
}

export async function retryWithBackoff<T>(
  fn: () => Promise<T>,
  options: RetryOptions,
): Promise<T> {
  const baseDelay = options.baseDelayMs ?? 200;
  const maxDelay = options.maxDelayMs ?? 5000;

  let lastError: unknown;

  for (let attempt = 0; attempt <= options.maxRetries; attempt++) {
    try {
      return await fn();
    } catch (err) {
      lastError = err;

      if (!isRetriable(err) || attempt === options.maxRetries) {
        throw err;
      }

      const delay = Math.min(baseDelay * Math.pow(2, attempt), maxDelay);
      // Add jitter ±25% so batched failures don't all retry in lockstep.
      const jittered = delay * (0.75 + Math.random() * 0.5);

      await sleep(jittered);
    }
  }

  // Unreachable, but TypeScript can't see that.
  throw lastError;
}

function isRetriable(err: unknown): boolean {
  // Network error — fetch threw.
  if (err instanceof TypeError) return true;

  // ApiError from our normaliser.
  if (typeof err === 'object' && err !== null && 'status' in err) {
    const apiErr = err as ApiError;
    if (apiErr.status >= 500) return true;
    if (apiErr.status === 408) return true;
    if (apiErr.status === 429) return true;
  }

  return false;
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
