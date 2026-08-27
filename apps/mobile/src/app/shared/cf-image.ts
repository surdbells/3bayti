/**
 * Cloudflare image transform helper for the 3bayti mobile app.
 * Mirrors packages/shared-ui/src/image-transform.ts, keep in sync.
 *
 * Transforms image URLs to use Cloudflare's /cdn-cgi/image/ resizing
 * path when the domain is CF-proxied (orange cloud). Passes through
 * all other URLs unchanged so existing images still load.
 *
 * Usage in component HTML:
 *   [src]="cfImage(product.image_1, 'card')"
 *
 * Usage in component class (inject or import directly):
 *   import { cfImage, CF_MOBILE_PRESETS } from '../shared/cf-image';
 */

const CF_ORIGIN = 'https://api-v3.3bayti.ae';

export interface CfImageOptions {
  width?: number;
  height?: number;
  fit?: 'cover' | 'contain' | 'scale-down' | 'pad' | 'crop';
  quality?: number;
  format?: 'auto' | 'webp' | 'avif' | 'jpeg' | 'png';
}

/**
 * Presets tuned for mobile viewports (375–430px wide × @2x/@3x DPR).
 * We request ~2× the CSS pixel width so Retina screens are sharp.
 */
export const CF_MOBILE_PRESETS = {
  /** Product cards in lists / category grids, half-screen width @2×. */
  card:    { width: 480,  quality: 82, fit: 'cover',   format: 'auto' } as CfImageOptions,
  /** Full-screen product image gallery. */
  detail:  { width: 900,  quality: 88, fit: 'cover',   format: 'auto' } as CfImageOptions,
  /** Order line items / cart rows, small thumbnail. */
  thumb:   { width: 160,  quality: 75, fit: 'cover',   format: 'auto' } as CfImageOptions,
  /** Vendor cover banner, full-width. */
  cover:   { width: 1200, quality: 80, fit: 'cover',   format: 'auto' } as CfImageOptions,
  /** Vendor logo, small square. */
  logo:    { width: 160,  quality: 80, fit: 'contain', format: 'auto' } as CfImageOptions,
} as const;

export type CfMobilePreset = keyof typeof CF_MOBILE_PRESETS;

/**
 * Build a Cloudflare image transform URL.
 * Pass-through: empty, blob:, data:, already-transformed, relative,
 * or any URL not on the CF_ORIGIN domain.
 */
export function cfImage(
  url: string | null | undefined,
  preset: CfMobilePreset | CfImageOptions,
): string {
  if (!url || url.trim() === '') return '';
  if (url.startsWith('blob:') || url.startsWith('data:')) return url;
  if (url.includes('/cdn-cgi/image/')) return url;
  if (!url.startsWith('http://') && !url.startsWith('https://')) return url;
  if (!url.startsWith(CF_ORIGIN)) return url;

  const opts: CfImageOptions =
    typeof preset === 'string' ? CF_MOBILE_PRESETS[preset] : preset;

  const params: string[] = [];
  if (opts.width)   params.push(`width=${opts.width}`);
  if (opts.height)  params.push(`height=${opts.height}`);
  if (opts.fit)     params.push(`fit=${opts.fit}`);
  if (opts.quality) params.push(`quality=${opts.quality}`);
  params.push(`format=${opts.format ?? 'auto'}`);

  return `${CF_ORIGIN}/cdn-cgi/image/${params.join(',')}/${url}`;
}
