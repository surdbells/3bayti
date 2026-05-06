# `apps/api` — 3bayti backend

Slim 4 + Doctrine 3 + PostgreSQL 16. The new platform API. Replaces the legacy
PHP backend at `api.3bayti.ae` over the M0–M5 migration.

## Status

> **M0.3 — skeleton only.** Single endpoint (`GET /v3/health`). No database,
> no auth, no real domain logic yet. Real endpoints land in M1+ per the
> roadmap at `docs/roadmap.md`.

## Quick start

```bash
# From this directory
composer install

# Run the dev server on :8080
php -S localhost:8080 -t public/

# In another terminal
curl http://localhost:8080/v3/health
# {"status":"ok","service":"3bayti-api","version":"dev","timestamp":"..."}
```

## Tests

```bash
composer test
# Runs PHPUnit. The first test (HealthTest) confirms the app boots
# cleanly and /v3/health responds correctly. If this fails, nothing
# else will work.
```

## Layout

```
apps/api/
├── public/index.php           ← HTTP entry point (kept tiny)
├── src/
│   ├── Bootstrap.php          ← Slim app factory + DI wiring
│   ├── Domain/                ← entities + repositories per bounded context
│   │   ├── User/              (M1)
│   │   ├── Catalog/           (M2)
│   │   ├── Order/             (M3 — see §5 of roadmap)
│   │   ├── Payment/           (M3)
│   │   ├── Cart/              (M3)
│   │   ├── Vendor/            (M4)
│   │   ├── Admin/             (M4)
│   │   ├── Messaging/         (M3)
│   │   └── Common/
│   ├── Application/           ← use cases, command/query handlers
│   ├── Infrastructure/        ← adapters (Noon, MessageCentral, ZeptoMail, R2)
│   └── Http/
│       ├── Controllers/       ← Slim handlers
│       └── Middleware/        ← auth, CORS, logging, etc.
├── config/
│   ├── di.php                 ← PHP-DI container definitions
│   └── routes.php             ← Slim route registry
├── migrations/                ← Doctrine migrations (empty until M1)
├── tests/
│   ├── HealthTest.php
│   └── bootstrap.php
├── composer.json
├── phpunit.xml
└── .env.example               ← copy to .env, fill in values
```

## Environment

Copy `.env.example` to `.env` and fill in values for local development.

In production, environment variables come from DigitalOcean App Platform's
encrypted secret store — never commit a real `.env`.

## Stack

| Layer | Tool | Notes |
|---|---|---|
| Runtime | PHP 8.3+ | |
| Framework | Slim 4 | |
| ORM | Doctrine 3 + Migrations | |
| Database | PostgreSQL 16 | |
| Cache / sessions | Redis (predis) | |
| Queues | Symfony Messenger + Redis transport | |
| Auth | firebase/php-jwt | Properly used, unlike the legacy backend |
| Validation | symfony/validator | |
| DI | PHP-DI | |
| Email | ZeptoMail | |
| SMS | MessageCentral CPaaS | |
| Payments | Noon Payments | UAE-native |
| File storage | Flysystem (R2 in prod) | |
| OpenAPI | swagger-php | Generates `packages/api-contracts/openapi.yaml` |
| Tests | PHPUnit 11 | |
| Static analysis | PHPStan level 6 | |
| Code style | PSR-12 via PHP_CodeSniffer | |
