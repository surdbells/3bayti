# 3bayti Web

The SEO-optimized public website for **3bayti**, the premium abaya/kaftan/modest-wear marketplace. Counterpart to the [3bayti mobile app](https://github.com/surdbells/abayti_app) — shares the brand, business, and APIs, but has its own UI codebase tailored for the web.

## Status

**Phase 2 (Weeks 4–7): Catalog.** Home, `/category` index, `/category/:slug` listings, and `/product/:slug` PDPs are live with full SSR. Search, designer pages, and richer PDPs (reviews, schema.org JSON-LD) follow.

**Hosting:** migrated from Cloudflare Pages to **Cloudflare Workers + Static Assets** (May 2026) to enable runtime SSR for the long tail of products beyond the prerender cap. See [Deploy](#deploy) below.

## Stack

- **Angular 21** with built-in SSR (`@angular/ssr`) — same major version as the mobile app
- **Tailwind CSS 4** (PostCSS-based) for utility-first styling
- **Standalone components** + signal-based state where applicable
- **TypeScript strict mode**

## Project structure

```
src/
├── app/
│   ├── core/             # cross-cutting concerns: API client, SEO service
│   │   ├── api/          # API constants (auto-generated from mobile app)
│   │   └── seo/          # Title, meta, OG, JSON-LD helpers
│   ├── features/         # route-owning feature components (home, category, product, …)
│   │   └── home/
│   ├── layout/           # persistent layout: header, footer
│   │   ├── header/
│   │   └── footer/
│   ├── shared/           # generic UI primitives (buttons, headings, etc.)
│   ├── app.ts            # root shell component
│   ├── app.routes.ts     # client-side route table
│   └── app.routes.server.ts  # server-side prerender config
├── styles.scss           # global tokens + Tailwind import
├── index.html            # SEO defaults (title, meta, OG, fonts)
├── server.ts             # legacy Node/Express SSR entry — kept for reference; production uses worker.ts
├── worker.ts             # Cloudflare Worker SSR entry (used by `ng build`)
├── main.ts               # client entry
└── main.server.ts        # server entry
```

## Local development

Prereqs: Node 20+, npm 10+.

```bash
npm install
npm start              # http://localhost:4200 with HMR
```

## Production build

```bash
npm run build          # outputs to dist/3bayti-web/
```

The build produces:
- `dist/3bayti-web/browser/` — static assets (HTML, CSS, JS, fonts) — what the Workers ASSETS binding serves directly
- `dist/3bayti-web/server/` — the SSR Worker bundle (`server.mjs`) plus its lazy-loaded chunks. Loaded by Cloudflare for any route the assets binding doesn't match (i.e. long-tail PDPs beyond the prerender cap).

## Deploy

**Hosting:** Cloudflare Workers + Static Assets. Production URL: [`https://staging.3bayti.ae/`](https://staging.3bayti.ae/) (will move to `3bayti.ae` when the production domain is hooked up).

### How requests flow

```
User request
   │
   ▼
Cloudflare edge
   │
   ├── Path matches a static file in dist/3bayti-web/browser/?
   │      ├─ Yes → serve it directly (ASSETS binding). No Worker invocation.
   │      │        Covers: home, /category, /category/:slug for 8 categories,
   │      │                /product/:slug for the top-200 most-recent products,
   │      │                hashed JS/CSS, sitemap.xml, robots.txt.
   │      │
   │      └─ No  → invoke the Worker (src/worker.ts → AngularAppEngine.handle()).
   │               Runs SSR against the upstream API, returns rendered HTML.
   │               This is the path for the long-tail of 4,800+ products.
   │
   ▼
Response to user
```

This means the fast path (prerendered routes) costs zero Worker invocations and ~3–10ms TTFB from the edge cache; the slow path (runtime SSR) is ~200–600ms cold and ~200ms warm.

### CI/CD (continuous deploy on push to master)

The repo's GitHub Actions workflow (`.github/workflows/ci.yml`) handles everything:

1. On every push and PR — builds, prerenders, runs SSR-output spot-checks (asserts the prerendered HTML actually contains expected content, asserts the Worker bundle exports a `fetch` handler).
2. On push to `master` — also deploys to Cloudflare Workers via [`cloudflare/wrangler-action@v3`](https://github.com/cloudflare/wrangler-action) and runs end-to-end smoke-tests against both the fresh `*.workers.dev` URL and the production custom domain. The smoke-test asserts that long-tail PDPs return real product data via runtime SSR (not the empty fallback shell).

If the smoke-test fails, the workflow goes red and the deploy is flagged loudly — but Cloudflare doesn't auto-rollback. To revert: Cloudflare dash → Workers & Pages → `3bayti-web` → Deployments tab → pick a previous version → Rollback.

### Required GitHub secrets

These two secrets must be set under repo Settings → Secrets and variables → Actions:

| Secret | What | Where to find |
|---|---|---|
| `CLOUDFLARE_API_TOKEN` | API token scoped to "Edit Cloudflare Workers" on the company account | [Cloudflare API Tokens](https://dash.cloudflare.com/profile/api-tokens) → Create Token → Edit Cloudflare Workers template |
| `CLOUDFLARE_ACCOUNT_ID` | The 32-char hex ID of the Cloudflare account hosting the Worker | Right sidebar of any page in the Cloudflare dashboard |

### `SITE_URL` build-time env var

Drives every canonical link, OG meta URL, sitemap entry, and JSON-LD URL on the site. CI sets this implicitly via the build environment; if you build locally and want a non-default value, prefix the build:

```bash
SITE_URL=https://3bayti.ae npm run build
```

If unset, the build defaults to `https://staging.3bayti.ae`.

### Local Worker preview

To run the production-shaped Worker locally (closer to prod than `npm start`):

```bash
npm run build
npm run preview:worker      # wraps `wrangler dev --local`
```

This boots the Worker on `http://localhost:8787` with the same routing the production Cloudflare edge would do. Useful for testing SSR-side bugs that don't reproduce in `npm start`.

### Cloudflare config files

The repo's `public/` directory contains:

- **`_headers`** — per-route HTTP headers (security, caching). Hashed assets get 1-year immutable cache; HTML pages get 5-min CDN cache + 24-hour stale-while-revalidate. Workers + Static Assets reads this natively, same syntax as Pages.
- **`_redirects`** — URL redirects. Empty for now; we'll add as URLs evolve.

Both files end up at the root of `dist/3bayti-web/browser/` after build, where the Workers ASSETS binding picks them up automatically.

### Worker config (`wrangler.jsonc`)

Top-level Worker config lives in `wrangler.jsonc` at repo root. The important settings:

- **`main`** — points at the built Worker bundle (`./dist/3bayti-web/server/server.mjs`)
- **`assets.directory`** — points at the prerendered static output (`./dist/3bayti-web/browser/`)
- **`assets.html_handling: "drop-trailing-slash"`** — matches the canonical URL format used in the sitemap and `rel=canonical` (no trailing slash)
- **`compatibility_flags: ["nodejs_compat"]`** — required for Angular's transitive Node dependencies

`angular.json` has two related settings worth knowing about:

- **`ssr.experimentalPlatform: "neutral"`** — emits a Workers-compatible bundle (no `createRequire(import.meta.url)` Node-isms that crash on the edge)
- **`security.allowedHosts`** — Angular's SSRF guard allowlist; populated with `3bayti.ae`, `*.3bayti.ae`, `*.workers.dev`, and localhost. Without this, runtime SSR silently falls back to serving an empty client-side shell.

### Build output structure

```
dist/3bayti-web/
├── browser/        ← served by Workers ASSETS binding (static files)
│   ├── index.html  ← prerendered home page (full DOM, SEO-ready)
│   ├── category/index.html, category/abayas-1/index.html, …  ← prerendered categories
│   ├── product/la26-2637/index.html, …  ← top-200 prerendered PDPs
│   ├── _dev/components/index.html
│   ├── sitemap.xml ← generated by postbuild hook
│   ├── robots.txt
│   ├── _headers    ← Workers reads this
│   ├── _redirects
│   ├── main-*.js   ← hashed entry chunk
│   └── styles-*.css
└── server/         ← Worker bundle + SSR runtime (loaded by Cloudflare for non-prerendered routes)
    ├── server.mjs  ← Worker entry (built from src/worker.ts)
    ├── chunk-*.mjs ← lazy-loaded route chunks
    └── …
```

## SEO checklist

- [x] SSR / prerendering (no JS-only content for crawlers) — top 200 PDPs prerendered, long-tail via runtime SSR
- [x] `<title>` + meta description per route via Angular's Meta service
- [x] Open Graph + Twitter Card tags
- [x] Canonical URLs (matched to Workers `drop-trailing-slash` config — no canonical-vs-served-URL mismatch)
- [x] `sitemap.xml` (W1.4 — generated at build via postbuild hook, covers all 1,600+ products + 8 categories + 80+ vendors)
- [x] `robots.txt` (W1.4)
- [x] JSON-LD: Organization + WebSite on home (W1.4); Product + ItemList on PDPs/categories (Phase 2)
- [x] Lighthouse ≥ 90 on Performance + SEO (W1.9 close-out — desktop 100/100, mobile 80/100; mobile perf is Phase 5 work)
- [ ] hreflang for ar/en (Phase 2)

## Roadmap

| Phase | Focus | Weeks |
|-------|-------|-------|
| 1 | Foundation: SSR, SEO scaffolding, hosting, CI/CD, home page | 1–3 |
| 2 | Catalog: category listings, product detail, search | 4–7 |
| 3 | Commerce: auth, cart, checkout, orders, account | 8–11 |
| 4 | Post-purchase: tracking, messages, support, vendor pages | 12–14 |
| 5 | Polish + SEO push: Core Web Vitals, content, schema validation | 15–18 |

See `docs/PHASE_1_PLAN.md` (TBD) for the detailed Phase 1 plan.

## License

Proprietary — © 2026 DOST HQ Limited / 3bayti. All rights reserved.
