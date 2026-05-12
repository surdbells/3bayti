/**
 * Public types for the abayti mobile <ax-icon> primitive.
 *
 * See docs/m4-icons-loader-skeleton-spec.md for design rationale.
 */

export type AxIconSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';
export type AxIconStrokeWidth = 'thin' | 'regular' | 'bold';

/**
 * Pixel values for each size token.
 * - xs (14): inline within text
 * - sm (16): tab labels, form addons
 * - md (20): buttons, list items (default)
 * - lg (24): section headers, cards
 * - xl (32): empty states, hero icons
 */
export const AX_ICON_SIZES: Record<AxIconSize, number> = {
  xs: 14,
  sm: 16,
  md: 20,
  lg: 24,
  xl: 32,
};

/**
 * Stroke widths for each weight token.
 * - thin (1.25): finest, for delicate decorative use
 * - regular (1.5): default, editorial restraint (vs lucide's 2.0)
 * - bold (2.0): heavier emphasis when icon needs to assert presence
 */
export const AX_ICON_STROKES: Record<AxIconStrokeWidth, number> = {
  thin: 1.25,
  regular: 1.5,
  bold: 2.0,
};
