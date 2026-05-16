# apps/mobile — End-to-end tests

Playwright e2e tests covering the **Angular/Ionic web bundle** that
runs inside the Capacitor WebView on iOS + Android.

## What this catches vs. what it doesn't

✅ **Catches:**
- UI state regressions
- Routing bugs
- Form validation
- List rendering
- Navigation flows
- Conditional rendering (auth gates, vendor gates, role-based UI)

❌ **Doesn't catch (need real device testing):**
- Native plugin behaviour (Camera, Push Notifications, Haptics, Biometric)
- Platform-specific UI (iOS bottom-sheet vs Android material)
- Hardware gestures (swipe-to-back, pull-to-refresh on native)
- Background/foreground lifecycle
- Deep linking / Universal Links / App Links
- Native splash screen / status bar / safe areas

For those, follow the device-test pattern from
`docs/runbooks/m3/m3.1.7-device-test-checklist.md`.

## Running locally

```bash
pnpm --filter @3bayti/mobile test:e2e          # headless
pnpm --filter @3bayti/mobile test:e2e:ui       # interactive
pnpm --filter @3bayti/mobile test:e2e:headed   # see the browser
pnpm --filter @3bayti/mobile test:e2e:report   # open last report
```

The `webServer` config auto-starts `pnpm start` (Ionic serve) on
`http://localhost:4200` if nothing's running. With Ionic already
running, tests attach.

Cold-start timeout is 180s (vs. 120s for apps/web) because Ionic
boot is slower.

## CI behaviour

Per locked decision M3.2.0-Q3 = A: mobile e2e DOES run in CI but
with `continue-on-error: true` initially. This means failures are
visible (PR annotations) but do not block merges. After we establish
a low false-positive baseline, we promote to required check.

Real device testing remains operator-driven via the M3.1.7 device-
test checklist pattern.

## Capacitor Preferences mock

The Capacitor Preferences plugin is a native key-value store with
no faithful browser equivalent. We use a custom mock that intercepts
calls at `window.Capacitor.Plugins.Preferences` BEFORE app boot.

```typescript
import { mockPreferences, mockAuthenticatedUser, mockAuthenticatedVendor } from './utils/preferences-mock';

// Empty store (first-time user; introGuard sends to /intro):
await mockPreferences(page, {});

// Returning user (skips intro):
await mockPreferences(page, { intro_seen: 'true' });

// Authenticated customer:
await mockAuthenticatedUser(page);

// Authenticated vendor (passes is_vendor gates):
await mockAuthenticatedVendor(page);

// Then navigate:
await page.goto('/my-orders');
```

The mock seeds `window.__PLAYWRIGHT_PREFS__` for test introspection
and `window.Capacitor.Plugins.Preferences` with promise-returning
mock methods matching the real plugin signature.

## Mocking backend API calls

For pages that fetch from the backend, use `page.route` to intercept:

```typescript
await page.route('**/customer/read_orders_listing*', async (route) => {
  await route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      status: 'success',
      response_code: 200,
      data: { orders: [] },
    }),
  });
});
```

This decouples e2e from a running backend.

## Layout

```
apps/mobile/e2e/
├── home.spec.ts          # entry routes (/, /intro, app shell)
├── auth.spec.ts          # /login, /register page renders
├── catalog.spec.ts       # browsing surfaces
├── my-orders.spec.ts     # authenticated my-orders page (M3.1.7-I scope)
└── utils/
    └── preferences-mock.ts  # Capacitor Preferences mock helper
```

## Adding a new spec

```typescript
import { test, expect } from '@playwright/test';
import { mockAuthenticatedUser } from './utils/preferences-mock';

test.describe('My new feature', () => {
  test('does the thing', async ({ page }) => {
    await mockAuthenticatedUser(page);
    await page.goto('/my-route');
    await page.waitForLoadState('networkidle');

    await expect(page.getByRole('heading', { name: /Expected/i })).toBeVisible();
  });
});
```

## Conventions

- Always mock Preferences first; auth-gated pages bounce without it.
- Mock backend network calls via `page.route` for deterministic runs.
- Use `getByRole` / `getByText` over CSS selectors when possible.
- Don't assert on translated copy without specifying locale.
- Don't pin to specific class names that may change in design polish.
