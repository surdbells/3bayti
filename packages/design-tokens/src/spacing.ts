/**
 * Spacing tokens + page layout.
 *
 * The page-level tokens (max-width, gutter) are the most important —
 * they enforce that every section's content edge aligns to the same
 * container bounds. Before these existed, sections used a mix of
 * max-widths and paddings, causing strips to bleed to viewport edges
 * while categories/designers stopped at 1280px (the "uneven content
 * width" issue from Phase 1 W3 review).
 */

export const layout = {
  pageMaxWidth: '1280px',
  pagePaddingX: '24px',
  pagePaddingXDesktop: '48px', // applied at >= 1024px
  desktopBreakpoint: '1024px',
} as const;

/**
 * Generic spacing scale (4px base). Use these in component code:
 * margin: spacing[2] (=8px), padding: spacing[4] (=16px), etc.
 */
export const spacing = {
  0: '0',
  px: '1px',
  0.5: '2px',
  1: '4px',
  1.5: '6px',
  2: '8px',
  2.5: '10px',
  3: '12px',
  4: '16px',
  5: '20px',
  6: '24px',
  8: '32px',
  10: '40px',
  12: '48px',
  16: '64px',
  20: '80px',
  24: '96px',
} as const;

export const radii = {
  none: '0',
  sm: '4px',
  md: '8px',
  lg: '14px',
  xl: '20px',
  full: '9999px',
} as const;

export type SpacingScale = keyof typeof spacing;
export type RadiusScale = keyof typeof radii;
