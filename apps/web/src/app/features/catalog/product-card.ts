import { Component, ChangeDetectionStrategy, Input } from '@angular/core';
import { Money, Product } from './product.model';

/**
 * ProductCard — single card in any product display surface.
 *
 * Used by:
 *   - /category/:slug              (W2.1)
 *   - /product/:slug related grid  (W2.2)
 *   - Home page product strips     (Phase 1)
 *   - /designer/:slug storefront   (Phase 6)
 *   - /search results              (Phase 6)
 *
 * Design language: "Gilded Boutique" — locked in roadmap §4.4.
 *   - Cream surface (#fdfaf3) lifts off the canvas
 *   - 20px card radius, 14px image radius with 14px padding (image
 *     floats inside the card, doesn't bleed to its edges)
 *   - Pronounced shadow at rest, dramatic 6px lift on hover
 *   - Cormorant italic vendor → Playfair product → Inter price
 *   - Gold ornament dot divider between name and price/rating
 *   - Frosted-glass badge (top-left) + like button (top-right)
 *   - Out-of-stock and sale-price treatments
 *
 * Renders entirely from a typed Product object. No API calls, no async.
 * SSR-safe (no DOM access, no `window`, no `document`).
 *
 * Defensive design choices preserved from prior version:
 *   - Image URL falsy → letter-fallback (brand-colored cream square
 *     with first character of product name)
 *   - vendor missing → vendor row hidden
 *   - sale_price missing or not lower than price → only regular price shown
 *   - rating === null → rating row hidden (don't fake a 0.0)
 *   - in_stock === false → soft "out of stock" treatment, hover disabled
 *   - product.slug missing → renders without an anchor (still visible,
 *     just not clickable; better than crashing)
 *
 * The card link uses anchor href (not routerLink) because every card on
 * a category page is server-rendered and many won't be hydrated until
 * the user starts interacting. Plain anchors work without JS, which is
 * exactly what crawlers + slow-network mobile users need.
 */
@Component({
  selector: 'ui-product-card',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (product) {
      @if (product.slug) {
        <a [href]="productUrl()" class="product-card" [class.is-out-of-stock]="!product.in_stock">
          <div class="product-card__image-wrap">
            @if (product.primary_image?.url) {
              <img
                [src]="product.primary_image!.url"
                [alt]="imageAlt()"
                loading="lazy"
                decoding="async"
              />
            } @else {
              <div class="product-card__fallback" aria-hidden="true">
                <span>{{ initial() }}</span>
              </div>
            }

            <!-- Top-left: status badge (only one shown, in priority
                 order: Sale > New > Bestseller). The previous design
                 stacked all three; the new spec keeps it cleaner. -->
            @if (isOnSale()) {
              <span class="product-card__badge product-card__badge--sale">Sale</span>
            } @else if (product.is_new) {
              <span class="product-card__badge">New</span>
            } @else if (product.is_bestseller) {
              <span class="product-card__badge">Best seller</span>
            }

            <!-- Top-right: like / wishlist button. Wishlist behavior
                 lands in Phase 5 (local-first then merge-on-login).
                 For now this is decorative — clicking does nothing. -->
            <button
              type="button"
              class="product-card__like"
              aria-label="Save to wishlist"
              (click)="onLikeClick($event)"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
              </svg>
            </button>

            @if (!product.in_stock) {
              <div class="product-card__stock-overlay">
                <span>Out of stock</span>
              </div>
            }
          </div>

          <div class="product-card__meta">
            @if (product.vendor?.name) {
              <p class="product-card__vendor">{{ product.vendor!.name }}</p>
            }

            <h3 class="product-card__name">{{ product.name }}</h3>

            <div class="product-card__divider" aria-hidden="true">
              <span class="product-card__divider-line"></span>
              <span class="product-card__divider-mark"></span>
              <span class="product-card__divider-line"></span>
            </div>

            <div class="product-card__price-row">
              <span class="product-card__price">
                @if (isOnSale() && product.sale_price) {
                  <span class="product-card__price-current product-card__price-current--sale">
                    {{ formatMoney(product.sale_price) }}
                  </span>
                  <span class="product-card__price-original">{{ formatMoney(product.price) }}</span>
                } @else {
                  <span class="product-card__price-current">{{ formatMoney(product.price) }}</span>
                }
              </span>

              @if (product.rating !== null && product.rating !== undefined && product.review_count) {
                <span class="product-card__rating">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" />
                  </svg>
                  {{ product.rating.toFixed(1) }}
                  <span class="product-card__rating-count">({{ product.review_count }})</span>
                </span>
              }
            </div>
          </div>
        </a>
      } @else {
        <!-- Defensive: missing slug renders the card without an anchor.
             Same visual, no navigation. -->
        <div class="product-card" [class.is-out-of-stock]="!product.in_stock">
          <div class="product-card__image-wrap">
            @if (product.primary_image?.url) {
              <img [src]="product.primary_image!.url" [alt]="imageAlt()" loading="lazy" decoding="async" />
            } @else {
              <div class="product-card__fallback" aria-hidden="true">
                <span>{{ initial() }}</span>
              </div>
            }
          </div>
          <div class="product-card__meta">
            @if (product.vendor?.name) {
              <p class="product-card__vendor">{{ product.vendor!.name }}</p>
            }
            <h3 class="product-card__name">{{ product.name }}</h3>
            <div class="product-card__price-row">
              <span class="product-card__price">{{ formatMoney(product.price) }}</span>
            </div>
          </div>
        </div>
      }
    }
  `,
  styleUrl: './product-card.scss',
})
export class ProductCardComponent {
  /** The product to render. Null/undefined renders nothing. */
  @Input({ required: true }) product!: Product | null;

  /** Build the canonical /product/:slug URL. */
  productUrl(): string {
    return `/product/${this.product?.slug ?? ''}`;
  }

  /** Alt text for the product image. Falls back to the product name. */
  imageAlt(): string {
    return this.product?.primary_image?.alt
      || this.product?.name
      || 'Product image';
  }

  /** First character for the letter-fallback. */
  initial(): string {
    return (this.product?.name?.[0] ?? '?').toUpperCase();
  }

  /** True if there's a sale_price LOWER than the regular price. */
  isOnSale(): boolean {
    const p = this.product;
    if (!p?.sale_price?.amount) return false;
    return p.sale_price.amount < p.price.amount;
  }

  /**
   * Format a Money value. Drops trailing .00 because integer prices are
   * common in this catalog. Examples:
   *   { amount: 530, currency: 'AED' } → "AED 530"
   *   { amount: 1250.5, currency: 'AED' } → "AED 1,250.50"
   */
  formatMoney(money: Money): string {
    const amount = Number(money.amount);
    const isInt = Number.isInteger(amount);
    const formatted = isInt
      ? amount.toLocaleString('en-AE')
      : amount.toLocaleString('en-AE', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        });
    return `${money.currency} ${formatted}`;
  }

  /**
   * Like button click. Currently a no-op — wishlist functionality
   * lands in Phase 5 (local-first guest wishlist with merge on login).
   * The button intercepts the click so it doesn't trigger the parent
   * anchor's navigation.
   */
  onLikeClick(event: MouseEvent): void {
    event.preventDefault();
    event.stopPropagation();
    /* TODO Phase 5: invoke WishlistService.toggle(product.slug) */
  }
}
