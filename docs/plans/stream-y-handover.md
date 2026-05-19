# Stream Y Handover Document

**Created:** Monday, May 18, 2026
**Last commit:** `c4a9039` (M3.2.X.12-I — Closure)
**Repository:** https://github.com/surdbells/3bayti.git
**Working dir:** `/home/claude/work/3bayti`
**Working tree:** clean · 1380 tests / 4663 assertions · phpstan 60 baseline

This document is everything a fresh conversation needs to plan and execute **Stream Y** with full continuity from Stream X Pass 2. Read this first, then begin the Stream Y planning turn.

---

## 1. Operator preferences (carried forward — do not deviate)

These are the user's working rules from `<userPreferences>`. Internalize them before any planning or execution.

### Core principles
- **Never make assumptions.** Ask for clarification on any unclear requirement, logic, workflow, or edge case before proceeding.
- **Every task begins with a structured Implementation Plan** before any development.
- **Always request approval** after presenting the plan, before implementation.
- **Break implementations into clearly defined phases and sub-phases** to reduce complexity, improve quality control, and avoid hitting tool/context limits.
- **Once a phase is approved, continue implementing approved sub-phases as a single continuous run** until completion or tool/context limits are reached.

### Development standards
- **No stubs, fakes, placeholders, or incomplete implementations.**
- All features must be **fully functional, production-ready, and properly integrated**.
- Prioritize **scalability, maintainability, performance, and clean architecture**.
- Follow best practices, proper structure, reusable patterns, and modular design.
- Always consider future product growth, multi-tenant readiness, extensibility, high-concurrency usage.

### UI/UX (when frontend work happens)
- Premium-quality UI and UX, modern and intuitive, responsive, accessible, visually polished.
- Consistency across components, layouts, typography, spacing, interactions.
- Smooth user flows, usability, professional product presentation.

### Implementation workflow
1. Analyze requirements
2. Ask clarifying questions if needed
3. Create a phased Implementation Plan
4. Request approval before coding
5. Implement phase-by-phase
6. Validate functionality after each phase
7. Ensure production readiness before marking complete

### Quality requirements
- Clean, optimized, well-documented code.
- Avoid technical debt.
- Proper validation, error handling, logging, security considerations.
- Compatibility, responsiveness, performance optimization across platforms/devices.
- **Never sacrifice code quality for speed.**

### Expected behaviour
- Think like a senior software architect and product engineer.
- Long-term product stability over quick fixes.
- Proactively identify architectural, scalability, security, UX issues early.
- Consistency with existing project conventions unless improvements are justified.
- **Always run a stage-gate review against the project plan** — find the plan document and re-read it.
- **Always display a Status snapshot of phases / sub-phases against the implementation plan after every commit.**
- **Always create comprehensive handover docs** when chat becomes long.
- **Continuous quality discipline, zero regressions, no compromises on code quality.**

---

## 2. Repository state

### Branch / HEAD
- **Branch:** `main`
- **HEAD:** `c4a9039` — M3.2.X.12-I (closure of last X-phase)
- **Working tree:** clean
- **Total commits in repo:** 357
- **All Stream X Pass 2 commits pushed to origin**

### Git author / PAT
- **Author:** Sodiq Bello `<surdbells@gmail.com>`
- **PAT** (for git push from container): `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`
- Git remote is preconfigured; `git push origin main` works without re-auth.

### Quality gate baselines (do NOT regress)
- **phpunit:** **1380 tests / 4663 assertions** — must stay green
- **phpstan:** **60 errors** (implicit baseline) — must stay at 60 or below across every commit
- **Lint:** all PHP files clean; verify with `php -l <file>` before commit

### Toolchain in container
Fresh containers come with PHP 8.3.6 + composer typically pre-installed. If missing:
```bash
apt-get install -y php-cli php-mbstring php-xml php-curl php-zip php-bcmath php-redis unzip
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer
cd /home/claude/work/3bayti/apps/api && \
  COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress --no-scripts \
  --ignore-platform-req=ext-redis
```

