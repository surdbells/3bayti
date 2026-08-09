import { Component, ChangeDetectionStrategy, Input } from '@angular/core';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * Featured-vendor data shape. Mirrors the mobile app's vendor response
 * (Store with cover/logo + embedded products), normalised to web's typed
 * conventions (slug-based routing).
 */
export interface FeaturedVendorProduct {
  /** Stable identifier for @for track. */
  id: number | string;
  /** Slug for the /product/:slug link target. */
  slug: string;
  /** Product image URL (single thumbnail). */
  image_url: string;
  /** Plain product name for the alt text. */
  name: string;
}

export interface FeaturedVendor {
  /** Slug for the /stores/:slug link target. */
  slug: string;
  /** Display name. */
  name: string;
  /** Short description / tagline (may contain inline HTML). */
  description: string | null;
  /** Vendor logo (circular badge). Null → initial fallback. */
  logo_url?: string | null;
  /** Vendor cover/hero image. Null → falls back to the first product image. */
  cover_image_url?: string | null;
  /** Average rating (0-5). Null if no ratings yet. */
  rating: number | null;
  /** Number of ratings the average is computed from. 0 = no ratings. */
  rating_count: number;
  /** Product thumbnails shown under the hero. */
  products: FeaturedVendorProduct[];
}

/**
 * StoreCard — vendor card with a HERO cover image, matching the mobile
 * vendor card: a cover image with a gradient scrim, a circular logo + name +
 * rating overlaid at the bottom, then a strip of product thumbnails and a
 * "View collection" link.
 *
 * Cover fallback: when the vendor has no cover image, the first product image
 * stands in; when there are no products either, the cover shows a warm brand
 * gradient. Logo falls back to the name's initial.
 *
 * Not a single anchor — the cover, each thumbnail and the CTA are separate
 * <a>s (different destinations), which is the correct pattern for a card with
 * multiple navigation targets.
 */
@Component({
  selector: 'ui-store-card',
  standalone: true,
  imports: [CfImagePipe, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (vendor) {
      <article class="store-card">
        <a [href]="vendorUrl()" class="store-card__cover" [attr.aria-label]="vendor.name">
          @if (heroImage()) {
            <img class="store-card__cover-img" [src]="heroImage() | cfImage:'card'" alt="" loading="lazy" decoding="async" />
          }
          <span class="store-card__scrim" aria-hidden="true"></span>
          <span class="store-card__overlay">
            <span class="store-card__logo">
              @if (vendor.logo_url) {
                <img [src]="vendor.logo_url" [alt]="vendor.name" loading="lazy" decoding="async" />
              } @else {
                <span class="store-card__logo-initial">{{ initial() }}</span>
              }
            </span>
            <span class="store-card__id">
              <span class="store-card__name">{{ vendor.name }}</span>
              @if (vendor.rating !== null && vendor.rating_count > 0) {
                <span class="store-card__rating">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" />
                  </svg>
                  {{ vendor.rating.toFixed(1) }}
                  <span class="store-card__rating-count">({{ vendor.rating_count }})</span>
                </span>
              }
            </span>
          </span>
        </a>

        <div class="store-card__body">
          @if (vendor.products.length > 0) {
            <div class="store-card__thumbs" role="list" [attr.aria-label]="'ui.storeCard.featuredProducts' | translate">
              @for (product of vendor.products.slice(0, 4); track product.id) {
                <a
                  [href]="productUrl(product.slug)"
                  class="store-card__thumb"
                  role="listitem"
                  [attr.aria-label]="product.name"
                >
                  <img [src]="product.image_url | cfImage:'card'" [alt]="product.name" loading="lazy" decoding="async" />
                </a>
              }
            </div>
          }

          <a [href]="vendorUrl()" class="store-card__view-collection">
            {{ 'ui.storeCard.viewCollection' | translate }}
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M5 12h14M13 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </a>
        </div>
      </article>
    }
  `,
  styleUrl: './store-card.scss',
})
export class StoreCardComponent {
  /** The vendor to display. Null/undefined renders nothing. */
  @Input({ required: true }) vendor!: FeaturedVendor | null;

  /** Build the canonical /stores/:slug URL. */
  vendorUrl(): string {
    return `/stores/${this.vendor?.slug ?? ''}`;
  }

  /** Build /product/:slug URL for a thumbnail. */
  productUrl(slug: string): string {
    return `/product/${slug}`;
  }

  /** Name initial for the logo fallback. */
  initial(): string {
    return (this.vendor?.name?.[0] ?? '?').toUpperCase();
  }

  /** Cover image, falling back to the first product image (then a gradient). */
  heroImage(): string {
    return this.vendor?.cover_image_url || this.vendor?.products?.[0]?.image_url || '';
  }
}
