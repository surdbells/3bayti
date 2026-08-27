/**
 * Minimal structural type for an axe violation. Mirrors the relevant
 * subset of axe-core's `Result` type without requiring direct axe-core
 * import (which is only a transitive dep here).
 */
interface AxeResult {
  id: string;
  impact?: 'minor' | 'moderate' | 'serious' | 'critical' | null;
  help: string;
  helpUrl: string;
  nodes: Array<{
    html: string;
    failureSummary?: string;
  }>;
}

/**
 * Allowlist of accessibility violations on existing pages that are
 * known but not yet remediated.
 *
 * M3.2.0-D, locked decision Q2 = B: rather than block M3.2.0 closure
 * on an a11y remediation sprint, we document existing violations
 * here and fix them inline as we ship new features.
 *
 * Each entry MUST include:
 *   - rule: the axe rule ID (e.g. 'color-contrast', 'label')
 *   - urlPattern: which pages this allowance applies to (string match
 *     against page.url() at test time)
 *   - reason: why this violation isn't fixed yet
 *   - remediationPhase: which M3.2.x phase will fix it
 *   - expiresAt: ISO date string; after this date the entry becomes
 *     a hard failure even if still on the allowlist (forces review)
 *
 * Adding a new entry should require justification in the PR description.
 * Removing an entry (fix landed) is celebrated.
 *
 * Initial baseline will be populated during M3.2.0-D first-run. Empty
 * here means "no known violations, every new violation is a failure."
 *
 * The expiresAt mechanism ensures we don't accumulate forever-deferred
 * a11y debt. Three months is the default; extend with a fresh
 * justification only if remediation timeline genuinely slips.
 */

export interface A11yAllowlistEntry {
  /** Axe rule ID, see https://dequeuniversity.com/rules/axe */
  rule: string;
  /** Substring match against page.url() at test time */
  urlPattern: string;
  /** Why this isn't fixed yet (human-readable) */
  reason: string;
  /** Which M3.2.x phase will fix it */
  remediationPhase: string;
  /** ISO date, after this date the entry is a hard failure */
  expiresAt: string;
}

export const A11Y_ALLOWLIST: A11yAllowlistEntry[] = [
  // Populated during M3.2.0-D first-run after observing baseline.
  // Empty array = no current allowlist. Every new violation that
  // surfaces must either:
  //   a) be fixed in the same PR that introduced it, OR
  //   b) be added here with the four required fields above
];

/**
 * Filter a set of axe violations against the allowlist for the given
 * URL. Returns only violations that are NOT on the allowlist (i.e.
 * violations that should fail the test).
 */
export function filterAllowlistedViolations(
  violations: AxeResult[],
  pageUrl: string,
): AxeResult[] {
  const now = new Date();
  return violations.filter((v) => {
    // Find an allowlist entry that matches this rule + URL.
    const entry = A11Y_ALLOWLIST.find(
      (a) => a.rule === v.id && pageUrl.includes(a.urlPattern),
    );
    if (!entry) return true; // not allowlisted → must surface

    // Check expiry: expired entries become hard failures.
    if (new Date(entry.expiresAt) < now) {
      return true;
    }

    return false;
  });
}