### Standard commands
```bash
# Test suite
cd apps/api && vendor/bin/phpunit

# Phpstan (must end with "Found 60 errors")
cd apps/api && vendor/bin/phpstan analyse src --level=6 --memory-limit=512M --no-progress

# Single test file
cd apps/api && vendor/bin/phpunit tests/path/to/SomeTest.php

# Lint single file
php -l <file>.php
```

---

## 3. Stream X Pass 2 — what just shipped (8/8 complete)

Each phase below is **closed** with code + tests + master plan ledger row + operator playbook section + closure runbook.

### Summary table

| Phase | Description | Final commit | Tests Δ |
|---|---|---|---|
| **X.18** | Returns request flow | (earlier session) | — |
| **X.10** | Faceted search backend | (earlier session) | — |
| **X.14** | Vendor performance metrics | (earlier session) | — |
| **X.17** | Admin order timeline | (earlier session) | — |
| **X.11** | Abandoned cart recovery emails | `f5d9592` | (earlier) |
| **X.15** | Multi-currency display | `3cc0b6f` | +64 / +200 |
| **X.13** | Vendor analytics dashboard | `17cc7d7` | +39 / +168 |
| **X.12** | Recommendations engine | `c4a9039` | +65 / +240 |

### What each phase delivered

**X.18 — Returns request flow:** Customer-initiated return requests with admin approval/denial workflow; OrderReturnRequest entity; vendor receipt confirmation; refund disbursement path.

**X.10 — Faceted search backend:** FacetAggregator service computing facets (price ranges, vendors, categories, attributes) with SET LOCAL statement_timeout + slow_response observability; integrated into product list endpoint.

**X.14 — Vendor performance metrics:** 4 performance rates (fulfillment, cancellation, return, dispute) computed via VendorMetricsCalculator; vendor self-serve + admin endpoints with audit trail; non-final services for testability.

**X.17 — Admin order timeline:** OrderTimelineBuilder service stitching order events, fulfilment transitions, returns, refunds into a single chronological view; admin-only audited endpoint.

**X.11 — Abandoned cart recovery emails:** Cron command sending one reminder per abandoned cart; opt-out tokens with per-recipient JWT; SKIPPED log rows as persistent suppression markers; idempotent across runs.

**X.15 — Multi-currency display:** fx_rates table + 5 seed rates (AED→AED/USD/EUR/SAR/GBP); CurrencyConversionService with bcmath HALF_UP + identity short-circuit + sticky last-known with 48h staleness warning; CurrencyContextMiddleware; ProductSerializer integration; admin upsert endpoints with audit. **Display-only — settlement remains 100% AED.**

**X.13 — Vendor analytics dashboard:** VendorAnalyticsCalculator with 6 SQL queries (totals + daily time-series via generate_series + top-N units + top-N revenue + customer mix via CTE classification + status mix via COUNT FILTER); vendor self-serve + admin audited endpoints; no DB changes (pure read-side aggregation).

**X.12 — Recommendations engine:** product_recommendations denormalized table + 2 calculator services (co-purchase + same-category fallback) + BuildRecommendationsCommand nightly cron + RecommendationsService read-side with popular fallback + 3 HTTP endpoints (per-product + personalized + admin explain).

### Reference documents (read these to understand any prior phase deeply)

- `docs/plans/m3.2-master-plan.md` — master plan with one ledger row per phase
- `docs/runbooks/m3.2/m3.2.x.NN-completion.md` — closure runbook per phase (10/11/12/13/14/15/17/18 all exist)
- `docs/runbooks/m3.2/operator-playbook.md` — staging smoke-test sequences §2.A through §2.U + production execution §3
- `docs/roadmap.md` — high-level product roadmap

---

## 4. Architectural patterns catalog (cumulative across Stream X Pass 2)

These patterns are **locked** and should be reused (or referenced when establishing new ones).

### NEW patterns introduced in Stream X Pass 2

