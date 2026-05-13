# 3bayti — M2 Architecture Snapshot

**As of:** Day 7 of M2 v3 migration rollout (May 13, 2026)
**Status:** Strangler-fig migration in progress — v3 serves majority of catalog reads from `apps/web`; legacy backend still serves everything else.

## One-page picture

```
                          ┌─────────────────────────────────────┐
                          │   Cloudflare Workers (edge)         │
                          │   staging.3bayti.ae                 │
                          │                                     │
                          │   apps/web   (Angular 21 + SSR)     │
                          │   ─────────────────────────         │
                          │   • prerendered HTML for SEO        │
                          │   • RoutedHttpClient adapter        │
                          │   • TransferState for hydration     │
                          └──────────────┬──────────────────────┘
                                         │
                ┌────────────────────────┴────────────────────────┐
                │                                                 │
                │      packages/api-client (workspace)            │
                │      ────────────────────────────────           │
                │      ENDPOINT_ROUTING table picks               │
                │      backend per endpoint:                      │
                │                                                 │
                │      target = 'new' → api-v3.3bayti.ae          │
                │      target = 'old' → api.3bayti.ae (legacy)    │
                │                                                 │
                └────┬────────────────────────────────┬───────────┘
                     │                                │
       ┌─────────────▼────────────┐    ┌──────────────▼─────────────┐
       │  api-v3.3bayti.ae        │    │  api.3bayti.ae             │
       │  (v3 — Slim 4 + Doctrine) │   │  (legacy — WordPress PHP)  │
       │                          │    │                            │
       │  endpoints (new):        │    │  endpoints (still):        │
       │  • GET /v3/categories    │    │  • GET /v2/categories/:slug│
       │  • GET /v3/products      │    │     (embedded products)    │
       │  • GET /v3/products/:s   │    │  • GET /v2/featured-vendors│
       │  • GET /v3/vendors       │    │  • POST /users/login (old) │
       │  • GET /v3/vendors/:s    │    │  • cart, checkout, orders  │
       │  • POST /v3/auth/login   │    │  • tickets, chat, profile  │
       │  • POST /v3/auth/register│    │  • OTP send/validate       │
       │  • + 50 more (M2 built)  │    │  • measurements, etc.      │
       │                          │    │                            │
       │  serves: web catalog     │    │  serves: web's special     │
       │  reads + auth            │    │  endpoints + all of        │
       │                          │    │  apps/mobile + apps/portal │
       └──────────┬───────────────┘    └─────────────┬──────────────┘
                  │                                  │
                  │                                  │
       ┌──────────▼──────────┐         ┌─────────────▼──────────────┐
       │  PostgreSQL 18      │         │  Legacy MariaDB            │
       │  bayti_v3 database  │         │  (unchanged from pre-M2)   │
       │                     │         │                            │
       │  migrated Day 4:    │         │  source of truth until     │
       │  • 8 categories     │         │  M3 reconcile cutover      │
       │  • 9,330 users      │         │                            │
       │  • 104 vendors      │         │  changes here are          │
       │  • 2,160 products   │         │  RE-IMPORTED into v3 by    │
       │  • 27 reviews       │         │  re-running the migration  │
       │                     │         │  scripts (UPSERT mode)     │
       │  hosted same droplet│         │                            │
       └─────────────────────┘         └────────────────────────────┘


       ┌──────────────────────────────────────────────────────────┐
       │  Other clients (UNCHANGED in M2; planned for M3)         │
       │                                                          │
       │  apps/mobile  (Ionic + Capacitor, Angular 21)            │
       │       → still calls api.3bayti.ae directly via           │
       │         NetworkService + GlobalComponent URL constants   │
       │       → 37 files × ~123 NetworkService invocations       │
       │       → M3 migration: per-flow flip (auth → catalog →    │
       │         cart → orders → etc)                             │
       │                                                          │
       │  apps/portal  (Angular 19, vendor admin)                 │
       │       → still calls api.3bayti.ae directly               │
       │       → also M3 migration                                │
       │                                                          │
       │  Both apps got pnpm-workspace + CI on Day 6 to unblock   │
       │  the actual migration in M3.                             │
       └──────────────────────────────────────────────────────────┘
```

## What lives on what server

