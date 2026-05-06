# Local development setup

How to get the 3bayti monorepo running on your machine.

## Prerequisites

| Tool | Version | Why |
|---|---|---|
| Node.js | 22.x (LTS) | Pinned in `.nvmrc` |
| pnpm | 9.15+ | Workspace package manager |
| PHP | 8.3+ | `apps/api` runtime |
| Composer | 2.x | PHP dependency manager |
| Docker + Docker Compose | recent | Local Postgres + Redis |
| Git | recent | Obvious |

If you use `nvm`, run `nvm use` from the repo root and it'll pick up
the Node version from `.nvmrc`.

For PHP, install the matching extensions: `mbstring`, `pdo`,
`pdo_pgsql`, `openssl`, `curl`, `fileinfo`, `gd`. macOS:
`brew install php@8.3` and they're all included. Linux:
`apt install php8.3 php8.3-{cli,fpm,pgsql,mbstring,curl,gd,xml}`.

## First-time setup

```bash
# Clone (you've probably already done this)
git clone https://github.com/surdbells/3bayti.git
cd 3bayti

# Install Node-side deps for all workspaces
pnpm install

# Install PHP-side deps for the API
cd apps/api
composer install
cp .env.example .env
# Generate JWT_SECRET — paste into .env where it says JWT_SECRET=
php -r "echo bin2hex(random_bytes(64));" | tee /dev/null
cd ../..

# Spin up Postgres + Redis (in another terminal or backgrounded)
docker compose up -d

# Confirm containers came up
docker compose ps
# Should show 3bayti-postgres and 3bayti-redis as healthy
```

## Running the apps

### Web (Angular SSR)

```bash
pnpm --filter @3bayti/web dev
# → opens on http://localhost:4200
```

This is `ng serve` under the hood. SSR isn't active in dev mode; for
SSR-mode local dev:

```bash
pnpm --filter @3bayti/web build
pnpm --filter @3bayti/web preview:worker
# → workerd on http://localhost:8787
```

### API (Slim 4)

```bash
cd apps/api
php -S localhost:8080 -t public/
# → http://localhost:8080/v3/health responds with {"status":"ok",...}
```

PHP's built-in dev server is fine for local. Production uses
DigitalOcean App Platform with PHP-FPM behind it.

### Everything in parallel

From the repo root:

```bash
pnpm dev
# Turborepo runs `dev` on every workspace package that defines it,
# in parallel. Logs are interleaved (one TUI per app).
```

The api isn't currently in this list — its `dev` is the PHP server,
which Turborepo doesn't manage. Run `php -S` separately for now.

## Common tasks

### Run all type-checks

```bash
pnpm type-check
# Turborepo type-checks every package + apps/web in dependency order.
# Turborepo cache speeds up repeat runs.
```

### Run all tests

```bash
pnpm test
# Frontend: Karma + Jasmine where configured (apps/web)
# Backend: PHPUnit (apps/api)
```

### Build everything

```bash
pnpm build
# Builds all packages + apps/web. Outputs in each app's dist/.
```

### Codegen API contracts

```bash
pnpm codegen
# Regenerates packages/api-contracts/src/generated.ts from openapi.yaml.
# Currently a placeholder; real codegen runs once apps/api has
# swagger-php annotations (M1+).
```

### Database — run migrations

```bash
cd apps/api
composer migrate
# Runs Doctrine migrations against the local Postgres.
# Currently no migrations exist (M0.4 ships none); first migration
# lands in M1 (auth + users).
```

### Database — reset

```bash
docker compose down -v   # nuke volumes
docker compose up -d
cd apps/api
composer migrate
composer seed   # if a seed script exists (M1+)
```

## Editor setup

VS Code is the recommended editor. Suggested extensions:

- **Angular Language Service** — template autocomplete + errors
- **PHP Intelephense** — PHP language support
- **ESLint** — picks up workspace config
- **Prettier** — formatting (config TBD in M1+)
- **Even Better TOML / YAML** — config file syntax
- **Tailwind CSS IntelliSense** — for `apps/web`

PhpStorm / WebStorm work just as well; the project has no editor lock-in.

## Troubleshooting

**`pnpm install` fails on native modules**

Some packages (`sharp`, `esbuild`, `lmdb`) build native bindings on
install. If you hit a build error, install build essentials:

- macOS: `xcode-select --install`
- Ubuntu: `sudo apt install build-essential python3`
- Windows: install Build Tools for Visual Studio

**Postgres container exits immediately**

Check logs: `docker compose logs postgres`. Most common cause is a
stale `3bayti_pgdata` volume from a previous failed init. Reset:

```bash
docker compose down -v
docker compose up -d
```

**`apps/web` build prerender fails with API errors**

Build-time prerender hits `https://api.3bayti.ae/v2/*`. If the production
API is down or rate-limiting, prerender fails. Workaround: build with
`PRERENDER_SKIP=true` (not currently implemented; M2 adds graceful
degradation).

**`composer install` complains about missing PHP extensions**

Install the missing extension and retry. The list above (Prerequisites)
covers everything composer.json declares as required.

## Where stuff lives

```
3bayti/
├── apps/
│   ├── api/                 ← Slim 4 backend (M0.3+)
│   ├── web/                 ← Angular SSR (live; from M0.2)
│   ├── mobile/              ← lands in M4
│   └── portal/              ← lands in M4
├── packages/
│   ├── api-contracts/       ← OpenAPI spec + generated TS types
│   ├── api-client/          ← typed HTTP client
│   ├── design-tokens/       ← brand palette, shadows, type, spacing
│   ├── shared-ui/           ← cross-app components (web + portal)
│   ├── eslint-config-3bayti/
│   └── tsconfig-3bayti/
├── docs/
│   ├── roadmap.md           ← THE source of truth
│   └── runbooks/            ← this file lives here
└── tools/
    ├── ci/                  ← (placeholder; .github/workflows/ is the real home)
    └── scripts/             ← deploy / migration / codegen scripts
```
