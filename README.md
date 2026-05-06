# 3bayti

The unified platform for **3bayti** — an e-commerce marketplace for modest fashion
(abayas, kaftans, modest wear) curated from independent UAE designers.

This monorepo consolidates four previously-separate codebases:

| App | Purpose | Stack |
|---|---|---|
| `apps/web` | Customer-facing storefront | Angular 21 SSR on Cloudflare Workers |
| `apps/mobile` | Customer + lite vendor mobile app | Ionic 8 + Angular 21 + Capacitor 8 |
| `apps/portal` | Vendor + admin desktop portal | Angular 21 (upgraded from 19) |
| `apps/api` | Platform backend | Slim 4 + Doctrine 3 + PostgreSQL 16 |

Plus shared packages in `packages/` for design tokens, API contracts, API client,
shared UI components, and shared TS/ESLint configs.

## Status

> **Pre-launch.** This monorepo is in active migration from four standalone repos.
> See `docs/roadmap.md` for the full strategic plan, M-phase breakdown, and current
> migration status.

## Quick start

```bash
# install JS dependencies for all apps + packages
pnpm install

# install PHP dependencies for the API (run from apps/api)
cd apps/api && composer install && cd ../..

# run everything in parallel for local dev
pnpm dev
```

Detailed dev setup: see [`docs/runbooks/local-dev.md`](docs/runbooks/local-dev.md) (will be created in M0.4).

## Repository layout

```
3bayti/
├── apps/                ← deployable applications
│   ├── api/             ← Slim 4 backend
│   ├── web/             ← Angular SSR storefront
│   ├── mobile/          ← Ionic mobile app  (lands in M4)
│   └── portal/          ← Angular vendor + admin portal  (lands in M4)
├── packages/            ← shared libraries, consumed by apps/
│   ├── api-contracts/   ← OpenAPI spec + generated TS types
│   ├── api-client/      ← typed HTTP client used by all 3 frontends
│   ├── shared-ui/       ← cross-app components (web + portal)
│   ├── design-tokens/   ← brand colors, typography, spacing, shadows
│   ├── eslint-config-3bayti/
│   └── tsconfig-3bayti/
├── docs/
│   ├── roadmap.md       ← THE source of truth for direction & decisions
│   ├── architecture/    ← deep-dive design docs
│   └── runbooks/        ← operational playbooks
└── tools/
    ├── ci/              ← GitHub Actions workflow definitions
    └── scripts/         ← deploy / migration / codegen scripts
```

## Source of truth

- [`docs/roadmap.md`](docs/roadmap.md) — direction, decisions, phasing, open questions

If anything in this README disagrees with the roadmap, the roadmap wins.

## License

Proprietary. All rights reserved. Not for redistribution.