```
┌─ DigitalOcean Droplet (142.93.172.195) ───────────────────────────┐
│                                                                   │
│  /www/wwwroot/3bayti/apps/api/      ←  v3 Slim 4 PHP backend     │
│      composer.json, src/, public/                                 │
│      exposed via nginx at api-v3.3bayti.ae:443                   │
│                                                                   │
│  /www/server/pgsql/                 ←  PostgreSQL 18              │
│      databases: bayti_v3                                          │
│                                                                   │
│  /www/wwwroot/api.3bayti.ae/        ←  Legacy WordPress PHP       │
│      (untouched by M2)                                            │
│      exposed at api.3bayti.ae:443                                │
│                                                                   │
│  Legacy MariaDB                     ←  Source-of-truth DB         │
│      (still authoritative until M3 cutover)                       │
│                                                                   │
└───────────────────────────────────────────────────────────────────┘

┌─ Cloudflare Workers (edge) ────────────────────────────────────────┐
│                                                                    │
│  3bayti-web Worker                  ←  apps/web (Angular SSR)     │
│      static assets + dynamic SSR                                   │
│      custom domain: staging.3bayti.ae                             │
│      preview URL: 3bayti-web.<account>.workers.dev                │
│                                                                    │
└────────────────────────────────────────────────────────────────────┘

apps/mobile  →  built on Sodiq's machine, distributed via app stores
apps/portal  →  hosting TBD (likely Cloudflare Pages post-M3)
```

## The strangler-fig pattern at the code level

```
┌─ apps/web component (e.g. ProductDetailComponent) ─────────────────┐
│                                                                    │
│  this.routed.get<ProductDetail>('GET /products/:slug', {           │
│    params: { slug }                                                │
│  })                                                                │
│                                                                    │
└────────────────────────────┬───────────────────────────────────────┘
                             │ (Observable<NormalisedResponse<T>>)
                             │
┌────────────────────────────▼───────────────────────────────────────┐
│  apps/web/src/app/core/http/routed-http-client.ts                  │
│  RoutedHttpClient.get()                                            │
│                                                                    │
│   1. resolveConfig('GET /products/:slug')  →  EndpointConfig       │
│      from packages/api-client/feature-flags.ts                     │
│      { target: 'new', shape: 'v3-envelope',                        │
│        oldPath: '/v2/products/:slug',                              │
│        newPath: '/v3/products/:slug' }                             │
│                                                                    │
│   2. resolveUrl(...) builds the full URL by:                       │
│      • Picking base URL: target='new' → v3BaseUrl                  │
│      • Substituting :slug with the params value                    │
│      • Result: https://api-v3.3bayti.ae/v3/products/la23           │
│                                                                    │
│   3. http.request(GET, url)  →  Angular HttpClient                 │
│      (uses fetch backend, preserves SSR TransferState)             │
│                                                                    │
│   4. normaliseResponse(raw, 'v3-envelope')                         │
│      → unwraps {data, meta?} to NormalisedResponse<T>              │
│                                                                    │
└────────────────────────────────────────────────────────────────────┘
```

## What the M2 rollout accomplished

| Item | Day |
|---|---|
| v3 API scaffold (Slim 4 + Doctrine + PostgreSQL 18) | M1 (Days 1-3 of broader rollout) |
| 53 v3 endpoints implemented + tested | M1 |
| `packages/api-client` routing layer | Day 3 |
| Legacy data migration to PostgreSQL (UPSERT-able) | Day 4 |
| apps/web flip to v3 catalog reads + auth | Day 5 |
| Mobile + portal pnpm/CI unification | Day 6 |
| Vendor #92 slug bug fix | Day 7 |
| Demo runbook + this diagram | Day 7 |

## What M3 will accomplish

| Item | Target |
|---|---|
| apps/mobile flip (auth + catalog + cart) | M3 |
| apps/portal flip (admin + vendor reads) | M3 |
| v3 endpoints to fill parity gaps (featured-vendors, categories/:slug embedded products) | M3 |
| HTML entity cleanup in vendor descriptions | M3 |
| Designer routes in apps/web (/designer, /designer/:slug) | M3 (or Phase 2) |
| 36 conflicting users' email reset campaign | M3 |
| Reconcile-deletes pre-cutover script | M3 |
| Legacy MariaDB retirement | M3 final phase |
