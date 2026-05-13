# Day 6 Completion — pnpm workspace infrastructure for mobile + portal

**Date:** 13 May 2026
**Status:** ✅ COMPLETE — apps/mobile and apps/portal are first-class pnpm workspace members with green CI
**Commits:** 8 (4 main phases + 1 CI fix + 1 cleanup + 1 doc commit + 1 untracked-files rescue)

## What this day was about

When Day 6 started, the original plan was to do the mobile catalog +
auth flip to v3. Pre-flight audit revealed:
- apps/mobile had its own `package-lock.json` and `node_modules`
  (npm-managed), inconsistent with the rest of the monorepo's pnpm
  setup
- apps/mobile's npm name was unscoped (`3bayti`); apps/portal's was
  `abayti`; only apps/web matched the `@3bayti/*` convention
- Neither apps/mobile nor apps/portal had any CI — they'd never been
  built on a clean checkout
- NetworkService call sites in mobile were 37 files (vs the plan's
  estimate of 10-15)

Doing the actual catalog/auth flip on this foundation would have
created brittle, hard-to-debug commits. Day 6 was re-scoped to
infrastructure-only; mobile flip moves to Day 7, portal flip to Day 8,
testing+cutover compresses to Day 9.

## What shipped on Day 6

### 1. Pnpm workspace consolidation

| Before | After |
|---|---|
| apps/mobile: name `"3bayti"`, separate npm lockfile, npm node_modules | apps/mobile: name `"@3bayti/mobile"`, no per-app lockfile, root pnpm-managed |
| apps/portal: name `"abayti"`, separate npm lockfile | apps/portal: name `"@3bayti/portal"`, no per-app lockfile |
| `pnpm install` at root partially conflicted with per-app installs | `pnpm install` at root is the single source of truth |

### 2. CI for mobile and portal

Two new workflows:
- `.github/workflows/mobile.yml` — type-check + production build (no native, no deploy)
- `.github/workflows/portal.yml` — type-check + production build

Both follow the same pattern as web.yml minus the wrangler deploy
step. Paths filters trigger only on changes to the relevant app +
its shared package dependencies.

### 3. CI surfaced a critical pre-existing bug

The first time mobile + portal got CI runs, both immediately failed
at type-check:

```
Mobile:  Cannot find module './vendor/store-dashboard/...'
Portal:  Cannot find module './vendor/user/...' (and 13 more)
```

Root cause: `.gitignore` contained `vendor/` and `**/vendor/` — added
to exclude Composer's PHP dependency dir for `apps/api/vendor/`. The
glob was too broad. It also matched `apps/mobile/src/app/vendor/`
and `apps/portal/src/app/vendor/` — which are source directories
containing real Angular components.

**57 source files (8,370 LOC) were silently excluded from git for
weeks.** Mobile + portal built fine on Sodiq's machine because the
files existed locally, but no clean checkout could build them. This
would have failed catastrophically on the deploy server before the
client demo.

Fix:
- Narrowed `.gitignore` to `apps/api/vendor/` only
- Added the 57 source files to git tracking

After the rescue, both CIs went green on the first iteration.

### 4. ApiClientService cleanup (Day 5 carry-over)

The deprecated `apps/web/src/app/core/api/api-client.service.ts` was
deleted. Zero apps/web files import it (Phase 5.D refactored them
all to RoutedHttpClient). Three doc-comment references that mentioned
the name were left in place — they're informational.

## Architecture decisions locked

### 1. pnpm-lock.yaml is the only lockfile

Going forward, the rule is unambiguous: `pnpm install` at the repo
root is the only way to install dependencies for any app. Running
`npm install` inside `apps/mobile` or `apps/portal` would create
drift and is now prevented by .gitignore.

### 2. CI builds web bundles only — no native, no deploy (yet)

For the demo, mobile's native build happens on Sodiq's machine via
`ionic capacitor sync`. Portal hosting is TBD (could be Cloudflare
Pages like web, or static at portal.3bayti.ae). CI doesn't try to
deploy either; it only verifies that a clean checkout can produce a
working bundle.