| Pattern | First introduced | Reusable for |
|---|---|---|
| Persistent suppression marker via SKIPPED log rows | X.11-E | Any cron that needs to remember "tried + skipped" without a separate suppression table |
| Per-recipient JWT for opt-out actions with distinct action claim | X.11-E | Magic-link unsubscribe, single-use action tokens, email confirmation flows |
| Opaque-on-failure verify() for security-sensitive endpoints | X.11-E / X.11-G | Any token endpoint where leaking validity details would enable enumeration |
| HALF_UP via bias-add-then-truncate bcmath trick | X.15-C / X.13-B | Any decimal-string money math needing standard rounding |
| Fluent self-returning serializer configuration | X.15-E | Per-request serializer config (locale, audience scope, currency context) |
| Skeleton-first with locked envelope contract | X.13-A | Multi-query aggregator services where response shape is the contract |
| generate_series with LEFT JOIN for hole-filled time-series | X.13-B | Any dashboard surface needing daily/weekly buckets without gaps |
| CTE-based two-pass classification queries | X.13-D | "Is X new vs returning in window" style classifications |
| Parallel calculator services sharing public API shape | X.12-C/D | Multiple algorithm sources merging into one output |
| Bulk graph computation via single CTE-based ROW_NUMBER() query | X.12-C | Per-group top-N selection without correlated subqueries |
| Cron-driven denormalized index with truncate + bulk insert in single transaction | X.12-E | Cache-style rebuild patterns where partial-state risk must be zero |
| Setter-level invariant enforcement with SQL defense in depth | X.12-B/D | Domain invariants that must hold even on direct DB writes |
| Admin debug "explain" endpoint pattern with grouped-by-source breakdown | X.12-G | Any algorithm-transparency surface (recommendations, search rankings, fraud scores) |
| Sequenced executeQuery mock dispatch by call-index | X.12-G tests | Testing multi-query service paths in unit tests |

### Patterns firmly locked by repetition (6+ instances each)

| Pattern | Instances |
|---|---|
| **Drop `final` from services that need PHPUnit doubles** | X.10-C / X.17-C / X.11-F / X.13-A / X.15-E / X.12-C/D/F |
| **Real-service-with-fake-deps integration test for observability** | X.14-E / X.17-E / X.11-H / X.15-G / X.13-F / X.12-H |

### Pre-existing patterns reused throughout

- Raw DBAL ParameterType with placeholder spreading for IN clauses
- PSR-3 timing observability with slow_response warning thresholds tuned per-service
- SET LOCAL statement_timeout with try/catch fail-soft for non-PostgreSQL test setups
- SQL identifier whitelist via `match()` — never string-interp ORDER BY columns
- INNER JOIN to display fields in aggregate queries (avoid N+1 hydration)
- Tiebreaker on monotonic column for deterministic ordering
- Multi-store routing with disambiguation 422 (VENDOR_AMBIGUOUS with available_vendor_ids)
- Audit `recordView` with structured context for read-side admin endpoints
- Read-only endpoint clamping rather than 400'ing on bad params
- Opaque 404 for cross-tenant lookups (no enumeration leak)
- AuditEmitter snapshot strategy per entity type (Brand, Vendor, Category, PromoCode, FxRate)
- Constructor-injected optional service parameters (backward-compatible additive features)
- Defensive type-checking on PSR-7 attributes before use

---

## 5. Operator follow-up backlog (39 items across Stream X Pass 2)

These are explicitly deferred items documented in operator-playbook.md §2.A-§2.U. They may inform Stream Y theme selection.

### From X.11 (Abandoned cart recovery)
24. Multi-touch reminder sequence (currently 1 reminder per cart)
25. Per-template opt-out granularity (currently single marketing flag)
26. Cart abandonment dashboard for ops

### From X.15 (Multi-currency display)
27. Paid FX rates auto-refresh (Open Exchange Rates / Currencylayer integration)
28. Expand currency list beyond v1's 5 (GCC + tourist origins)
29. Bulk CSV upload for admin rate management
30. Per-request Redis cache for findAllRates
31. Notification + alerting on fx_rate.stale warnings

