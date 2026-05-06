/**
 * @3bayti/design-tokens
 *
 * Single source of truth for brand design tokens — used by web/mobile/portal.
 *
 * Three consumption modes:
 *   1. TypeScript constants (this index): import { colors, shadows } from '@3bayti/design-tokens'
 *   2. CSS custom properties: @import '@3bayti/design-tokens/css' (use in app's global stylesheet)
 *   3. SCSS variables: @use '@3bayti/design-tokens/scss' as tokens (mobile uses this)
 *
 * The CSS/SCSS exports are kept hand-synced with the TS exports for now.
 * If they drift, a codegen step should generate them from TS — added in M1+ if needed.
 */

export { colors } from './colors.js';
export type { BrandShade, BgRole, TextRole, BorderRole } from './colors.js';

export { shadows } from './shadows.js';
export type { ShadowRole } from './shadows.js';

export { fontFamilies, fontSizes, fontWeights } from './typography.js';
export type { FontFamily, FontSize, FontWeight } from './typography.js';

export { layout, spacing, radii } from './spacing.js';
export type { SpacingScale, RadiusScale } from './spacing.js';
