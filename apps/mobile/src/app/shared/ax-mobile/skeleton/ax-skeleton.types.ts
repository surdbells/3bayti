/**
 * Colour treatment for a skeleton primitive.
 *
 *  - 'default'  : surface-tinted bar (--ax-color-skeleton-base), for content
 *                 on the page canvas / cards. Follows light + dark mode.
 *  - 'on-media' : translucent white bar for placeholders drawn OVER a photo
 *                 under a dark scrim (cover headers, hero images).
 */
export type AxSkeletonTone = 'default' | 'on-media';