### From X.13 (Vendor analytics dashboard)
32. Explicit from/to window param (?from=YYYY-MM-DD&to=YYYY-MM-DD)
33. Weekly bucketing for long windows (auto-pick daily vs weekly)
34. Cross-vendor admin "marketplace overview" aggregations
35. Redis cache for analytics queries (1h TTL)
36. Composite indexes on hot analytics queries

### From X.12 (Recommendations engine)
37. Paid ML model upgrade (collaborative filtering / matrix factorization)
38. Admin pin/unpin recommendations tuning (manual overrides)
39. Cron scheduler hardening + monitoring (lockfile + alerts)

### Earlier items (#1-23 from X.18 / X.10 / X.14 / X.17)
Documented in operator-playbook.md §2.A-§2.R. Topics span faceted search caching, vendor metrics composite indexes, order timeline event source expansion, return request webhook integrations, etc.

---

## 6. Stream Y candidate themes

Five themes to choose from. Operator picks priority + secondary themes at the planning turn.

### Theme A — Mobile-first hardening
**Pitch:** Strengthen the mobile commerce experience to match production-grade native marketplace apps.

**Phase candidates (6-7 phases × 5-7 days):**
- Y.M.1 — Push notification infrastructure (APNs + FCM, device token registration, notification preferences)
- Y.M.2 — Deep linking (universal links + Android app links + branch.io-style fallbacks)
- Y.M.3 — Offline cart with sync resolution (queue mutations offline, replay on reconnect, conflict resolution)
- Y.M.4 — Mobile-specific API surface (paginated cursor endpoints, image variants, low-bandwidth payload shapes)
- Y.M.5 — Biometric authentication (TouchID/FaceID via WebAuthn-style proof-of-possession)
- Y.M.6 — Mobile analytics + crash reporting wire-up

**Estimated:** 8-10 weeks.

**Best fit when:** mobile traffic is large fraction of total OR a mobile app launch is imminent OR retention metrics show mobile-specific drop-offs.

---

### Theme B — Marketplace growth surfaces
**Pitch:** Add the social + retention surfaces that drive marketplace network effects.

**Phase candidates (8-9 phases × 5-7 days):**
- Y.G.1 — Wishlist / save-for-later (with cross-device sync)
- Y.G.2 — Product review submission flow (photo upload, vendor reply, helpful/unhelpful voting)
- Y.G.3 — Q&A on product pages (customer-vendor or community Q&A)
- Y.G.4 — Social sharing endpoints (Open Graph metadata, share-link tracking, deep-link conversion)
- Y.G.5 — Referral program (unique codes per user, reward tracking, fraud guards)
- Y.G.6 — Follow vendors / new-product notifications
- Y.G.7 — Recently viewed history
- Y.G.8 — Personalized "you might also like" v2 (combines X.12 recommendations + browse history + wishlist signals)

**Estimated:** 10-12 weeks.

**Best fit when:** acquisition cost is high (need higher LTV) OR competitor marketplaces have these and yours doesn't OR vendor onboarding feedback asks for review/Q&A surfaces.

---

### Theme C — Vendor self-service expansion
**Pitch:** Reduce ops load by letting vendors manage more of their own surface area.

**Phase candidates (6-7 phases × 5-7 days):**
- Y.V.1 — Bulk product import/export (CSV upload with validation, error reporting, partial-success semantics)
- Y.V.2 — Bulk product price/inventory updates
- Y.V.3 — Vendor-created promotion codes (with admin approval gate or direct create)
- Y.V.4 — Payout reconciliation surface (matched payouts to delivered orders, dispute flag, payout history download)
- Y.V.5 — Vendor messaging center (vendor↔customer; vendor↔admin)
- Y.V.6 — Inventory management entity + low-stock alerts
- Y.V.7 — Vendor performance dashboard expansion (combines X.13 analytics + X.14 metrics + new operational KPIs)

**Estimated:** 8-10 weeks.

