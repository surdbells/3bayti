# Day 3 completion notes — 12 May 2026

## What shipped

`b8bf5f3` — M2.2.2: api-client routing layer + response normaliser
`c865bee` — M0-followup-fix: regenerate pnpm-lock.yaml for apps/mobile + apps/portal

## CI status at end of Day 3

| Workflow | Status | Notes |
|---|---|---|
| API — build & test | N/A (didn't trigger) | Day 3 didn't touch `apps/api`. Last green on `65ec3fe` (Day 2). |
| Packages — type-check | ✅ green | Confirms `@3bayti/api-client` TypeScript is clean |
| Web — build & deploy | ❌ red | Pre-existing failure: legacy product slug drifted |

## The Web CI failure

`.github/workflows/web.yml` step "Verify SSR output" expects
`dist/3bayti-web/browser/product/la26-2637/index.html` to exist
after build. It doesn't, because the vendor renamed product 2637 on
the legacy backend from `LA26` to `LA27`, so the slug regenerated
from `la26-2637` to `la27-2637`.

This is a **data drift** issue, not a code issue. It's caused by:

1. Hardcoded slug `la26-2637` in the CI script
2. apps/web's build prerendering against live legacy data
3. Vendor edit happening between yesterday and today

### Why I'm not fixing it now

1. **Out of scope for Day 3.** Day 3 was api-client work. The SSR
   prerender script lives in apps/web and is owned by Day 5.
2. **About to be replaced.** Day 5 of the 10-day plan rewrites
   apps/web's data source (legacy v2 → v3). The prerender script
   will be rewritten then. Fixing the slug now is throw-away.
3. **Non-blocking.** Day 4 only touches `apps/api`. The API workflow
   will run cleanly. apps/web's broken build doesn't block API deploy.
4. **The actual failure is a known class of problem.** Hardcoding any
   product slug in CI against a live database is fragile. The Day 5
   refactor should use a deterministic sentinel slug (e.g., one we
   own, that the legacy backend won't change).

### What to do on Day 5

Either:
- Replace `la26-2637` with the current slug `la27-2637` and accept
  it'll drift again, OR (better)
- Switch the SSR build to consume from v3 (which we control) and use
  a deterministic test product, OR (best)
- Drop the specific-product assertion entirely and assert only on
  generic shape ("at least 50 prerendered product pages exist").

The third option is what I'll propose on Day 5.

## Day 3 deliverables — recap

| # | Task | Status |
|---|---|---|
| 3.A | Inspect existing api-client scaffolding | ✅ done |
| 3.B | ENDPOINT_ROUTING expanded 9 → 51 entries | ✅ live |
| 3.C | URL resolver + shape hint via resolveConfig | ✅ live |
| 3.D | Response normaliser (v2/v3-envelope/raw → unified) | ✅ live |
| 3.E | openapi.yaml expanded 1 → 9 paths + 14 schemas | ✅ live |
| 3.F | Type generation | DEFERRED — manual interfaces in apps/web suffice for demo |
| 3.G | Smoke test wrapper | DEFERRED to Day 8 end-to-end testing |
| 3.H | Commit + push | ✅ b8bf5f3 + c865bee |

## Code shipped Day 3

- `packages/api-client/src/feature-flags.ts`: 51 endpoints, 433 lines
- `packages/api-client/src/response-normaliser.ts`: 100 lines, NEW
- `packages/api-client/src/client.ts`: rewritten, 140 lines
- `packages/api-client/src/index.ts`: rewritten, 50 lines
- `packages/api-contracts/openapi.yaml`: rewritten, 450 lines (was 63)
- `pnpm-lock.yaml`: regenerated, +16860/-2935 lines

Total: ~1700 lines added/changed in TypeScript + YAML + lockfile.

## Days remaining: 7

Day 4 starts when Sodiq says go. Day 4 is **the migration day** —
the make-or-break checkpoint of the 10-day plan.

Day 4 sub-phases:
- 4.A: Pre-flight verification (Postgres schema state, MySQL connectivity)
- 4.B: Migration scripts (categories, users, vendors, products, reviews)
- 4.C: Dry-run into bayti_v3_staging schema
- 4.D: Spot-check 50 random products
- 4.E: Live migration into bayti_v3
- 4.F: Bcrypt login spot-check (existing legacy user logs in to v3)
- 4.G: Commit + push migration scripts