If portal hosting locks before the demo, we'll add a wrangler-style
deploy step to portal.yml. Mobile likely never gets a CI deploy
since native builds need macOS runners.

### 3. Test running deferred

Both apps have `ng test` scripts (karma + jasmine), but karma in CI
requires a headless Chrome image and additional config. For now,
CI runs type-check + build only. Tests can be added later when the
codebase stabilizes.

### 4. Angular version skew is tolerated

Web: 21.2. Mobile: 21.1. Portal: 19.2.

pnpm's strict hoisting places each version in a separate `.pnpm/`
path, so they don't conflict at the dependency level. Portal will
need an Angular major upgrade post-demo, but that's a M3 task.

## Bugs caught + fixed during Day 6

### Bug 1: `.gitignore` `vendor/` overreach
- **Symptom:** Mobile + portal CI failed with "Cannot find module" for 15 components.
- **Cause:** PHP-convention `vendor/` exclusion accidentally matched Angular source dirs in mobile + portal.
- **Fix:** Narrow exclusion to `apps/api/vendor/` only.
- **Impact:** 57 source files (8,370 LOC) recovered into git tracking.
- **Commit:** 9cf29eb (rescue), refined in this cleanup commit

### Bug 2: Inadvertent commit message vs content mismatch
- **Symptom:** Commits 05badd1 and 9cf29eb described `.gitignore` changes in their messages but didn't actually stage them.
- **Cause:** `git rm`-only and `git add <specific paths>` don't pick up unrelated working-tree edits.
- **Lesson:** When the commit message says ".gitignore updated", always confirm with `git diff --cached .gitignore` before committing.
- **Fix:** This cleanup commit consolidates the .gitignore fix into a single tracked change.

## Known limitations carried forward

### Functional
1. **Mobile + portal have no test runs in CI** — karma headless setup deferred.
2. **Portal has no deploy step in CI** — hosting decision pending.
3. **Mobile and portal have NO route-level api-client integration yet** — that's Day 7 (mobile) and Day 8 (portal).

### Cosmetic
4. **Portal's Angular project name is still `abayti`** (not `portal`) inside `angular.json`. Renaming would require updating any `ng build abayti` scripts. Deferred to consolidation in M3.

### Followups for cleanup
5. **Sodiq must delete `apps/mobile/node_modules` and `apps/portal/node_modules` locally** before next `pnpm install`. The CI runs on fresh checkouts so this doesn't affect CI; it's a workstation concern only.
6. **The 57 rescued components have NOT been audited for type errors** — CI passes mean tsc accepted them as a corpus, but there may be runtime issues we don't know about. Future work.

## Operational notes for Sodiq

### After pulling Day 6 commits

```bash
cd /path/to/3bayti
git pull --rebase
rm -rf apps/mobile/node_modules apps/portal/node_modules
pnpm install
```

First `pnpm install` may take 3-5 minutes as pnpm hydrates the store
for the previously-npm-only apps. Subsequent runs are incremental.

### Verifying Mobile / Portal CI

Push any change touching `apps/mobile/**` or `apps/portal/**` (or
their shared package deps) and watch GitHub Actions. Both workflows
type-check + build the affected app and verify the bundle output.

### Running Mobile / Portal locally

```bash
# Mobile (Ionic dev server)
pnpm --filter @3bayti/mobile start
# Visit http://localhost:8100

# Portal (Angular dev server)
pnpm --filter @3bayti/portal start
# Visit http://localhost:4200
```

## What's next: Day 7

Day 7 is the mobile catalog + auth flip (originally Day 6's plan).
Now properly unblocked by Day 6's infrastructure:
- @3bayti/api-client can be added to apps/mobile as a workspace dep
- Mobile CI catches any breakage from the refactor

Pre-Day-7 work for the morning:
- Audit specifically which 8-10 mobile files do catalog reads vs. cart/checkout (which stay on legacy v1)
- Decide: build a Capacitor-native NetworkAdapter that wraps api-client, or use Angular HttpClient directly (mobile already injects HttpClient via @ionic/angular)
- Verify v3's mobile-specific endpoints work via Capacitor HTTP plugin (CORS, content-type) — quick curl from a mobile-like User-Agent
