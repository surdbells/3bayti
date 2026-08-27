import { AxeBuilder } from '@axe-core/playwright';
import { expect, type Page, type TestInfo } from '@playwright/test';

/**
 * Automated WCAG AA accessibility gate for apps/mobile e2e tests.
 *
 * Mirrors apps/web's a11y helper. Mobile-specific notes:
 *
 *   - Tests run against the Ionic dev server (web preview), so this
 *     catches AA issues in the Angular layer, not OS-level a11y
 *     (VoiceOver/TalkBack) which still need real device verification
 *     per M3.1.7 device-test pattern.
 *
 *   - We don't yet have a mobile allowlist file. M3.1.x mobile pages
 *     shipped through M3.1.7, adding axe-core retroactively would
 *     surface known issues. The mobile allowlist will land alongside
 *     the first M3.2.Z mobile phase that adds e2e coverage to specific
 *     pages.
 *
 * Usage:
 *   import { expectNoA11yViolations } from './utils/a11y';
 *
 *   test('login page is accessible', async ({ page }, info) => {
 *     await page.goto('/login');
 *     await page.waitForLoadState('networkidle');
 *     await expectNoA11yViolations(page, info);
 *   });
 */

export interface A11yOptions {
  excludeRules?: string[];
  excludeSelectors?: string[];
  reason?: string;
}

export async function expectNoA11yViolations(
  page: Page,
  info: TestInfo,
  options: A11yOptions = {},
): Promise<void> {
  let builder = new AxeBuilder({ page }).withTags([
    'wcag2a',
    'wcag2aa',
    'wcag21a',
    'wcag21aa',
  ]);

  if (options.excludeRules && options.excludeRules.length > 0) {
    builder = builder.disableRules(options.excludeRules);
    if (options.reason) {
      info.annotations.push({
        type: 'a11y-rule-exclusion',
        description: `Excluded rules [${options.excludeRules.join(', ')}]: ${options.reason}`,
      });
    }
  }

  if (options.excludeSelectors && options.excludeSelectors.length > 0) {
    for (const sel of options.excludeSelectors) {
      builder = builder.exclude(sel);
    }
  }

  const results = await builder.analyze();

  if (results.violations.length > 0) {
    await info.attach('axe-violations.json', {
      body: JSON.stringify(results.violations, null, 2),
      contentType: 'application/json',
    });
    const lines = results.violations.map((v) => {
      const nodes = v.nodes
        .map((n) => `      • ${n.html} — ${n.failureSummary ?? 'no summary'}`)
        .join('\n');
      return `  [${v.impact?.toUpperCase() ?? '???'}] ${v.id}: ${v.help}\n    Help URL: ${v.helpUrl}\n${nodes}`;
    });
    const msg = [`A11y violations found on ${page.url()}:`, ...lines].join('\n');
    expect(results.violations, msg).toEqual([]);
  }
}
