import type { Page } from '@playwright/test';

/**
 * Capacitor Preferences plugin mock for Playwright e2e tests.
 *
 * The Capacitor Preferences plugin is a native iOS/Android key-value
 * store with no browser equivalent. In the web preview (where
 * Playwright runs), Capacitor falls back to a localStorage-based
 * shim, but the shim's behaviour differs subtly from the native
 * plugin (timing, capacity limits, async behaviour).
 *
 * For deterministic test runs we install our own mock via
 * `page.addInitScript` BEFORE any app code executes. The mock:
 *   - intercepts the @capacitor/preferences module via a window
 *     property the app's Capacitor adapter looks for
 *   - provides a synchronous in-memory store
 *   - is reset per-page-load (per-test isolation)
 *
 * Usage in a test:
 *   await mockPreferences(page, { user: { id: 1, token: 'abc' } });
 *   await page.goto('/my-orders');
 *
 * Reset by calling `mockPreferences(page, {})` or just not calling it
 * (subsequent navigations get the most recent mocked state).
 */
export async function mockPreferences(
  page: Page,
  initial: Record<string, unknown> = {},
): Promise<void> {
  // Serialize values to strings since the real Preferences API only
  // stores strings.
  const serialized: Record<string, string> = {};
  for (const [k, v] of Object.entries(initial)) {
    serialized[k] = typeof v === 'string' ? v : JSON.stringify(v);
  }

  // addInitScript runs before any page script. We seed window with
  // both a global `__PLAYWRIGHT_PREFS__` and a window-level intercept
  // on `window.Capacitor.Plugins.Preferences` so any Capacitor adapter
  // pattern picks it up.
  await page.addInitScript((store: Record<string, string>) => {
    const data = { ...store };

    // Global hook for test introspection.
    (window as unknown as { __PLAYWRIGHT_PREFS__: Record<string, string> })[
      '__PLAYWRIGHT_PREFS__'
    ] = data;

    // Capacitor.Plugins.Preferences API surface. Real plugin returns
    // promises; mock matches that contract.
    const w = window as unknown as {
      Capacitor?: { Plugins?: Record<string, unknown> };
    };
    w.Capacitor = w.Capacitor ?? { Plugins: {} };
    w.Capacitor.Plugins = w.Capacitor.Plugins ?? {};
    w.Capacitor.Plugins['Preferences'] = {
      get: ({ key }: { key: string }) =>
        Promise.resolve({ value: data[key] ?? null }),
      set: ({ key, value }: { key: string; value: string }) => {
        data[key] = value;
        return Promise.resolve();
      },
      remove: ({ key }: { key: string }) => {
        delete data[key];
        return Promise.resolve();
      },
      clear: () => {
        for (const k of Object.keys(data)) delete data[k];
        return Promise.resolve();
      },
      keys: () => Promise.resolve({ keys: Object.keys(data) }),
      migrate: () => Promise.resolve({ migrated: [], existing: [] }),
      removeOld: () => Promise.resolve(),
    };
  }, serialized);
}

/**
 * Convenience: seed an authenticated user state. Use when the test
 * needs to bypass the login flow and land directly on a logged-in
 * page.
 *
 * Default user shape matches what apps/mobile's `Preferences.get('user')`
 * call sites expect: { id, token, name, email, is_vendor, ... }.
 */
export async function mockAuthenticatedUser(
  page: Page,
  overrides: Partial<{
    id: number;
    token: string;
    name: string;
    email: string;
    is_vendor: boolean;
  }> = {},
): Promise<void> {
  const user = {
    id: 1,
    token: 'mock-jwt-token-for-tests',
    name: 'Test Customer',
    email: 'test@example.com',
    is_vendor: false,
    ...overrides,
  };
  await mockPreferences(page, { user });
}

/**
 * Convenience: seed an authenticated VENDOR user state. Required
 * for any page guarded by `is_vendor === true` (vendor-orders,
 * vendor-order-detail, store-dashboard).
 */
export async function mockAuthenticatedVendor(
  page: Page,
  overrides: Partial<{
    id: number;
    token: string;
    name: string;
    email: string;
  }> = {},
): Promise<void> {
  await mockAuthenticatedUser(page, { ...overrides, is_vendor: true });
}
