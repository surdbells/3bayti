/**
 * Cloudflare Image Transformations utility.
 *
 * Wraps a stored image URL with the Cloudflare `/cdn-cgi/image/` resize
 * path so images are served at the right size with auto WebP/AVIF
 * conversion. Works for any domain Cloudflare is proxying (orange cloud).
 *
 * Usage
 * -----
 *   import { cfImage, CF_PRESETS } from '@3bayti/shared-ui/image-transform';
 *
 *   // With explicit options
 *   cfImage(url, { width: 400, quality: 80 })
 *   // → https://api-v3.3bayti.ae/cdn-cgi/image/width=400,quality=80,format=auto/uploads/products/...
 *
 *   // With a named preset
 *   cfImage(url, CF_PRESETS.card)
 *   // → width=480,quality=82,fit=cover,format=auto
 *
 * How it works
 * ------------
 * Cloudflare's image resizing feature intercepts requests whose path
 * starts with /cdn-cgi/image/. The segment after that is a comma-separated
 * list of transform parameters. The rest of the path is the origin URL
 * (either relative or absolute). We use the absolute form so the transform
 * can be applied from any page origin.
 *
 * Reference: https://developers.cloudflare.com/images/transform-images/
 *
 * Passthrough rules
 * -----------------
 * URLs that can't be transformed are returned unchanged:
 *   - Empty / null / undefined
 *   - Blob URLs (thumbUrl from AxFileUpload — local preview before upload)
 *   - Data URLs (base64 — should not reach here in normal flow)
 *   - Already-transformed URLs (contains /cdn-cgi/image/)
 *   - External URLs not on our CDN origin (e.g. legacy api.3bayti.ae —
 *     those will still load; they just won't be resized by CF)
 *   - Relative paths (e.g. assets/img/placeholder-1.png)
 *
 * On passthrough, the raw URL is returned so the <img> still loads.
 *
 * The CF transform origin
 * -----------------------
 * CF Image Transformations only work on your own zone. Our images are
 * served from api-v3.3bayti.ae (CF-proxied orange cloud). The /uploads/
 * path is served by Apache via an Alias directive (see new-server-setup.md).
 * Any request for /cdn-cgi/image/.../<absolute-url> where the absolute URL
 * is on the same zone is handled natively by Cloudflare — no PHP involved.
 */

/** The domain that serves /uploads/ and has CF image transforms enabled. */
const CF_ORIGIN = 'https://api-v3.3bayti.ae';

export interface CfImageOptions {
  /**
   * Target width in CSS pixels. Required for most use cases.
   * Cloudflare will scale height proportionally unless `height` is also set.
   */
  width?: number;
  /** Target height in CSS pixels. */
  height?: number;
  /**
   * Fit mode.
   * - 'cover'   (default) — crop to fill exactly width×height
   * - 'contain' — fit inside width×height, letterbox if needed
   * - 'scale-down' — like contain but never upscale
   * - 'pad'     — contain with transparent padding
   * - 'crop'    — crop without resize (uses width/height as crop window)
   */
  fit?: 'cover' | 'contain' | 'scale-down' | 'pad' | 'crop';
  /**
   * Output quality 1–100. Lower = smaller file, higher = better fidelity.
   * ~80 is the sweet spot for product photography at 2× DPR.
   */
  quality?: number;
  /**
   * Output format.
   * 'auto' (default) — CF picks the best format the browser accepts
   * (AVIF > WebP > JPEG). Recommended for all cases.
   */
  format?: 'auto' | 'webp' | 'avif' | 'jpeg' | 'png';
  /** Sharpen factor 0–10 (default 0). Subtle sharpening at 1 is useful after downsample. */
  sharpen?: number;
}

/**
 * Named presets for common use cases across the 3bayti product.
 * Choose the preset whose container width most closely matches the
 * rendered size. Cloudflare multiplies by DPR automatically when
 * the `dpr` param is set — we omit it here and let the browser
 * request the right DPR via srcset if needed in future.
 */
