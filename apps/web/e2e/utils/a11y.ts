import { AxeBuilder } from '@axe-core/playwright';
import { expect, type Page, type TestInfo } from '@playwright/test';
import { filterAllowlistedViolations } from './a11y-allowlist';

/**
 * Automated WCAG AA accessibility gate using axe-core.
 *
 * M3.2.0-D, locked decision Q2 = B: enforce WCAG 2.0 A + AA + 2.1 A + AA.
 * Known baseline violations are allowlisted with documented remediation
 * plans (see utils/a11y-allowlist.ts); new violations are hard failures.
 *
 * Usage in a spec file:
 *   import { test, expect } from '@playwright/test';
 *   import { expectNoA11yViolations } from './utils/a11y';
 *
 *   test('home page is accessible', async ({ page }, info) => {
 *     await page.goto('/');
 *     await page.waitForLoadState('networkidle');
 *     await expectNoA11yViolations(page, info);
 *   });
 *
 * For ad-hoc rule exclusion (rare; document in PR):
 *   await expectNoA11yViolations(page, info, {
 *     excludeRules: ['color-contrast'],
 *     reason: 'M3.2.0-D baseline pending design-token contrast audit',
 *   });
 *
 * What axe tests:
 *   - Color contrast (WCAG 1.4.3, 1.4.11)
 *   - Form labels (WCAG 3.3.2)
 *   - Heading order (WCAG 1.3.1)
 *   - Image alt text (WCAG 1.1.1)
 *   - Keyboard accessibility (WCAG 2.1.1)
 *   - ARIA attributes (WCAG 4.1.2)
 *   - Focus visibility (WCAG 2.4.7)
 *   - ~90 rules total at the AA tag level
 *
 * What axe does NOT test (still requires manual review):
 *   - Screen reader announcement quality
 *   - Cognitive load
 *   - Touch target size on mobile
 *   - Sequence of focus order
 *   - Sound captions / video alternatives
 *
 * See https://github.com/dequelabs/axe-core/blob/develop/doc/rule-descriptions.md
 */

export interface A11yOptions {
  /** Rules to exclude from this single test (use sparingly; document why) */
  excludeRules?: string[];
  /** CSS selectors to skip (e.g. third-party widgets) */
  excludeSelectors?: string[];
  /** Reason for any exclusion, surfaces in test output for review */
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

  // Apply test-specific rule exclusions.
  if (options.excludeRules && options.excludeRules.length > 0) {
    builder = builder.disableRules(options.excludeRules);
    if (options.reason) {
      info.annotations.push({
        type: 'a11y-rule-exclusion',
        description: `Excluded rules [${options.excludeRules.join(', ')}]: ${options.reason}`,
      });
    }
  }

  // Apply selector exclusions.
  if (options.excludeSelectors && options.excludeSelectors.length > 0) {
    for (const sel of options.excludeSelectors) {
      builder = builder.exclude(sel);
    }
  }

  const results = await builder.analyze();

  // Filter known-allowlisted violations.
  const realViolations = filterAllowlistedViolations(results.violations, page.url());

  // Attach the full report as a Playwright artifact for debugging.
  if (results.violations.length > 0) {
    await info.attach('axe-violations.json', {
      body: JSON.stringify(results.violations, null, 2),
      contentType: 'application/json',
    });
  }

  if (realViolations.length > 0) {
    // Build a human-readable failure message.
    const lines = realViolations.map((v) => {
      const nodes = v.nodes.map((n) => `      • ${n.html} — ${n.failureSummary ?? 'no summary'}`).join('\n');
      return `  [${v.impact?.toUpperCase() ?? '???'}] ${v.id}: ${v.help}\n    Help URL: ${v.helpUrl}\n${nodes}`;
    });
    const msg = [
      `A11y violations found on ${page.url()}:`,
      ...lines,
      '',
      'If a violation is expected (legacy code pending remediation),',
      'add it to apps/web/e2e/utils/a11y-allowlist.ts with reason +',
      'remediation phase + expiry date. If a violation is new, fix it',
      'in this PR.',
    ].join('\n');
    expect(realViolations, msg).toEqual([]);
  }
}
