/**
 * Shadow tokens — the "Soft & subtle" surface elevation language.
 *
 * Five surfaces use these: ProductCard, DesignerCard, category tiles,
 * designer skeleton, hero carousel center card. Floating buttons (strip
 * arrows, carousel arrows, dot indicators) use the floating token.
 *
 * History: replaced the previous "Pronounced shadow" language (Phase 1
 * Week 1) with these softer values per W3 round-3 direction. The
 * previous language used 3 layers up to 0.16 opacity at 48px blur —
 * readable but visually heavy. The new tokens use 2 layers, max 0.06
 * opacity at 12px blur — cards still float but feel grounded. Closer
 * to ASOS / COS / H&M editorial than Pinterest.
 */

export const shadows = {
  cardResting:
    '0 1px 2px rgba(90, 58, 44, 0.04), 0 4px 12px -2px rgba(90, 58, 44, 0.06)',

  cardHover:
    '0 2px 4px rgba(90, 58, 44, 0.06), 0 8px 20px -4px rgba(90, 58, 44, 0.10)',

  /**
   * Carousel center card sits proud of the page without overpowering
   * the image. Slightly stronger than card-hover but still soft.
   */
  carouselCenter:
    '0 4px 8px rgba(90, 58, 44, 0.06), 0 16px 32px -8px rgba(90, 58, 44, 0.12)',

  /**
   * Floating UI elements (arrow buttons, dot indicators) sit on top
   * of cards or images and need a subtle separating shadow regardless
   * of background.
   */
  floating:
    '0 1px 2px rgba(90, 58, 44, 0.04), 0 6px 14px -4px rgba(90, 58, 44, 0.08)',
} as const;

export type ShadowRole = keyof typeof shadows;