**Best fit when:** ops team is overloaded with vendor escalations OR vendor count is growing fast OR vendors complain about manual workflows.

---

### Theme D — Operational excellence
**Pitch:** Production-grade reliability, compliance, and automation.

**Phase candidates (7-8 phases × 5-7 days):**
- Y.O.1 — Multi-region read-replica wire-up (DB connection routing, write-through, fallback handling)
- Y.O.2 — GDPR/PDPL right-to-erasure flows (data deletion request, audit-log redaction, retention policy enforcement)
- Y.O.3 — Refund automation (auto-refund timer for unfulfilled orders, partial refund flows, dispute auto-escalation)
- Y.O.4 — Payment retry + dunning (failed payment recovery, payment method update prompts)
- Y.O.5 — Data warehouse export (nightly ETL to data lake / BigQuery / Snowflake for analytics team)
- Y.O.6 — Audit log retention + archival (move >2yr audit to cold storage)
- Y.O.7 — Incident response runbooks + chaos engineering scaffolding
- Y.O.8 — Rate limiting + abuse prevention (per-IP + per-user + per-vendor with override paths)

**Estimated:** 9-12 weeks.

**Best fit when:** approaching regulated launch (UAE PDPL effective) OR audit/security review imminent OR scaling incidents have happened.

---

### Theme E — ML readiness
**Pitch:** Build the data + experiment infrastructure so ML can ship next.

