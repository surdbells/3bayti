# Local Build Verification — Mobile + Portal

**For:** Sodiq (do this once between Day 8 and Day 9, on your local workstation)
**Estimated time:** 5-10 minutes
**Why:** Day 6 converted mobile + portal from npm to pnpm workspace members. CI verifies a clean checkout builds, but your LOCAL workstation has stale node_modules from before the conversion. If anything's drifted, finding out NOW is better than finding out on demo day.

## What CI already verified

- `mobile.yml` and `portal.yml` workflows run on every push that touches the relevant paths
- Both currently green (verified at Phase 8.A)
- CI builds from a clean checkout, so it proves "anyone cloning the repo can build mobile + portal"

## What CI does NOT verify

- Your local `node_modules` matches what CI used
- Your local pnpm version matches CI's pnpm 9.15.0
- Your local Node version (CI uses Node 22)
- Capacitor/Ionic native tooling (mobile)

## Recommended verification

Run these in order. Stop at the first failure.

### Step 1 — Pull latest

```bash
cd /path/to/3bayti
git checkout main
git pull --rebase

# Confirm you're at the Day 8 head
git log --oneline -3
# Expected: top commit starts with "M2.2.8" (any Day 8 phase)
```

### Step 2 — Clean stale install state (one-time)

```bash
# Remove the stale node_modules dirs from before Day 6's pnpm conversion
rm -rf apps/mobile/node_modules apps/portal/node_modules

# Optionally also clean the root one if it feels weird
# rm -rf node_modules
```

### Step 3 — Fresh pnpm install

```bash
pnpm install
```

Expected output: "Done in Xs" with no errors. First run may take 3-5 minutes
as pnpm hydrates the store for the previously-npm-only apps. Subsequent
runs are incremental.

### Step 4 — Type-check both apps

```bash
pnpm --filter @3bayti/mobile type-check
pnpm --filter @3bayti/portal type-check
```

Both should exit 0 with no output. If either fails:
- Compare your output against the CI run of the same workflow (GitHub Actions
  page for that branch)
- Most likely cause: your local TypeScript version differs from CI's. Run
  `pnpm install` to sync, then retry.

### Step 5 — Build both apps

```bash
pnpm --filter @3bayti/mobile build
pnpm --filter @3bayti/portal build
```

Expected output for each: success message with bundle path printed.

Mobile builds to `apps/mobile/www/` or `apps/mobile/dist/app/browser/`.
Portal builds to `apps/portal/dist/abayti/browser/` (the Angular project
name inside portal/angular.json is `abayti`, not `portal` — pre-existing
artifact).

### Step 6 — Confirm bundles look real

```bash
# Mobile
ls -la apps/mobile/www/ 2>/dev/null || ls -la apps/mobile/dist/app/browser/

# Portal
ls -la apps/portal/dist/abayti/browser/
```

You should see `index.html`, polyfills.js, main.js, runtime.js, plus
asset directories. If `index.html` is missing, the build silently failed
even if exit code was 0.

## What to do if anything fails

1. **Take a screenshot of the error.**
2. **Compare against the same workflow's CI run.** If CI is green but local fails, the discrepancy is local.
3. **Common fixes:**
   - Outdated Node: install Node 22 via nvm or your version manager
   - Outdated pnpm: `corepack prepare pnpm@9.15.0 --activate`
   - Stale store: `rm -rf node_modules apps/*/node_modules && pnpm install`
4. **Don't push partial fixes the day before demo.** If something's broken locally but CI is green, the deployed app is fine — diagnose post-demo.

## If you skipped this step

Mobile and portal aren't on the demo path anyway (both still legacy v1).
Skipping this verification only matters if you plan to develop on them between
Day 8 and Day 10 — for the demo itself, CI green is sufficient.

But: do it once before Day 9 testing day, just so we know the workstation
is in a known-good state for any unexpected debugging.
