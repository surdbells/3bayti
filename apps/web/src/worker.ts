/**
 * Cloudflare Worker entry — runs Angular SSR for non-prerendered routes.
 *
 * How this fits into the request flow:
 *
 *   1. A request hits Cloudflare's edge.
 *   2. Workers + Static Assets routing: Cloudflare checks the assets
 *      manifest (built from `dist/3bayti-web/browser/`) FIRST. If a
 *      static file matches, it's served directly — never invokes this
 *      Worker. That covers:
 *        - / (prerendered home)
 *        - /category, /category/:slug for the 8 prerendered categories
 *        - /product/:slug for the top-200 prerendered products
 *        - hashed JS/CSS/woff2 assets, sitemap.xml, robots.txt
 *      This is the fast path: zero Worker invocations, full edge cache.
 *   3. If assets miss (e.g. /product/long-tail-slug-not-prerendered),
 *      Cloudflare invokes this Worker. We hand the Request to
 *      `AngularAppEngine.handle()`, which:
 *        - Looks up the matching Angular route (defined in
 *          app.routes.server.ts)
 *        - For RenderMode.Server (the default fallback for /product/:slug
 *          via PrerenderFallback.Server), it runs SSR and returns a
 *          fully-rendered Response.
 *        - Returns `null` if no Angular route matches — we then return
 *          a 404. (In practice this should be rare since `'**'` is
 *          configured as Prerender; non-matching paths typically resolve
 *          via the assets binding to index.html or fail there.)
 *
 * Why @angular/ssr (NOT @angular/ssr/node):
 *   The /node entry uses Express, Node Streams, and Node-only path APIs
 *   that don't exist in the Workers runtime. The platform-agnostic
 *   @angular/ssr export uses standard Web APIs (Request, Response) and
 *   runs natively on Workers, Deno, Bun, etc.
 *
 * Why nodejs_compat is still set in wrangler.jsonc:
 *   Some Angular runtime internals (e.g. zone.js shims, Buffer references
 *   in dependencies) expect Node globals to exist. The flag enables
 *   Cloudflare's polyfills without forcing us to use Node-specific APIs
 *   in our own code.
 */

import { AngularAppEngine, createRequestHandler } from '@angular/ssr';

/* Singleton — instantiated once per Worker isolate. The class internally
 * caches the parsed app manifest and entry-point loaders, so reusing the
 * same instance across requests avoids redundant work. */
const angularApp = new AngularAppEngine();

/**
 * Workers Assets binding — set in wrangler.jsonc's `assets.binding`.
 * Currently unused because we rely on Cloudflare's default static-first
 * routing (which serves assets BEFORE this Worker is invoked). We
 * declare the type here for future use (e.g. if we add edge-cached SSR
 * responses or programmatic asset lookups).
 */
interface Env {
  ASSETS: { fetch: (request: Request) => Promise<Response> };
}

export default {
  async fetch(request: Request, _env: Env, _ctx: ExecutionContext): Promise<Response> {
    try {
      /* Hand the request to Angular's SSR engine. Returns:
       *   - Response: SSR succeeded (or a prerendered page was served
       *     internally by AngularAppEngine — though in our setup
       *     prerendered pages are served by the assets binding before
       *     reaching here).
       *   - null: no Angular route matched the URL. */
      const response = await angularApp.handle(request);
      if (response) {
        return response;
      }

      /* No Angular route matched. This shouldn't normally happen given
       * our `'**' → Prerender` catch-all, but if it does, return a
       * minimal 404. (We don't proxy back to env.ASSETS here because
       * if assets had a match, the request never would have reached
       * this Worker in the first place.) */
      return new Response('Not Found', {
        status: 404,
        headers: { 'content-type': 'text/plain; charset=utf-8' },
      });
    } catch (err) {
      /* Last-resort error handler. We log to the Worker's stderr (visible
       * in Cloudflare's tail logs / Workers Logs) so we can debug, but
       * we don't leak the error message to the client — generic 500. */
      console.error('[worker.fetch] SSR error:', err);
      return new Response('Internal Server Error', {
        status: 500,
        headers: { 'content-type': 'text/plain; charset=utf-8' },
      });
    }
  },
};

/**
 * Request handler used by the Angular CLI's dev-server during build.
 *
 * The Angular build tooling looks for a default export AND an exported
 * `reqHandler`. The default export above is the runtime Worker handler;
 * `reqHandler` is what the build pipeline uses to perform prerendering
 * at compile time.
 */
export const reqHandler = createRequestHandler(async (req: Request) => {
  const response = await angularApp.handle(req);
  return response ?? new Response('Not Found', { status: 404 });
});
