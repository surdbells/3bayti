import { Component, ChangeDetectionStrategy, Input } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';

import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import type { Collection } from './collection.model';
import { collectionUrl } from './collections.service';

/**
 * CollectionCard, the "shop by collection" tile shared by the home-page row
 * and the /collection index grid.
 *
 * Design: a ~4:5 rounded box whose BACKGROUND is the collection image
 * (`image_url`, cover-fit + centred) under a dark gradient scrim, with the
 * collection name centred both ways in white display serif (the
 * .hero-bento__tile-title language) and a small "N pieces" line beneath.
 * With no image the box falls back to a warm brand gradient with the same
 * soft texture as the hero promo tiles.
 *
 * The background layer is an <img alt=""> (object-fit: cover) rather than a
 * CSS background-image so it lazy-loads and goes through the Cloudflare
 * resize pipe like every other card image. It is decorative; the accessible
 * name comes from the anchor's aria-label (name + piece count).
 *
 * The whole card is one link to /collection/<slug> (slug only, never an id).
 * It is a plain [href] anchor, like the category tiles beside it and
 * ProductCard, not routerLink: the app router has no scroll restoration, so
 * a routerLink from the home row would open the collection page at the home
 * page's scroll offset instead of at its banner.
 * Sizing is owned by the parent (row track width or grid column); the card
 * fills its host and keeps the aspect ratio.
 */
@Component({
  selector: 'app-collection-card',
  standalone: true,
  imports: [TranslatePipe, CfImagePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (collection) {
      <a
        class="collection-card"
        [class.collection-card--no-image]="!collection.image_url"
        [href]="url()"
        [attr.aria-label]="
          (collection.product_count === 1 ? 'collections.cardAriaOne' : 'collections.cardAria')
            | translate: { name: collection.name, count: collection.product_count }
        "
        data-testid="collection-card"
      >
        @if (collection.image_url) {
          <img
            class="collection-card__bg"
            [src]="collection.image_url | cfImage: 'card'"
            alt=""
            loading="lazy"
            decoding="async"
            data-testid="collection-card-bg"
          />
        }
        <span class="collection-card__scrim" aria-hidden="true"></span>
        <span class="collection-card__content">
          <span class="collection-card__name">{{ collection.name }}</span>
          @if (collection.product_count > 0) {
            <span class="collection-card__count">
              {{ collection.product_count }}
              {{ (collection.product_count === 1 ? 'collections.piece' : 'collections.pieces') | translate }}
            </span>
          }
        </span>
      </a>
    }
  `,
  styleUrl: './collection-card.scss',
})
export class CollectionCardComponent {
  /** The collection to render. Null/undefined renders nothing. */
  @Input({ required: true }) collection!: Collection | null;

  /** Canonical /collection/:slug link target. */
  url(): string {
    return collectionUrl(this.collection?.slug ?? '');
  }
}
