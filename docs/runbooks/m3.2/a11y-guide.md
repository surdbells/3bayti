# Accessibility (a11y) Guide

**Standard:** WCAG 2.1 AA (we enforce 2.0 A + 2.0 AA + 2.1 A + 2.1 AA)
**Tool:** axe-core via @axe-core/playwright
**Phase:** M3.2.0-D
**Locked decision:** Q2 = B (allowlist baseline, fix inline as we ship)

## What WCAG AA means in practice

The 50ish rules that matter most in our context:

| Category | Examples |
|---|---|
| **Color contrast** | Body text 4.5:1 against background; large text 3:1; UI components 3:1 |
| **Keyboard** | Every interactive element reachable + activatable via keyboard alone |
| **Focus** | Visible focus indicator on every focusable element |
| **Forms** | Every input has a visible label OR aria-label OR aria-labelledby |
| **Images** | Every img has alt text (empty alt="" if purely decorative) |
| **Headings** | Logical hierarchy, no skipping levels (h1 → h2 → h3, not h1 → h4) |
| **Landmarks** | One `<main>`, navigation in `<nav>`, etc. |
| **ARIA** | Used correctly (right role, right state, no conflicting attributes) |
| **Language** | `<html lang="en">` (or `ar`) set; per-element lang for switches |
| **Page title** | Descriptive `<title>` per page |

What axe doesn't catch (still needs manual review):

- Screen reader announcement quality
- Cognitive load / readability
- Touch target size on mobile (44×44 minimum)
- Focus order matches visual order
- Captions on video / transcripts on audio

## How to fix common violations

### color-contrast

```scss
// ❌ #999 on white = 2.85:1 — fails AA for body text
.muted-text { color: #999; }

// ✅ #595959 on white = 7.04:1 — passes AAA
.muted-text { color: #595959; }
```

Use https://webaim.org/resources/contrastchecker/ to verify combinations. Our design tokens (`packages/design-tokens`) should all hit 4.5:1 minimum; flag exceptions to the design team.

### label

```html
<!-- ❌ -->
<input type="email" placeholder="Email">

<!-- ✅ -->
<label for="email">Email address</label>
<input id="email" type="email">

<!-- ✅ alternative (visually hidden label still announced) -->
<label for="email" class="sr-only">Email address</label>
<input id="email" type="email" placeholder="Email">

<!-- ✅ for Angular Material / Ionic where the framework wraps -->
<ion-item>
  <ion-label position="floating">Email</ion-label>
  <ion-input type="email" name="email"></ion-input>
</ion-item>
```

### heading-order

```html
<!-- ❌ -->
<h1>Page title</h1>
<h3>Section</h3>  <!-- skipped h2 -->

<!-- ✅ -->
<h1>Page title</h1>
<h2>Section</h2>
```

If you need visual styling that doesn't match heading semantics, use CSS:

```html
<h2 class="visual-h4">Section</h2>  <!-- styled like h4 but semantically h2 -->
```

### image-alt

```html
<!-- ❌ -->
<img src="hero.jpg">

<!-- ✅ informative image -->
<img src="hero.jpg" alt="Three abayas displayed on mannequins in a boutique window">

<!-- ✅ decorative image (next to text that says the same thing) -->
<img src="checkmark.svg" alt="" role="presentation">
```

### button-name

```html
<!-- ❌ icon-only button has no accessible name -->
<button><ax-icon name="close" /></button>

<!-- ✅ -->
<button aria-label="Close dialog"><ax-icon name="close" /></button>
```

### aria-roles, aria-required-attr, aria-allowed-attr

ARIA is dangerous. The first rule of ARIA: **don't use ARIA**. If the native element works, use it. Reach for ARIA only when you need to communicate semantic information that HTML can't express natively.

When you do use ARIA, axe enforces correctness:

```html
<!-- ❌ role="button" without tabindex (not focusable) -->
<div role="button" (click)="doThing()">Click me</div>

<!-- ✅ actual button -->
<button (click)="doThing()">Click me</button>

<!-- ✅ if you MUST use div: tabindex + keyboard handler -->
<div role="button" tabindex="0" (click)="doThing()" (keydown.enter)="doThing()" (keydown.space)="doThing()">
  Click me
</div>
```

## How to use the allowlist

When you encounter a baseline violation that won't be fixed in your current PR:

1. Add an entry to `apps/web/e2e/utils/a11y-allowlist.ts`:

```typescript
{
  rule: 'color-contrast',
  urlPattern: '/category',
  reason: 'Category filter pills use brand-500 (#B9935A) on white, contrast 3.2:1. Design tokens to be revised in M3.2.X audit phase.',
  remediationPhase: 'M3.2.X.7',
  expiresAt: '2026-08-16',  // 3 months from creation
}
```

2. Justify the entry in your PR description
3. Track the remediation as a TODO in the relevant remediation phase

When you FIX a violation, remove the corresponding allowlist entry in the same PR. The PR should also remove any code-level workarounds.

## When to add `excludeRules` per-test

Almost never. The allowlist mechanism is the right place. `excludeRules` is for one-off cases where:

- A third-party widget (Stripe, Google Maps embed) has known violations we can't control
- A specific test case is deliberately testing the error state (which itself has an a11y issue we'll fix in normal state)
- Document the reason inline:

```typescript
await expectNoA11yViolations(page, info, {
  excludeRules: ['color-contrast'],
  reason: 'Testing error-state banner; contrast fix coming in M3.2.X.5',
});
```

The reason annotates the Playwright test output.

## Running a11y checks locally

```bash
cd apps/web
pnpm test:e2e  # All specs run a11y at the end of primary load tests

# Single spec:
npx playwright test e2e/home.spec.ts

# UI mode (shows axe results inline):
pnpm test:e2e:ui
```

## Debugging a failure

When a test fails on a11y violations:

1. **Read the failure message** — it lists each violation with rule ID, severity, help URL, and the offending HTML
2. **Visit the help URL** — `https://dequeuniversity.com/rules/axe/...` has remediation steps with examples
3. **Use Chrome DevTools axe extension** — install https://chrome.google.com/webstore/detail/axe-devtools/lhdoppojpmngadmnindnejefpokejbdd, navigate to the failing page, run a scan, click the violation to see the exact node
4. **Fix and re-run** — `pnpm test:e2e -g "<test name>"`

## Files

- `apps/web/e2e/utils/a11y.ts` — `expectNoA11yViolations(page, info, opts?)` helper
- `apps/web/e2e/utils/a11y-allowlist.ts` — Baseline allowlist with expiry
- `docs/runbooks/m3.2/a11y-baseline.md` — Current baseline violations
- This guide
