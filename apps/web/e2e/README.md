# apps/web — End-to-end tests

Playwright e2e tests covering the public-facing surfaces of `apps/web`.

## Running locally

```bash
# From repo root or apps/web — both work
pnpm --filter @3bayti/web test:e2e          # headless, all spec files

pnpm --filter @3bayti/web test:e2e:ui       # interactive UI mode — best
                                            # for writing new tests
pnpm --filter @3bayti/web test:e2e:headed   # see the browser run

pnpm --filter @3bayti/web test:e2e:report   # open the last HTML report
                                            # (after a failed run)

# Single spec file:
pnpm --filter @3bayti/web exec playwright test e2e/home.spec.ts

# Single test by name:
pnpm --filter @3bayti/web exec playwright test -g "displays product name"
```

The `webServer` config auto-starts `pnpm dev` on `http://localhost:4200`
if nothing's running there. With the dev server already running, tests
attach to the existing instance (faster iteration).

## Running against staging

```bash
PLAYWRIGHT_BASE_URL=https://staging.3bayti.ae pnpm --filter @3bayti/web test:e2e
```

This skips the local dev server and runs every spec against the live
staging deployment. Use this to verify a deploy before merging.

## Layout

```
apps/web/e2e/
├── home.spec.ts             # / route
├── categories-index.spec.ts # /category
├── category-detail.spec.ts  # /category/:slug (slug picked from sitemap)
├── product-detail.spec.ts   # /product/:slug (slug picked from sitemap)
├── responsive.spec.ts       # mobile/tablet/desktop viewport gates
└── utils/                   # shared helpers (added in M3.2.0-D)
```

## Conventions

- **Selectors:** prefer `getByRole`, `getByLabel`, `getByText` over CSS
  selectors. Use BEM class selectors (`.product-card__name`) for stable
  structural markers when role-based selectors aren't sufficient.
- **Editorial copy:** don't pin tests to specific marketing copy
  ("Premium Abayas..."). That breaks whenever marketing tweaks the
  hero. Use stable structural markers.
- **Network waits:** use `waitForLoadState('networkidle')` for
  data-driven pages. Avoid arbitrary `waitForTimeout(N)` — flaky.
- **Slugs:** category/product detail pages pick a real slug from
  `sitemap.xml` at test time. This survives backend slug schema changes.
- **JSON-LD:** parse the script tag JSON to assert on `@type` rather
  than text matching. Survives JSON formatting changes.

## Adding a new spec

```typescript
import { test, expect } from '@playwright/test';

test.describe('My new feature', () => {
  test('does the thing', async ({ page }) => {
    await page.goto('/my-route');
    await page.waitForLoadState('networkidle');

    await expect(page.getByRole('heading', { name: /Expected/i })).toBeVisible();
  });
});
```

Save to `apps/web/e2e/<feature>.spec.ts`. Playwright picks up any
`.spec.ts` under `e2e/` automatically.

## Debugging a failure

1. **Open the HTML report:** `pnpm --filter @3bayti/web test:e2e:report`
2. **Re-run in headed mode** for the failing spec to watch:
   `pnpm --filter @3bayti/web test:e2e:headed -g "<test name>"`
3. **Use UI mode** for step-by-step:
   `pnpm --filter @3bayti/web test:e2e:ui`
4. **Check the trace** — failed tests in CI upload `trace.zip` to
   the playwright-report artifact. Drop it on https://trace.playwright.dev
   for a visual timeline.

## CI behaviour

- Tests run as part of `.github/workflows/web.yml` after the build step
  (see M3.2.0-F).
- Mobile e2e are local-only by default; see `apps/mobile/e2e/README.md`.
- Visual regression is handled separately via Chromatic (see
  `docs/runbooks/m3.2/chromatic-runbook.md` after M3.2.0-C ships).
- Accessibility violations are caught via `@axe-core/playwright`; see
  `docs/runbooks/m3.2/a11y-guide.md` after M3.2.0-D ships.

## When tests should change

- ✅ You intentionally changed UX — update the assertion
- ✅ A selector class was renamed — update the selector
- ✅ A new visible behaviour was added — add a new test
- ❌ The test is "flaky" — fix the timing, don't disable. See "Debugging".
- ❌ The test fails on CI but passes locally — it's a real bug; CI is
   slower + more constrained, so a CI-only failure usually indicates
   a race condition that exists everywhere.
