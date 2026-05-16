# Accessibility Baseline — apps/web

**Phase:** M3.2.0-D
**Created:** May 16, 2026
**Locked decision:** Q2 = B (allowlist baseline, fix inline)

## Why this document exists

When M3.2.0-D enabled axe-core scanning across apps/web's 4 routes (home, categories index, category detail, product detail), any existing WCAG AA violations would have blocked M3.2.0 closure unless we either:

- **A:** Spent 2-5 days remediating before progressing (rejected per Q2)
- **B:** Allowlisted with documented remediation plan (chosen)
- **C:** Lowered the bar to WCAG A only (rejected — undermines quality stance)

This document is the running ledger of every allowlisted violation, its reason, its remediation phase, and its expiry date.

## How baseline was captured

The allowlist file `apps/web/e2e/utils/a11y-allowlist.ts` ships with an **empty array**. This is intentional:

1. The first CI run after M3.2.0-D merges will surface any violations as test failures
2. The operator/developer reviews each violation and decides:
   - **Fix immediately** in this PR (preferred for trivial fixes like missing alt text)
   - **Allowlist with remediation phase** (for design-token-level fixes or larger refactors)
3. The allowlist entries land in a subsequent commit BEFORE M3.2.0-D is considered complete

This pattern keeps the allowlist honest. A pre-populated allowlist would obscure what we're actually carrying as debt.

## Baseline capture procedure

When the next M3.2.0-D-aware run happens:

```bash
cd apps/web
pnpm test:e2e 2>&1 | tee a11y-baseline-capture.txt
```

For each test failure of the pattern `A11y violations found on <URL>`:

1. Extract rule IDs from the failure
2. For each rule, decide fix-now vs allowlist
3. If allowlisting, add to `a11y-allowlist.ts` with:
   - `rule` — exact axe rule ID
   - `urlPattern` — substring of `page.url()` that matches the failing pages
   - `reason` — why deferred (1-2 sentences)
   - `remediationPhase` — M3.2.X.N most likely to cover the underlying fix
   - `expiresAt` — 3 months from now by default; longer if justified

4. Re-run tests to confirm allowlist absorbs the violations
5. Commit the allowlist updates as the M3.2.0-D closure step

## Active allowlist entries

**Status:** None yet recorded. Baseline capture pending first CI run.

When entries land, they get a section here documenting:

```markdown
### color-contrast — /category filter pills

**Pages:** `/category`, `/category/:slug`
**Rule:** color-contrast
**Reason:** Filter pills use brand-500 (#B9935A) text on white, contrast 3.2:1.
            Fix requires design tokens revision affecting all filter pills.
**Remediation:** M3.2.X.7 (vendor lifecycle + UI polish phase)
**Expires:** 2026-08-16
**Added:** 2026-05-17 by Sodiq
```

## Allowlist expiry policy

Every allowlist entry MUST have an `expiresAt` date. Default: 3 months from creation.

After expiry:
- The allowlist filter STOPS suppressing the violation
- Tests fail again as if there were no allowlist
- This forces re-review: either fix now, or renew the allowlist entry with fresh justification

Renewals should be exceptional. If an entry has been renewed twice without progress, it indicates the remediation phase is being mis-scoped — escalate to plan-level discussion.

## Counts (updated each M3.2.X closure)

```
Last updated: 2026-05-17 (M3.2.0-D commit)

Allowlisted violations:        0
  By rule:
    color-contrast:            0
    label:                     0
    heading-order:             0
    image-alt:                 0
    other:                     0

  By urgency (impact):
    critical:                  0
    serious:                   0
    moderate:                  0
    minor:                     0

  Expiring within 30 days:     0
  Already expired:             0
```

When the allowlist is empty, M3.2.0-D is fully closed and apps/web has zero known a11y violations at WCAG AA.

## Reference: axe rule impact levels

- **critical** — Severely impacts most users (no keyboard access, no labels)
- **serious** — Impacts many users (low contrast, missing alt on informative images)
- **moderate** — Impacts some users (heading order, redundant landmarks)
- **minor** — Impacts few users (best-practice optimizations)

Prioritize remediation in this order: critical → serious → moderate → minor.

## Mobile baseline

Mobile a11y baseline is captured separately and reported in the M3.1.7 device-test pattern; not part of M3.2.0-D scope. Mobile e2e via Capacitor WebView does include axe scanning (added in `apps/mobile/e2e/utils/a11y.ts` parallel pattern), but full mobile a11y is operator-verified on real devices using OS screen readers (VoiceOver, TalkBack).
