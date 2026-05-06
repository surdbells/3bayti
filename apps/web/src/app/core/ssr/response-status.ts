import { inject, RESPONSE_INIT, PLATFORM_ID } from '@angular/core';
import { isPlatformServer } from '@angular/common';

/**
 * Sets the HTTP response status code for SSR'd responses.
 *
 * Why this exists (W2.2c):
 * ========================
 * Without this, invalid product/category slugs render a "not found" page
 * but the HTTP response is HTTP 200. Search engines that don't run JS
 * will index those URLs as real pages, polluting the index with paths
 * that don't exist.
 *
 * `RESPONSE_INIT` is an InjectionToken<ResponseInit | null> exported from
 * @angular/core. The `@angular/ssr` engine creates a `ResponseInit`
 * object before rendering, provides it via this token, then uses the
 * SAME object to construct the final `Response` after rendering finishes.
 * Mutating `status` on this shared object during render therefore
 * propagates to the HTTP response that Cloudflare Workers emits.
 *
 * Reference: @angular/ssr/fesm2022/ssr.mjs — the engine does
 * `new Response(stream, responseInit)` after render, so any in-render
 * mutations to `responseInit.status` are honored.
 *
 * Why it's a no-op on the browser:
 * ================================
 * The token's factory returns `null` on browser platforms (the response
 * has long since been emitted by then). Components calling this on
 * client-side hydration would see `responseInit === null` and we silently
 * skip the mutation rather than throwing.
 *
 * Usage:
 *   private setStatus = createSetSsrStatus();
 *   ...
 *   if (apiReturned404) {
 *     this.notFound.set(true);
 *     this.setStatus(404);
 *   }
 */
export function createSetSsrStatus(): (status: number) => void {
  const responseInit = inject(RESPONSE_INIT, { optional: true });
  const platformId = inject(PLATFORM_ID);

  return (status: number): void => {
    /* No-op on the browser — by the time the client runs, the response
     * has already been sent. */
    if (!isPlatformServer(platformId)) return;

    /* Defensive: in some test/contrived setups the token may be missing
     * even on the server. Don't crash; just don't set the status. */
    if (responseInit === null) return;

    responseInit.status = status;
  };
}
