import { Component, ChangeDetectionStrategy, Input } from '@angular/core';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * Featured-vendor data shape. Mirrors the mobile app's `customer/featured`
 * response (Store with embedded products), normalised to web's typed
 * conventions (slug-based routing, Money objects).
 *
 * Lives here because /v2/featured-vendors (Phase 1 backend deliverable)
 * is its only consumer right now. Will move to a shared model file in
 * Phase 6 when /store/:slug also consumes vendor data.
 */
export interface FeaturedVendorProduct {
  /** Stable identifier for *trackBy. */
  id: number | string;
  /** Slug for the /product/:slug link target. */
  slug: string;
  /** Product image URL (single thumbnail; no responsive sources at this size). */
  image_url: string;
  /** Plain product name for the alt text. */
  name: string;
}

export interface FeaturedVendor {
  /** Slug for the /store/:slug link target. */
  slug: string;
  /** Display name. */
  name: string;
  /**
   * Short description / tagline. May contain inline HTML from the
   * vendor (formatted via the admin app). Component renders it via
   * [innerHTML] — backend is responsible for sanitising at write time.
   */
  description: string | null;
  /** Average rating (0-5). Null if no ratings yet. */
  rating: number | null;
  /** Number of ratings the average is computed from. 0 = no ratings. */
  rating_count: number;
  /**
   * Up to 4 product thumbnails. We render 4 in a 2×2 grid. If fewer
   * are returned (rare), the grid auto-fills with empty space — the
   * card still looks balanced because the cream surface fills the gaps.
   */
  products: FeaturedVendorProduct[];
}

/**
 * StoreCard — vendor card with embedded product thumbnails.
 *
 * Used by the home-page Store Spotlight section (Phase 1) and
 * eventually any "stores we think you'd love" surface (Phase 6+).
 *
 * Layout (left-to-right reading order):
 *   - Top: vendor name (Playfair) + rating chip (if rating_count > 0)
 *   - Description (Cormorant italic, dimmed) — optional
 *   - 2×2 grid of product thumbnails, each clicks through to PDP
 *   - Bottom: "View collection →" link to /store/:slug
 *
 * Visual cohesion with ProductCard:
 *   - Same cream surface (#fdfaf3)
 *   - Same Pronounced shadow (already a brand pattern at this point)
 *   - Same typography hierarchy
 *   - Same hover lift (-6px) and shadow deepening
 *
 * Important difference from ProductCard:
 *   - StoreCard is NOT a single anchor. The outer container is a
 *     plain div because the card has multiple click targets (4 product
 *     thumbnails + "View collection" link, all going to different
 *     destinations). Each is a separate <a>.
 *   - This means the entire card isn't one big focusable target —
 *     keyboard users tab through each <a> individually, which is
 *     the correct accessibility pattern for a card with multiple
 *     navigation destinations.
 */
@Component({
  selector: 'ui-store-card',
  standalone: true,
  imports: [CfImagePipe, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (vendor) {
      <article class="store-card">
        <header class="store-card__header">
          <a [href]="vendorUrl()" class="store-card__name-link">
            <h3 class="store-card__name">{{ vendor.name }}</h3>
          </a>
          @if (vendor.rating !== null && vendor.rating_count > 0) {
            <a [href]="vendorReviewsUrl()" class="store-card__rating">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" />
              </svg>
              <span>{{ vendor.rating.toFixed(1) }}</span>
              <span class="store-card__rating-count">({{ vendor.rating_count }})</span>
            </a>
          }
        </header>

        @if (vendor.description) {
          <p class="store-card__description" [innerHTML]="vendor.description"></p>
        }

        <div class="store-card__products" role="list" [attr.aria-label]="'ui.storeCard.featuredProducts' | translate">
          @for (product of vendor.products.slice(0, 4); track product.id) {
            <a
              [href]="productUrl(product.slug)"
              class="store-card__product"
              role="listitem"
              [attr.aria-label]="product.name"
            >
              <img
                [src]="product.image_url | cfImage:'card'"
                [alt]="product.name"
                loading="lazy"
                decoding="async"
              />
            </a>
          }
        </div>

        <a [href]="vendorUrl()" class="store-card__view-collection">
          {{ 'ui.storeCard.viewCollection' | translate }}
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M5 12h14M13 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </a>
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

  /** /stores/:slug/reviews — the rating chip links here. */
  vendorReviewsUrl(): string {
    return `/stores/${this.vendor?.slug ?? ''}/reviews`;
  }

  /** Build /product/:slug URL for a thumbnail. */
  productUrl(slug: string): string {
    return `/product/${slug}`;
  }
}
