import { Pipe, PipeTransform } from '@angular/core';
import { cfImage, CF_PRESETS, type CfImageOptions } from '../image-transform';

export type CfPresetName = keyof typeof CF_PRESETS;

/**
 * Angular pipe — apply Cloudflare image transforms in templates.
 *
 * Usage:
 *   <!-- Named preset (recommended) -->
 *   <img [src]="product.primary_image.url | cfImage:'card'" />
 *   <img [src]="item.product_image        | cfImage:'thumb'" />
 *   <img [src]="designer.cover_image_url  | cfImage:'cover'" />
 *
 *   <!-- Inline options -->
 *   <img [src]="url | cfImage:{ width: 400, quality: 80 }" />
 *
 * Available presets: card, thumb, detail, gallery, cover, logo, hero
 * (see CF_PRESETS in image-transform.ts for exact widths/quality values).
 *
 * The pipe is pure — Angular only re-evaluates when the URL or preset
 * reference changes. No performance cost in list rendering.
 *
 * Import in standalone component:
 *   imports: [CfImagePipe]
 */
@Pipe({ name: 'cfImage', standalone: true, pure: true })
export class CfImagePipe implements PipeTransform {
  transform(url: string | null | undefined, preset: CfPresetName | CfImageOptions): string {
    if (!url) return '';
    const opts: CfImageOptions =
      typeof preset === 'string' ? CF_PRESETS[preset] : preset;
    return cfImage(url, opts);
  }
}
