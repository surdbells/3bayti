/**
 * Typography tokens.
 *
 * Three font families per the modest-luxury aesthetic:
 *   - body: Inter (UI text, prices, labels, paragraph copy)
 *   - display: Playfair Display (h1, h2, h3, marketing headlines)
 *   - script: Cormorant Garamond italic (vendor names on cards — designer voice)
 *
 * Loaded via Google Fonts link in apps/web/src/index.html.
 */

export const fontFamilies = {
  body: "'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
  display: "'Playfair Display', Georgia, 'Times New Roman', serif",
  script: "'Cormorant Garamond', Georgia, serif",
} as const;

export const fontSizes = {
  xs: '11px',
  sm: '12px',
  base: '14px',
  md: '16px',
  lg: '18px',
  xl: '20px',
  '2xl': '24px',
  '3xl': '32px',
  '4xl': '40px',
} as const;

export const fontWeights = {
  regular: 400,
  medium: 500,
  semibold: 600,
  bold: 700,
} as const;

export type FontFamily = keyof typeof fontFamilies;
export type FontSize = keyof typeof fontSizes;
export type FontWeight = keyof typeof fontWeights;
