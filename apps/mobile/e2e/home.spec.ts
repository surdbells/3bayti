import { test, expect } from '@playwright/test';
import { mockPreferences } from './utils/preferences-mock';

/**
 * Mobile app entry-point baseline tests.
 *
 * The mobile app's root route ('/') redirects to '/intro' for first-
 * time users (introGuard checks for 'intro_seen' Preferences key).
 * Returning users skip intro and land on '/home'.
 *
 * These tests cover both paths via Preferences mock.
 */

test.describe('Mobile entry — first-time user', () => {
  test('root path redirects to intro', async ({ page }) => {
    // No 'intro_seen' key → introGuard sends user to /intro
    await mockPreferences(page, {});

    await page.goto('/');
    await page.waitForLoadState('networkidle');

    expect(page.url()).toContain('/intro');
  });

  test('intro page renders', async ({ page }) => {
    await mockPreferences(page, {});
    await page.goto('/intro');
    await page.waitForLoadState('networkidle');

    // The intro page is part of the public bundle. We don't pin to
    // specific marketing copy, just verify the route resolved.
    expect(page.url()).toContain('/intro');
  });
});

test.describe('Mobile entry — returning user', () => {
  test('skips intro after intro_seen', async ({ page }) => {
    // intro_seen=true → introGuard allows direct nav to /home equivalent
    await mockPreferences(page, { intro_seen: 'true' });

    // The app may route directly into the catalog tab or login depending
    // on auth state. Either is acceptable here, we just verify the
    // user is NOT bounced to /intro.
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    expect(page.url()).not.toContain('/intro');
  });
});

test.describe('Mobile app shell', () => {
  test('renders without JS errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (err) => errors.push(err.message));
    page.on('console', (msg) => {
      if (msg.type() === 'error') errors.push(msg.text());
    });

    await mockPreferences(page, { intro_seen: 'true' });
    await page.goto('/intro');
    await page.waitForLoadState('networkidle');

    const realErrors = errors.filter(
      (e) =>
        !e.includes('favicon') &&
        !e.toLowerCase().includes('deprecat') &&
        !e.includes('Failed to load resource') &&
        // Capacitor in web preview logs warnings about missing native
        // bridges, expected, not a regression.
        !e.toLowerCase().includes('capacitor') &&
        !e.toLowerCase().includes('not implemented on web'),
    );

    expect(realErrors).toEqual([]);
  });
});
