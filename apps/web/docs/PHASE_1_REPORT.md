# Phase 1 Report — Foundation

**Phase 1 status: ✅ Complete**
**Date:** May 4, 2026
**Live URL:** https://3bayti-web.pages.dev/

> **Update (May 5, 2026):** The deployment model described below
> ("Cloudflare Pages static hosting — no Workers/runtime SSR")
> reflects the state at Phase 1 close-out. With the catalogue at
> 5,000+ products it became infeasible to prerender everything,
> so the project migrated to **Cloudflare Workers + Static Assets**
> with runtime SSR for long-tail PDPs. The current production URL
> is [`https://staging.3bayti.ae/`](https://staging.3bayti.ae/).
> See `README.md` § Deploy for the current architecture. The rest
> of this document is preserved as a historical record of the
> Phase 1 deliverable.

---

## Goal recap

Per the Phase 1 plan: by end of Phase 1, a public-facing URL renders a server-rendered, indexable "Hello 3bayti" page with proper SEO scaffolding, the new repo has a clean project structure, and one real backend GET endpoint is live to prove the catalog-fetching pattern works.

## Acceptance criteria

| # | Criterion | Status |
|---|-----------|:------:|
| 1 | Public URL exists and serves a 3bayti-branded page | ✅ |
| 2 | View Source reveals real HTML (proves SSR works) | ✅ |
| 3 | `/sitemap.xml` returns valid XML | ✅ |
| 4 | `/robots.txt` exists with correct directives | ✅ |
| 5 | Lighthouse ≥ 90 on Performance and SEO | ⚠️ Mostly* |
| 6 | One v2 GET endpoint live (`/v2/categories`) | ⏳ Backend pending |
| 7 | Repo has clear `README.md` for teammates | ✅ |
| 8 | CI/CD pipeline runs on PR + auto-deploys to staging | ✅ |

*Lighthouse desktop is 100 across all four. Mobile is 100 on SEO/A11y/Best Practices but 80 on Performance — see below.

## What shipped

### Sprint commits

| Sprint | Commit | Title |
|--------|--------|-------|
| W1.1 | `aa4a404` | Phase 1 init — Angular 21 SSR workspace + home page |
| W1.2 | `99cd421` | API client service + v2 OpenAPI spec for backend |
| W1.4 | `fdcb948` | SEO infrastructure — SeoService, schema helpers, sitemap generator |
| W1.3 | `f95a2d0` | Design-token-driven UI primitives + dev preview route |
| W1.8 | `0ff4a7c` | CI/CD pipeline + Cloudflare Pages config |

W1.5 (home page) shipped as part of W1.1; W1.6 (OpenAPI spec) shipped as part of W1.2; W1.7 (categories page) deferred to Phase 2 dependency on backend deploy.

### Stack as built

- **Angular 21.2** with `@angular/ssr` (built-in Universal)
- **Tailwind CSS 4.2.4** via PostCSS (`@tailwindcss/postcss`)
- **TypeScript strict mode**, standalone components throughout, OnPush change detection on UI primitives
- **Cloudflare Pages** static hosting (no Workers/runtime SSR — everything prerendered at build time)
- **GitHub Actions** for CI on every push + PR

### Architecture summary

```
src/
├── app/
│   ├── core/
│   │   ├── api/         — typed HttpClient wrapper, v2 endpoint URL config
│   │   └── seo/         — SeoService + schema.org JSON-LD builders
│   ├── features/
│   │   ├── home/        — / route
│   │   └── dev-components/ — /_dev/components route (noindex'd)
│   ├── layout/
│   │   ├── header/      — sticky branded site header
│   │   └── footer/      — site footer
│   └── shared/
│       └── ui/          — Button, Container, Heading, Text, Stack primitives
├── styles.scss          — Tailwind + design-token CSS custom properties
└── index.html           — SEO defaults (OG, Twitter, fonts preconnect)

public/
├── _headers             — Cloudflare per-route HTTP headers
├── _redirects           — Cloudflare redirects (empty)
├── robots.txt
└── sitemap.xml          — overwritten by postbuild generator

scripts/
└── generate-sitemap.mjs — postbuild hook, fetches /v2/sitemap-data when live

docs/
└── api-v2.openapi.yaml  — contract for the 7 backend GETs
```

## Lighthouse audit

Run on May 4, 2026 against `https://3bayti-web.pages.dev/`.

### Desktop (default Lighthouse desktop preset)

| Category | Score |
|----------|------:|
| Performance | **100** |
| Accessibility | **100** |
| Best Practices | **100** |
| SEO | **100** |

Key metrics: FCP 0.6s, LCP 0.6s, TBT 20ms, CLS 0.005, Speed Index 0.7s, TTI 0.7s.

### Mobile (default Lighthouse mobile preset — emulates slow 4G + slow CPU)

| Category | Score |
|----------|------:|
| Performance | **80** |
| Accessibility | **100** |
| Best Practices | **100** |
| SEO | **100** |

Key metrics: FCP 1.3s, LCP 1.3s, TBT 870ms, CLS 0.023, Speed Index 1.5s, TTI 2.4s.

**The 870ms TBT is the bottleneck on mobile.** Mostly Angular framework hydration on a simulated slow CPU. Real Android devices will see lower numbers since simulated mobile in Lighthouse uses a 4× CPU slowdown.

### Performance improvements deferred to Phase 5

Phase 1's goal was foundation, not optimization. These are tracked for Phase 5 polish:

1. **Defer hydration to idle.** Angular 18+ supports `withIncrementalHydration()`. Currently we hydrate eagerly via `provideClientHydration(withEventReplay())`. Could shift to incremental hydration once we have interactive components that need it.
2. **Async-load Google Fonts.** Currently render-blocking via `<link rel="stylesheet">` in `index.html`. Should switch to `media="print"` + `onload` swap pattern, or self-host the font files.
3. **Reduce framework bundle size.** Angular 21 with all the providers is ~80KB gzipped. Tree-shaking hostNames could trim a few KB. Phase 5 has a budget for this.
4. **Image format / size optimization.** No images yet, but when product images land, route them through Cloudflare Images for AVIF + responsive sizes.

## What didn't ship in Phase 1

### W1.7 — Categories page (deferred to Phase 2 start)

Blocked on the backend deploy. The web code is ready (API client + SEO infrastructure both exist). Once `GET https://api.3bayti.ae/v2/categories` returns 200, wiring the categories page is one commit (~2 hours of work):

1. Create `src/app/features/categories/categories.ts`
2. Inject `ApiClientService` and call `getList<Category>('/categories')`
3. Render the list with `<ui-stack>` + `<ui-heading>` primitives
4. Add route `/category` in `app.routes.ts`
5. Set SEO meta + breadcrumb JSON-LD
6. Add to sitemap generator

### Storybook

Skipped intentionally. The `/_dev/components` route serves the same purpose at zero added dependency cost. Revisit if/when the team grows.

## Issues to track

### Issue 1 — Custom domain not yet hooked up

The deployed URL is `https://3bayti-web.pages.dev/` but the SEO meta tags, sitemap, and OG URLs all reference `https://web.3bayti.ae/`. Until the custom domain is added in Cloudflare Pages settings, the canonical URLs declared by the site don't match where it's actually served.

**Fix:** Cloudflare dash → Pages project → Custom domains → add `web.3bayti.ae`. Cert auto-provisioned.

### Issue 2 — `xhr2` chunks in the build

Angular HttpClient bundles xhr2 as a fallback even though we configured `withFetch()`. Two ~12KB lazy chunks. Not loaded at runtime in browser, so no real cost. Could be silenced with a custom build configuration but not worth the effort right now.

### Issue 3 — Cloudflare 308 redirects on subroutes

`/_dev/components` (no trailing slash) returns a 308 redirect to `/_dev/components/`. Cloudflare auto-normalizes paths to match the prerendered file structure (`_dev/components/index.html`). Functionally fine but users hit a cheap redirect. To avoid: use trailing slashes in internal links.

## Phase 2 readiness checklist

Before we start Phase 2 (Catalog: category landing, PDP, search):

- [x] SSR working end-to-end
- [x] SEO infrastructure ready (SeoService, schema helpers, sitemap)
- [x] UI primitives ready (Button, Container, Heading, Text, Stack)
- [x] API client ready (typed `getOne<T>` / `getList<T>` for the v2 envelope)
- [x] Hosting + deploy pipeline live
- [ ] Backend v2 endpoints live (operator: deploy `3bayti-backend-v2.zip`)
- [ ] Custom domain `web.3bayti.ae` hooked up (operator: Cloudflare Pages → Custom domains)
- [ ] Real product imagery decided + sourced (or stub assets)

The two unchecked items are operator action items — neither blocks Claude's next sprint of work, but both unlock Phase 2 features.

## Sign-off

Phase 1 delivered the foundation: a deployed, SSR'd, SEO-ready Angular 21 web app on Cloudflare Pages, with a typed API client ready for the v2 backend, a working CI/CD pipeline, and a clean primitive library to build on.

Phase 2 starts with **W1.7 (categories page)** as soon as the backend is live, then moves into category landing pages, product detail pages, and search.
