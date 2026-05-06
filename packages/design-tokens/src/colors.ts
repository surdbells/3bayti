/**
 * Brand color tokens — semantic palette for 3bayti.
 *
 * Mirrors the mobile app's identity. Apps consume these as TypeScript
 * constants OR via the `tokens.css` / `tokens.scss` exports (CSS variables).
 *
 * Don't add raw hex values in app code. If a new color is needed,
 * add it here and it cascades to web + mobile + portal automatically.
 */

export const colors = {
  brand: {
    50: '#f9f4ea',
    100: '#efe2cf',
    200: '#e4d0b1',
    300: '#d6b988',
    400: '#c69d63',
    500: '#b18f1f', // primary gold
    600: '#8c6f0f',
    700: '#5a3a2c', // deep espresso for headings
  },
  bg: {
    canvas: '#faf8f5',
    surface: '#ffffff',
    muted: '#f4f0ea',
  },
  text: {
    primary: '#2e241c',
    secondary: '#5a4a3c',
    tertiary: '#8a7868',
    inverse: '#ffffff',
  },
  border: {
    subtle: 'rgba(46, 36, 28, 0.08)',
    default: 'rgba(46, 36, 28, 0.16)',
  },
} as const;

export type BrandShade = keyof typeof colors.brand;
export type BgRole = keyof typeof colors.bg;
export type TextRole = keyof typeof colors.text;
export type BorderRole = keyof typeof colors.border;