**Phase candidates (5-7 phases × 5-7 days):**
- Y.L.1 — Event capture (clickstream, search-no-results, add-to-cart, view-product) to event_log table or external stream
- Y.L.2 — Feature store (offline features in DB, online features in Redis, materialization cron)
- Y.L.3 — A/B testing infrastructure (experiment definitions, assignment, exposure logging, results querying)
- Y.L.4 — Recommendation v2 with ML scoring (operator follow-up #37 fully wired)
- Y.L.5 — Search ranking ML hooks (re-rank top-N from faceted search using event signals)
- Y.L.6 — Personalization signals API (expose user features to clients for adaptive UI)
- Y.L.7 — Offline evaluation harness (replay historical events against new models)

**Estimated:** 8-10 weeks.

**Best fit when:** ML team is forming or hired OR competitive pressure on personalization OR ops want richer analytics that today's tooling can't deliver.

---

## 7. Stream Y planning protocol

Follow this protocol in the fresh conversation, after reading this handover.

### Step 1: Confirm context
First message in fresh conversation should:
1. Acknowledge reading this handover
2. Run `cd /home/claude/work/3bayti && git log --oneline -1 && git status --short && cd apps/api && vendor/bin/phpunit 2>&1 | tail -1` to confirm clean state (expect HEAD `c4a9039`, clean tree, 1380 tests)
3. Ask the operator: **"Which Stream Y theme(s) shall we plan? Pick a primary theme (A-E above) and optionally up to two secondary themes."**

### Step 2: Theme-specific clarifying questions
After operator picks theme(s), ask **theme-specific Q-decisions** that scope what's in v1 of the stream. For example, for Theme A (Mobile-first):
- Q-Platforms: iOS + Android both? Or one first?
- Q-PushDelivery: APNs + FCM direct or via abstraction (OneSignal / SNS)?
- Q-OfflineCart: full mutation queue or read-only offline view in v1?
- Q-DeepLinkDomain: confirm domain ownership for universal links setup
- Q-AppSubmission: is there an existing iOS/Android app shell or are we API-only?

Aim for 8-12 Q-decisions per theme, similar to how each X-phase had 10 locked decisions.

### Step 3: Phased Implementation Plan
After Q-decisions locked, produce:
1. Stream Y overview — what's in scope, what's explicitly NOT
2. Phase breakdown — 6-10 phases (Y.X.1 through Y.X.N)
3. Per-phase: estimated sub-phases, locked decisions, DB changes if any, key risks
4. Total estimate in weeks
5. Dependency order between phases (e.g. Y.M.3 offline cart needs Y.M.1 push first)

### Step 4: Approval gate
**Stop and request approval.** Operator may approve as-is, request changes, or ask follow-up questions. Do not start coding before explicit approval.

### Step 5: Continuous execution
On approval, execute the same way Stream X Pass 2 ran:
- Each phase: Plan → A → B → ... → Closure (matches the X.NN-A through X.NN-I shape)
- Each sub-phase: commit + push to origin
- Each commit: full gates green (`vendor/bin/phpunit` + `vendor/bin/phpstan` + lint)
- Each commit: status snapshot after the commit ("X.NN-B shipped (commit hash). Tests: N+M / N+M. Phpstan: 60. Continuing X.NN-C.")
- Each completed phase: master plan ledger row + operator playbook §2.X smoke-test section + completion runbook

### Step 6: Handover when context budget approaches
Replicate this document's structure as the chat grows. Suggested triggers:
- After every 2 closed phases
- When current context fills past 70%

---

## 8. Operating rhythm checklist (for every sub-phase in Stream Y)

This is the rhythm Stream X Pass 2 followed without deviation. Do the same.

### Before starting a sub-phase
- [ ] Re-read the relevant section of the Stream Y plan document
- [ ] Confirm previous sub-phase committed + pushed (`git log --oneline -3`)
- [ ] Confirm clean working tree (`git status --short`)

### During implementation
- [ ] Production-ready code only — no stubs, placeholders, or TODOs left behind
- [ ] Reuse architectural patterns from §4 catalog where applicable
- [ ] Use locked Q-decisions consistently — do not re-decide mid-flight
- [ ] Add tests as you go; do not defer them to a "tests sub-phase"

### Before each commit
- [ ] `vendor/bin/phpunit` ends with `OK (... tests, ... assertions)` — no failures
- [ ] `vendor/bin/phpstan analyse src --level=6 --memory-limit=512M --no-progress` ends with `Found 60 errors` (or fewer; never more)
- [ ] `php -l <each new or modified PHP file>` reports `No syntax errors detected`
- [ ] If anything new went in `config/di.php`, verify autowire actually resolves (visual check or test triggers DI)
- [ ] If anything new went in `config/routes.php`, verify the route registers (visual check or test hits it)

### Commit message template
```
M<milestone>.<phase>-<subphase> — <one-line summary>

<paragraph explaining what shipped and why>

<file>: (NEW/MODIFIED)
  <description of changes>

<another file>: (NEW)
  <description>

Mid-flight catches
==================
1. <first catch + how resolved>
2. <second catch>
(omit section if no catches)

Quality gates:
  - phpunit: NEW / NEW (+DELTA / +DELTA vs prior)
  - phpstan: 60 errors (baseline preserved)
  - Lint clean

Architectural patterns reinforced:
  - <pattern 1>
  - <pattern 2>

Next: M<milestone>.<phase>-<next> — <next sub-phase>
```

### After each commit
- [ ] `git push origin main` succeeds
- [ ] Display status snapshot to operator including commit hash, tests delta, gate state
- [ ] If closing a phase: master plan ledger row + operator playbook section + completion runbook before declaring done

### Quality discipline rules
- **NEVER skip phpstan baseline check.** If it goes to 61, fix before commit.
- **NEVER use willReturn(true) on void mock methods.** Always check the mocked method signature.
- **NEVER edit unrelated files in the same commit** — keep changes scoped to the sub-phase.
- **NEVER remove operator-facing documentation** for refactors. Update it.
- **ALWAYS use bcmath for decimal money math.** Floats are forbidden for amounts.
- **ALWAYS use parameter binding in DBAL.** Never string-interp values into SQL.
- **ALWAYS whitelist via `match()` for SQL identifiers** (ORDER BY columns, table names).

---

## 9. Key files reference

For deeper context, fresh conversation may need to read these files.

### Code organization
```
apps/api/
├── src/
│   ├── Domain/          ← entities, repositories, domain services
│   │   ├── Audit/       ← AuditEmitter, AuditLog
│   │   ├── Cart/        ← Cart, CartItem, CartRepository, CartAbandonmentFinder
│   │   ├── Catalog/     ← Product, Vendor, Category, calculators (X.10/X.12/X.13/X.14)
│   │   ├── Currency/    ← Currency enum, FxRate, CurrencyConversionService
│   │   ├── Notification/← NotificationLog, EmailTemplate
│   │   ├── Order/       ← Order, OrderItem, OrderReturnRequest, OrderTimelineBuilder
│   │   ├── Promo/       ← PromoCode
│   │   └── User/        ← User, UserRepository
│   ├── Http/
│   │   ├── Controllers/ ← organized by audience: Admin/, Vendor/, Me/, Catalog/, Auth/, etc.
│   │   ├── Middleware/  ← AuthMiddleware, AdminAuthMiddleware, VendorAuthMiddleware, CurrencyContextMiddleware, etc.
│   │   ├── Serializers/ ← per-entity response shaping
│   │   └── Errors/      ← HttpException, ErrorCodes
│   ├── Console/         ← Symfony console commands (BuildRecommendationsCommand, SendAbandonedCartRemindersCommand, ReconcilePendingOrdersCommand)
│   ├── Payment/Noon/    ← payment gateway integration
│   ├── Notification/    ← email service (ZeptoMail HTTP mailer)
│   └── Infrastructure/  ← Auth/JwtService, etc.
├── tests/
│   ├── Domain/          ← unit tests mirroring src/Domain structure
│   ├── Http/Controllers/← HTTP-level tests via HttpTestCase
│   ├── Console/         ← cron command tests via CommandTester
│   └── Support/         ← InMemoryLogger and other test infra
├── migrations/          ← Doctrine migrations (latest: Version20260519000001)
└── config/
    ├── di.php           ← PHP-DI container bindings
    └── routes.php       ← Slim route definitions
```

### Frequently referenced entities + services
- **ProductSerializer** — `src/Http/Serializers/ProductSerializer.php` — main catalog response shape; X.15-E gave it optional CurrencyConversionService injection + `configureFromRequest()` fluent method
- **AuditEmitter** — `src/Domain/Audit/AuditEmitter.php` — `recordCreate`/`recordUpdate`/`recordDelete`/`recordView`/`recordOverride`; snapshot strategies per entity
- **HttpTestCase** — `tests/Http/HttpTestCase.php` — base for HTTP-level tests; `bind()`, `stubEm()`, `jsonRequest()`, `handle()`, `jsonBody()`, `makeUser()`
- **InMemoryLogger** — `tests/Support/InMemoryLogger.php` — capturing PSR-3 logger with `findByMessage()` helper

### Routes registered (high-level)
- Public catalog: `/v3/products`, `/v3/products/{slug}`, `/v3/products/{slug}/recommendations`, `/v3/products/facets`, `/v3/vendors/{slug}`, `/v3/categories`, `/v3/styles`
- Auth: `/v3/auth/login`, `/v3/auth/register`, `/v3/auth/refresh`, `/v3/auth/logout`
- Customer self-serve: `/v3/me/profile`, `/v3/me/addresses`, `/v3/me/orders`, `/v3/me/recommendations`, `/v3/cart`, `/v3/checkout`
- Vendor self-serve: `/v3/vendor/analytics`, `/v3/vendor/metrics`, `/v3/vendor/orders/*`, `/v3/vendor/onboarding/*`
- Admin: `/v3/admin/vendors/*`, `/v3/admin/orders/*`, `/v3/admin/disputes/*`, `/v3/admin/categories/*`, `/v3/admin/fx-rates/*`, `/v3/admin/recommendations/{id}/explain`, etc.
- Webhooks: `/v3/webhooks/noon`, etc.

---

## 10. Recent session metrics

**Single sustained conversation** shipped 8 X-phases (X.18 → X.10 → X.14 → X.17 → X.11 → X.15 → X.13 → X.12) plus all closure work.

- Total commits: ~57 across 8 phases + sub-phases
- Test growth: +168 tests / +608 assertions
- Phpstan baseline: 60 errors preserved across every single commit
- Zero quality compromises: no stubs, no placeholders, every feature production-ready
- Documentation: every phase has plan + closure runbook + operator playbook entry
- Architectural patterns: 14 new patterns + 2 firmly locked by repetition

This is the bar for Stream Y. The fresh conversation should match this discipline.

---

## 11. Pre-flight checklist for fresh conversation

Before responding to "let's start Stream Y" in the fresh conversation, do:

- [ ] **Read this handover top to bottom** (no skimming)
- [ ] **Read** `docs/plans/m3.2-master-plan.md` (full master plan with all ledger rows)
- [ ] **Skim** `docs/runbooks/m3.2/operator-playbook.md` table of contents (§2.A-§2.U)
- [ ] **Verify state:** `git log --oneline -1` shows `c4a9039`; `git status --short` is empty; `vendor/bin/phpunit | tail -1` shows `OK (1380 tests, 4663 assertions)`
- [ ] **Verify gates:** `vendor/bin/phpstan analyse src --level=6 --memory-limit=512M --no-progress | tail -1` shows `Found 60 errors`
- [ ] **First message to operator:** acknowledge handover read, confirm clean state, ask which Stream Y theme(s)

---

## 12. Closing note

Stream X Pass 2 was an exceptional sustained execution: 8 phases shipped in a single conversation with zero quality compromises. The codebase is in production-ready shape with comprehensive operator runbooks for staged deployment.

Stream Y starts from a strong foundation. Pick a theme, lock decisions, plan in phases, execute continuously, document thoroughly. **Same rhythm, same discipline, same standards.**

The fresh conversation has everything it needs. Begin.

— Handover prepared at commit `c4a9039`, Monday, May 18, 2026.

---

## Appendix A — M3.2.Y.1 closed 2026-05-19

Stream Y has begun and the first major sub-phase (M3.2.Y.1 — Web Auth UI Build + Flip) is closed.

**Closure commit:** `b186a09` (Y.1-J) → followed by this commit (Y.1-K).
**Final test count:** 284 vitest across 22 files. phpunit unchanged at 1380/4663.
**Sub-phases delivered:** 11 (A through K). Detailed ledger in `docs/runbooks/m3.2/m3.2.y.1-completion.md`.

**What this means for fresh-conversation kickoff:**

If a fresh conversation picks up Stream Y now, the first step is NOT to plan Y.1 — Y.1 is done. The first step is to either:

1. **Run the Y.1 staging-smoke pass** (§2.V of operator-playbook.md) to validate the auth surface against the live API, then proceed to Y.2 plan time.

OR

2. **Skip directly to Y.2 plan time** if the operator wants to defer Y.1 staging-smoke to be batched with Y.2 closure (acceptable since FEATURE_AUTH_HEADER_CTA defaults to false — the auth surface ships invisibly until Y.2 flips it).

**Recommended Y.2 plan-time Q-matrix** (locked decisions to make at Y.2 plan):
- Q-CartPersistence: signed-in vs guest cart merge strategy?
- Q-CheckoutSteps: how many steps (address → delivery → payment → review, or fewer)?
- Q-PaymentMethod: just Noon for v1, or COD also enabled at launch?
- Q-OrderHistoryDepth: list-only OR list + per-order detail page (overlap with Z.1)?
- Q-CtaFlip: when exactly does FEATURE_AUTH_HEADER_CTA flip? (proposed: after Y.2-B cart page ships)

**Inherited from Y.1:** AuthService.currentUser signal, isAuthenticated signal, refresh interceptor, mapApiErrors helper, ToastService, FormField + PhoneInput + PasswordStrength primitives, i18n shape, RTL CSS pattern, BFF proxy for cookie-bound auth (Y.2 endpoints are Bearer-bound and don't need new BFF routes).

— Appendix added at Y.1-K closure, Tuesday, May 19, 2026.
