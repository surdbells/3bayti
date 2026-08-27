import { test, expect } from '@playwright/test';
import { mockPreferences } from './utils/preferences-mock';

/**
 * Mobile auth surface baseline tests.
 *
 * These verify the login + register pages render correctly. They do
 * NOT submit forms against the real backend, that's M3.2.Y.1 (web
 * auth UI build) and M3.1.4 (mobile auth flip, already shipped).
 *
 * Focus here is on the page render + validation messaging.
 */

test.describe('Login page', () => {
  test('renders email + password fields', async ({ page }) => {
    await mockPreferences(page, {});
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    // Email and password inputs should be present and focusable.
    const emailField = page.locator('input[type="email"], input[name="email"]').first();
    const passwordField = page.locator('input[type="password"]').first();

    await expect(emailField).toBeVisible();
    await expect(passwordField).toBeVisible();
  });

  test('has a submit button', async ({ page }) => {
    await mockPreferences(page, {});
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    // Either a button[type="submit"] OR an ion-button with appropriate text
    const submitButton = page
      .locator('button[type="submit"], ion-button')
      .filter({ hasText: /sign in|log in|login/i })
      .first();

    await expect(submitButton).toBeVisible();
  });

  test('links to register page', async ({ page }) => {
    await mockPreferences(page, {});
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    // A new-account link is expected on the login page. Don't pin to
    // a specific class, search by text.
    const registerLink = page.locator('a, ion-button').filter({
      hasText: /register|sign up|create account/i,
    });
    const count = await registerLink.count();
    expect(count).toBeGreaterThanOrEqual(1);
  });
});

test.describe('Register page', () => {
  test('renders', async ({ page }) => {
    await mockPreferences(page, {});
    await page.goto('/register');
    await page.waitForLoadState('networkidle');

    // Register page should have at least an email field.
    const emailField = page.locator('input[type="email"], input[name="email"]').first();
    await expect(emailField).toBeVisible();
  });
});