export const CF_PRESETS = {
  /** Product list card — 3:4 portrait, up to ~480px wide on desktop grid. */
  card:     { width: 480, quality: 82, fit: 'cover',  format: 'auto' } as CfImageOptions,
  /** Thumbnail in cart / order lines / search results — ~80–120px. */
  thumb:    { width: 160, quality: 75, fit: 'cover',  format: 'auto' } as CfImageOptions,
  /** Product detail main image — full-width on mobile, ~640px on desktop. */
  detail:   { width: 900, quality: 85, fit: 'cover',  format: 'auto' } as CfImageOptions,
  /** Product detail gallery thumbnails strip — ~100px square. */
  gallery:  { width: 200, quality: 75, fit: 'cover',  format: 'auto' } as CfImageOptions,
  /** Vendor/designer cover banner — full-width hero, up to 1400px. */
  cover:    { width: 1400, quality: 80, fit: 'cover', format: 'auto' } as CfImageOptions,
  /** Vendor logo — small square, ~80px. */
  logo:     { width: 160, quality: 80, fit: 'contain',format: 'auto' } as CfImageOptions,
  /** Hero carousel — large feature image, full-width. */
  hero:     { width: 1200, quality: 85, fit: 'cover', format: 'auto' } as CfImageOptions,
} as const;

/**
 * Build a Cloudflare image transform URL.
 *
 * @param url      The canonical image URL (as stored in DB / returned by API).
 * @param options  Transform options or a CF_PRESETS entry.
 * @returns        The transformed URL, or the original URL if passthrough applies.
 *
 * @example
 *   cfImage('https://api-v3.3bayti.ae/uploads/products/store/01J....jpg', CF_PRESETS.card)
 *   // → 'https://api-v3.3bayti.ae/cdn-cgi/image/width=480,quality=82,fit=cover,format=auto/https://api-v3.3bayti.ae/uploads/products/store/01J....jpg'
 */
export function cfImage(url: string | null | undefined, options: CfImageOptions): string {
  // ── Passthrough cases ─────────────────────────────────────────────────────

  if (!url || url.trim() === '') return '';

  // Local browser previews before upload
  if (url.startsWith('blob:') || url.startsWith('data:')) return url;

  // Already transformed
  if (url.includes('/cdn-cgi/image/')) return url;

  // Relative paths (placeholder images, assets/)
  if (!url.startsWith('http://') && !url.startsWith('https://')) return url;

  // Not on the CF-enabled origin — return as-is (still loads, just not resized)
  if (!url.startsWith(CF_ORIGIN)) return url;

  // ── Build transform parameters ────────────────────────────────────────────

  const params: string[] = [];

  if (options.width)   params.push(`width=${options.width}`);
  if (options.height)  params.push(`height=${options.height}`);
  if (options.fit)     params.push(`fit=${options.fit}`);
  if (options.quality) params.push(`quality=${options.quality}`);
  if (options.sharpen) params.push(`sharpen=${options.sharpen}`);

  // format=auto always included — tells CF to pick AVIF/WebP/JPEG by Accept header
  params.push(`format=${options.format ?? 'auto'}`);

  if (params.length === 0) return url;

  return `${CF_ORIGIN}/cdn-cgi/image/${params.join(',')}/${url}`;
}

/**
 * Angular pipe for use in templates:
 *
 *   {{ product.primary_image.url | cfImage:'card' }}
 *   {{ url | cfImage:{ width: 400, quality: 80 } }}
 *
 * Import `CfImagePipe` in your standalone component's `imports` array.
 * The pipe is pure — Angular will only recompute when the URL or
 * options reference changes.
 */
export function createCfImagePipe(): unknown {
  // Defined as a factory so this file stays framework-agnostic.
  // The Angular-specific class is exported from ./angular/cf-image.pipe.ts.
  // Import from there in Angular apps.
  throw new Error('Use the Angular pipe from @3bayti/shared-ui/angular instead.');
}
