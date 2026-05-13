// Empty module shim. Used by wrangler.jsonc's `alias` to stub out
// modules we never actually use at runtime but esbuild encounters
// during bundling.
//
// Current users:
//   - xhr2: Angular's @angular/common/http SSR backend tree-shakes
//     out a dynamic `import("xhr2")` fallback. We use
//     provideHttpClient(withFetch()) so xhr2 is never reached, but
//     wrangler 4.88+ tries to resolve the import path anyway and
//     fails because xhr2 doesn't ship Workers-compatible code.
//     Aliasing to this shim makes esbuild happy without bloating
//     the bundle.

export default {};
