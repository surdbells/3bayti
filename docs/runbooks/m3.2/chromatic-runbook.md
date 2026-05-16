# Chromatic Visual Regression — Runbook

**Phase:** M3.2.0-C
**Tool:** Chromatic (selected over Percy — see M3.2.0 implementation plan §1.3)
**Free tier:** 5,000 snapshots/month + unlimited collaborators
**Integration:** @chromatic-com/playwright archive-based capture

## What Chromatic does for us

Every PR captures visual snapshots at predetermined checkpoints inside our Playwright e2e tests. Chromatic compares each snapshot to the baseline (current `main`) and surfaces visual differences in a PR comment:

> ✅ Snapshot `home-page-default` — no changes
> ⚠️ Snapshot `product-detail-default` — 2 visual differences (review needed)

The reviewer clicks through to a side-by-side diff UI showing pixel-level changes (text moved, color shifted, image dimensions changed, etc.). Approve or reject; merge unblocked.

## Operator setup (one-time)

Locked decision Q1 = C means the code lands without blocking on this. When you have time:

1. Sign up at https://chromatic.com — GitHub OAuth login is easiest
2. Click "Add Project" → select `surdbells/3bayti`
3. Chromatic generates a project token. Copy it.
4. Go to `https://github.com/surdbells/3bayti/settings/secrets/actions`
5. New repository secret:
   - Name: `CHROMATIC_PROJECT_TOKEN`
   - Value: paste the token from step 3
6. Edit `apps/web/chromatic.config.json` — replace `"projectId": "TBD-set-via-chromatic-init-when-token-added"` with the project ID Chromatic gave you (visible in the project settings)
7. Run a manual baseline once locally:

   ```bash
   cd apps/web
   CHROMATIC_PROJECT_TOKEN=<token> pnpm chromatic
   ```

8. After verifying the baseline looks correct in Chromatic UI, remove `continue-on-error: true` from the Chromatic step in `.github/workflows/web.yml` (added during M3.2.0-F)

After step 8, Chromatic becomes a required PR gate.

## How to read a Chromatic PR comment

```
✅ Build passed!

📷 Captured 7 stories on Chrome
🆕 No new stories
🚨 Found 2 changes that need review

[Review changes in Chromatic →]
```

Click the link. Chromatic UI shows:

- **Left panel:** baseline (current main branch)
- **Right panel:** your PR's snapshot
- **Diff overlay:** pixels that changed, highlighted

For each change:

- **"Accept as baseline"** if the change is intentional (you updated the design, edited copy, added a section)
- **"Deny"** if the change is a regression (something broke that shouldn't have)

Denied changes block PR merge. Accepted changes update the baseline; subsequent PRs diff against the new baseline.

## How to add a snapshot to a new test

```typescript
import { test, expect } from '@playwright/test';
import { snapshot } from './utils/chromatic';

test('my new feature', async ({ page }, info) => {
  await page.goto('/my-feature');
  await page.waitForLoadState('networkidle');

  // ... functional assertions ...

  await snapshot(page, 'my-feature-default', info);
});
```

Snapshot names must be **unique within the test**. If you snapshot the same page in two states (e.g. before/after interaction), use different names:

```typescript
await snapshot(page, 'cart-empty', info);
// ... user adds item ...
await snapshot(page, 'cart-with-one-item', info);
```

## How to debug a false positive

Chromatic may flag visual differences that aren't real regressions:

1. **Animation differences** — the snapshot was captured mid-animation. Fix: `await page.waitForLoadState('networkidle')` plus a small `await page.waitForTimeout(200)` before snapshot.
2. **Font rendering** — same font renders slightly differently across CI runs. Fix: increase Chromatic's `diffThreshold` for that snapshot.
3. **Time-sensitive content** — "Recently viewed", carousels auto-rotating. Fix: mock data via `page.route` so the content is deterministic, or use Chromatic's `ignoreSelectors` to mask the dynamic region.
4. **Image lazy-load** — image not yet rendered when snapshot fires. Fix: scroll the image into view before snapshot or use `await page.evaluate(() => document.fonts.ready)` to wait for fonts + images.

## How to reset baselines after a major redesign

1. Land your redesign PR with Chromatic flagging all the changes
2. On the Chromatic UI, "Accept all changes" — this batch-updates every changed snapshot to the new baseline
3. Subsequent PRs diff against the new baseline

If the redesign is so substantial that "accept all" loses signal, you can delete the project's snapshot history in Chromatic settings → "Reset baseline". Use sparingly — you lose the ability to see what changed across the redesign.

## Budget management

Free tier: 5,000 snapshots/month. With our current 5 spec files × ~7 snapshots per spec × ~30 PRs/month, we'd use:

```
5 specs × 7 snapshots × 30 PRs = 1,050 snapshots/month
```

Well under budget. `onlyChanged: true` in `chromatic.config.json` (TurboSnap) only re-captures stories that actually changed based on git diff, reducing further.

Monitor budget at Chromatic dashboard → Settings → Plan. Set a Slack/email alert at 80% utilization.

## What NOT to snapshot

- **Pages that mostly contain user-generated content** (review lists, search results, dynamic recommendations) — diffs are mostly content noise
- **Loading skeletons** — captured before real content arrives; not representative
- **Error states from real errors** — not deterministic; mock the error condition instead
- **Modals/popovers without explicit open state** — race conditions cause flakes

## Files

- `apps/web/chromatic.config.json` — Chromatic CLI configuration
- `apps/web/e2e/utils/chromatic.ts` — Wrapper around `@chromatic-com/playwright`'s `takeSnapshot`
- `.github/workflows/web.yml` — CI integration (added in M3.2.0-F)
- This runbook
