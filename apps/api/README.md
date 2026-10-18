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

## SMS OTP providers and outage alerts

SMS login/verification codes go through `RoutingOtpProvider`, which tries the
providers switched on in the admin portal (Settings → OTP Providers) in
priority order and fails over to the next one on an error. MessageCentral is
always wired; Cequens only when `CEQUENS_API_KEY` + `CEQUENS_USERNAME` are set.

**Alert on provider problems.** When a provider refuses for an *account*
reason, the API emails the admin team and raises one Sentry issue. Without
this, the MessageCentral account ran out of credit twice in 2026 (Sept 18-20
and Oct 14-18, both "insufficient credits" / responseCode 508; the October one
blocked 120+ customers for 4 days) and was noticed only by chance. With it,
it's noticed in minutes instead of days:

| Problem (reason) | Detected from | What the email tells the owner to do |
| --- | --- | --- |
| out of credit (`insufficient_credit`) | MessageCentral `responseCode` 508 / "insufficient credits", "insufficient/low balance", HTTP 402 | top up the provider account (and switch on auto top-up / a low-balance alert) |
| SMS pricing not set up (`pricing_not_configured`) | "Pricing not found", "channel/route not enabled" | ask the provider to price SMS OTP for the affected country |
| credentials rejected (`credentials_rejected`) | the provider's auth step refused us (`unauthorized`), HTTP 401/403, MessageCentral 501 `INVALID_CUSTOMER_ID`, "account suspended" | check the account is active and the API credentials on the server |
| no provider (`no_provider`) | the router had no provider to try | check the provider settings / admin toggles |

Network errors, timeouts, 5xx, rate limiting and invalid phone numbers do
**not** alert (they're ordinary, already-visible Sentry errors). See
`OtpProviderFailureClassifier`.

**Pricing is per destination country.** MessageCentral prices SMS per
country. In production, "Pricing not found" was one customer's country
(7 events from 2 customers) while every other code went through. So a pricing
alert names the country ("customers with +973 (Bahrain) numbers can't get SMS
codes"), and only a code sent successfully to *that* country resolves it. The
router passes the monitor only the dial code, never the number.

- **Recipients:** `OTP_ALERT_EMAILS` (comma-separated), else
  `ADMIN_NOTIFICATION_EMAILS`. With neither, only Sentry is alerted.
- **Rate limit:** at most one email per provider (+ country, for pricing) +
  problem per `OTP_ALERT_COOLDOWN_SECONDS` (default 3600), claimed atomically
  in Redis so all workers send one email. "Customers blocked" and "a backup
  provider covered it" have separate cooldowns, so if the backup also fails
  the owner is told at once.
- **Sentry:** one issue per provider + problem (fingerprint
  `otp-provider-account/<provider>/<reason>`), level `fatal` when customers were
  blocked, `error` when a backup covered it. At most one event a minute per
  problem (each event is a synchronous HTTP call inside the customer's
  request); the event carries the running failure count and the outage start.
  The incident event has no user, no HTTP request (url/body) and no breadcrumbs.
- **The email** says, in plain language: which provider, what's wrong, what
  customers can't do (sign up, log in with a phone code, verify a phone, reset a
  password by SMS), whether a backup provider delivered the code, what to do,
  when it started, how many code requests failed, and the provider's reply
  (redacted). It never contains a customer's phone number, code, user id or any
  API key.
- **Recovery:** on the first successful send afterwards (to the same country,
  for pricing), one "SMS codes are working again" email (with the outage
  duration) and the alert state clears.
- **Cost on the OTP path:** alerting never changes an OTP result: every side
  effect is caught. It does run inside the customer's request. Only the one
  request that claims the cooldown sends the email. Each send is bounded by
  the mailer's 15 s timeout. After a network failure, or any failure taking
  3 s or more, the remaining recipients are skipped, and no new recipient is
  started after 15 s. So once per window, one customer's request can wait
  roughly one mail timeout.
- **Needs Redis (`REDIS_DSN`).** Without it, the API uses a per-request
  in-memory store. The monitor detects this (log `otp.alert.store_not_shared`)
  and falls back to a marker file in `var/cache`, which gives at most one email
  per cooldown per server. The same fallback applies when Redis is down; if
  `var/cache` isn't writable, it degrades to once per request. Sentry is then
  reported every time, and recovery emails are off.

**Test it** (sends a clearly marked `[TEST]` email now, ignoring the cooldown and
without touching the live alert state; every recipient is tried and reported):

```bash
php bin/console otp:test-alert                                   # out-of-credit alert
php bin/console otp:test-alert --reason=pricing_not_configured   # or credentials_rejected / no_provider
php bin/console otp:test-alert --recovery                        # the "working again" email
php bin/console otp:test-alert --to=you@example.com              # just to you
php bin/console otp:test-alert --with-sentry                     # also a [TEST] Sentry event (separate issue)
```

On the production server, run it from the API folder with the server's PHP,
after the deploy's `rm -rf var/cache/di/*`:

```bash
cd /www/wwwroot/3bayti/apps/api && /www/server/php/83/bin/php bin/console otp:test-alert
```

It prints one `OK` / `FAILED` line per recipient. Outside production the mailer
is `NullMailer` (logs only) and the command says so.

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
