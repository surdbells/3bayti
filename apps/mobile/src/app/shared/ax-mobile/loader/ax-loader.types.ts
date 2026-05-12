/**
 * Public types for the abayti mobile <ax-loader> primitive.
 *
 * See docs/m4-icons-loader-skeleton-spec.md for design rationale.
 */

export type AxLoaderSize = 'sm' | 'md' | 'lg';

/**
 * Per-token sizing values. Not exported as runtime constants because
 * the dimensions are baked into the component SCSS via class
 * selectors — exposing them as JS would create two sources of truth.
 *
 * For reference:
 *   sm: 4px dots, 32px total width — inline buttons, small spaces
 *   md: 6px dots, 48px total width — list loading (default)
 *   lg: 8px dots, 64px total width — page-level loading
 */
