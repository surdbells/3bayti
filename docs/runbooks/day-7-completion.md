# Day 7 Completion — Web/portal polish + demo prep

**Date:** 13 May 2026
**Status:** ✅ COMPLETE — demo readiness materials shipped, two production bugs fixed, scope honestly scoped
**Commits:** 7 (one per phase B-H)

## What this day was about

Originally planned as the apps/mobile catalog + auth flip. Pre-flight
audit on Day 7 revealed:
- Mobile's NetworkService usage was 37 files × 123 invocations (vs the
  plan's estimate of 10-15)
- v3 only has 2 of mobile's 9 auth endpoints (login, register); the
  rest (UserReset, sendOTP, validateOTP, sendOOTP, UserValidate,
  EmailValidate, UserConfirm) are mobile-specific flows with no v3
  equivalent
- v3's `/v3/featured-vendors` returns 500 (endpoint not built)
- Mobile's `/customer/*` legacy endpoints return responses with
  custom shapes nothing in v3 mirrors

Doing the actual mobile flip on this foundation would have produced
brittle code touching 13 files of uncertain quality, with high risk
of bugs leaking into the demo. Day 7 was re-scoped to **defer mobile
to M3** and use the day for web/portal polish + demo prep instead.

Phased plan delivered:
- 7.A — De-risking sweep (audit-only, no commits)
- 7.B — Fix vendor #92 slug + document deferred entity cleanup
- 7.C — Demo runbook (242 LOC) — the script Sodiq runs during the demo
- 7.D — Architecture diagram (180 LOC ASCII)
- 7.E — Status one-pager (done vs deferred)
- 7.F — Enable noPropertyAccessFromIndexSignature in api-client
- 7.G — Strip /designer/* from sitemap until route exists
- 7.H — This doc

## What shipped on Day 7

### Demo readiness materials

`docs/runbooks/demo-script.md` — single document Sodiq has open during
the demo. Contains:
- Pre-flight checklist (30 min and 5 min before)
- 15-minute demo script in five parts
- Recovery procedures for staging or API hiccups
- Seven pre-prepared audience questions with answers
- Known-issues table the audience might notice + honest framing

`docs/runbooks/architecture-diagram.md` — one-page ASCII visual of
M2 state. Used as a slide during demo Part 1.

`docs/runbooks/m2-rollout-status.md` — explicit done-vs-deferred list
with quantitative summary (25% endpoint coverage, 80% web traffic
coverage, 100% infrastructure).

### Production bug fixes (data quality)

`apps/api/bin/post-migration/fix-vendor-92-slug.sql` — fixes vendor #92
whose slug was `ether-amp-moon` (HTML entity bug from Day 4 migration).
The SQL fix sets it to `ether-and-moon`. Not auto-run; Sodiq executes
manually on production.

Broader audit found 67 of 100 vendors have HTML entities in their
descriptions, but they aren't rendered to users anywhere in the demo
surface, so cleanup is deferred to M3 with explicit documentation in
`docs/runbooks/deferred-vendor-description-cleanup.md`.

### Production bug fixes (SEO)

`apps/web/scripts/generate-sitemap.mjs` — removed 104 `/designer/<slug>`
URLs that all returned 404 (route not implemented). Sitemap shrinks
from 2,037 to 1,933 URLs. Smaller, more honest, no SEO trust hit.

### Code quality

`packages/api-client/tsconfig.json` — enabled `noPropertyAccessFromIndex-
Signature` so the package's own CI catches the dot-access pattern that
broke apps/web on Day 5. Forward-looking — prevents the bug from
recurring at the source.

## Architecture decisions locked

### 1. Mobile is M3 work, not M2 work

The decision was made formally on Day 7. Reasoning:
- Mobile's NetworkService touches 37 files; the plan said 10-15
- Most catalog `/customer/*` endpoints have no v3 equivalent; would
  need v3-side endpoint work first
- Mobile native builds require App Store/Play Store re-submission,
  which has its own cycle outside this rollout
- M2's win is the strangler-fig infrastructure proving it works for
  the highest-traffic surface (web); mobile flip is a separate effort

The architecture diagram + status doc reflect this. Demo Q&A in the
runbook prepares Sodiq for the "what about mobile?" question with a
clear honest answer.

### 2. Vendor descriptions stay encoded for the demo

67 vendors have HTML entities in descriptions. Decoding them properly
needs decisions (allow HTML? plain text? sanitization rules?) that
M3 will make. For the demo, descriptions aren't rendered anywhere
user-facing, so the encoding stays harmlessly in the DB.

### 3. /designer/* stays absent from sitemap (NOT removed from the
route system, because there is no route system to remove from)

Until apps/web ships /designer/:slug routes, sitemap doesn't list
them. Restoring is a 7-line edit to uncomment the vendor loop in
`generate-sitemap.mjs`. Self-documenting.

### 4. noPropertyAccessFromIndexSignature is enabled per-package,
not globally

Three other packages (api-contracts, design-tokens, shared-ui) extend
the same base.json. Turning the flag on globally would risk surfacing
unknown errors. Surgical enablement in api-client only — the package
that hit the issue — keeps blast radius small. Promotion to base.json
is a future audit task.

## Bugs caught + fixed during Day 7

### Bug 1: Vendor #92 slug `ether-amp-moon`
- **Symptom:** Slug visible in sitemap with HTML-entity-encoded
  fragment baked in
- **Cause:** Day 4 migration ran slug generator on encoded name
  `Ether &amp; Moon`
- **Fix:** SQL UPDATE in `fix-vendor-92-slug.sql`
- **Commit:** 59ce259

### Bug 2: 104 `/designer/<slug>` URLs in sitemap, all 404
- **Symptom:** Search engines would crawl 104 dead URLs per sitemap fetch
- **Cause:** Sitemap generator listed all vendors as if `/designer/:slug`
  routes existed; routes were Phase 2 work that never landed
- **Fix:** Removed the vendor loop in `generate-sitemap.mjs`; documented
  exactly how to restore when the routes do ship
- **Commit:** 6bc0353

### Bug 3 (latent, prevented going forward): TS4111 dot-access on
Record types in api-client
- **Symptom:** Would have re-emerged the next time a new contributor
  added Record-typed code to api-client
- **Cause:** Package's tsconfig didn't include the strict flag that
  downstream consumers expect
- **Fix:** Enabled `noPropertyAccessFromIndexSignature` in api-client's
  tsconfig
- **Commit:** 409d8e9

## Known limitations carried forward to Day 8+

### Demo-blocking? Nothing.

Every critical demo surface (home, category, product, sitemap, API)
verified working in the Phase 7.A walkthrough. No known issues that
would derail the demo.

### M3 backlog (not blocking)
1. Mobile catalog + auth flip (12-15 files)
2. Portal catalog + admin flip
3. v3 `/featured-vendors` endpoint
4. v3 `/categories/:slug` to include embedded products + meta
5. Vendor description HTML entity cleanup (67 rows)
6. Designer routes in apps/web
7. 36 users with email conflicts (reset campaign)
8. Reconcile-deletes pre-cutover script

### Pre-Day-8 work for Sodiq
1. Run the slug fix SQL on production:
   ```bash
   cd /www/wwwroot/3bayti/apps/api
   sudo -u postgres /www/server/pgsql/bin/psql -d bayti_v3 \
     -f bin/post-migration/fix-vendor-92-slug.sql
   ```

2. Verify the fix:
   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" \
     https://api-v3.3bayti.ae/v3/vendors/ether-and-moon
   # Expected: 200
   ```

3. Read through the demo runbook once. Adjust anything that's wrong
   for your speaking style.

4. Replace `REPLACE_BEFORE_DEMO` in the runbook with your test password
   (locally only; never committed).

## What's next: Day 8

Day 8 was originally the portal catalog + admin flip. Given mobile
was deferred today, Day 8 stays on the portal flip as planned. The
infrastructure is ready (Day 6 brought portal into pnpm + CI; portal
is at @3bayti/portal with green CI).

Pre-Day-8 work:
- Verify portal's NetworkService analog (or whatever it uses) — same
  audit pattern as Day 7 morning
- Check which `/v3/admin/*` endpoints exist (v3 may have built admin
  reads but not admin mutations)
- Decide: portal flip same scope as web (just catalog reads), or
  larger (admin CRUD too)?

If Day 8 surfaces the same scope-bigger-than-plan issue as Day 7 did
for mobile, defer portal to M3 the same way. Day 8 becomes another
polish + de-risking day. Day 9 is testing + cutover. Day 10 is demo.

We have buffer.
