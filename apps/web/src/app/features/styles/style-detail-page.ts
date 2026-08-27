import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import { AuthService } from '../../core/auth/auth.service';
import { WishlistService } from '../wishlist/wishlist.service';
import { StyleService } from './style.service';
import type { Style, StyleProduct } from './style.model';

/**
 * /styles/:slug, a single style's page.
 *
 * Public storefront page. Layout:
 *   - Cover banner (style cover_image_url; falls back to a gradient).
 *   - Header: style name + item count + display total_price summary.
 *   - "Products in this style": each bundled product as a card with
 *     a "View product" link (-> PDP by slug) and an "Add to wishlist"
 *     action (reuses the web WishlistService; guests are routed to
 *     /login).
 *
 * Data
 * ----
 *   - getBySlug(slug). A 404 (unknown / inactive slug) renders the
 *     inline not-found state (link back to /styles), NOT a hard error.
 *
 * IMPORTANT: total_price is DISPLAY-ONLY (a string sum); it is never a
 * checkout amount. Wishlist + PDP links use each product's v3 `id`.
 */
@Component({
  selector: 'app-style-detail',
  standalone: true,
  imports: [NgIf, NgFor, RouterLink, TranslatePipe, CfImagePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="style-detail" data-testid="style-detail-page">
      <ng-container *ngIf="!notFound(); else notFoundState">
        <ng-container *ngIf="style() !== null">
          <!-- Cover banner -->
          <div class="style-detail__cover" aria-hidden="true">
            <img
              *ngIf="(style()!.cover_image_url ?? '') !== ''; else coverBlank"
              [src]="style()!.cover_image_url | cfImage:'cover'"
              alt=""
            />
            <ng-template #coverBlank>
              <div class="style-detail__cover-blank"></div>
            </ng-template>
          </div>

          <div class="style-detail__container">
            <header class="style-detail__header">
              <h1 class="style-detail__name">{{ style()!.name }}</h1>
              <p
                *ngIf="(style()!.description ?? '') !== ''"
                class="style-detail__description"
                data-testid="style-description"
              >{{ style()!.description }}</p>
              <p class="style-detail__count">
                {{ 'styles.itemCount' | translate:{ count: style()!.products.length } }}
              </p>
            </header>

            <section
              class="style-detail__products"
              aria-labelledby="style-products-heading"
            >
              <h2 id="style-products-heading" class="style-detail__section-title">
                {{ 'styles.detail.productsHeading' | translate }}
              </h2>

              <div class="style-detail__grid" data-testid="style-product-grid">
                <article
                  *ngFor="let p of style()!.products; trackBy: trackById"
                  class="style-product"
                  data-testid="style-product"
                >
                  <a [routerLink]="['/product', p.slug]" class="style-product__image-wrap">
                    <img
                      *ngIf="(p.primary_image_url ?? '') !== ''; else prodBlank"
                      [src]="p.primary_image_url | cfImage:'card'"
                      [alt]="p.name"
                      loading="lazy"
                      decoding="async"
                    />
                    <ng-template #prodBlank>
                      <div class="style-product__blank">
                        <span aria-hidden="true">{{ initialOf(p) }}</span>
                      </div>
                    </ng-template>
                  </a>

                  <div class="style-product__meta">
                    <h3 class="style-product__name">{{ p.name }}</h3>
                    <p class="style-product__price">{{ p.price }}</p>

                    <div class="style-product__actions">
                      <a
                        [routerLink]="['/product', p.slug]"
                        class="style-product__view"
                        data-testid="style-product-view"
                      >
                        {{ 'styles.detail.viewProduct' | translate }}
                      </a>
                      <button
                        type="button"
                        class="style-product__wishlist"
                        [class.is-saved]="isSaved(p.id)"
                        [attr.aria-pressed]="isSaved(p.id)"
                        (click)="onAddToWishlist(p)"
                        data-testid="style-product-wishlist"
                      >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                          <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
                        </svg>
                        {{ (isSaved(p.id) ? 'styles.savedToWishlist' : 'styles.addToWishlist') | translate }}
                      </button>
                    </div>
                  </div>
                </article>
              </div>

              <!-- Display-only total summary. NOT a checkout amount. -->
              <div class="style-detail__summary" data-testid="style-total">
                <span class="style-detail__summary-label">{{ 'styles.total' | translate }}</span>
                <span class="style-detail__summary-value">{{ style()!.total_price }}</span>
              </div>
            </section>
          </div>
        </ng-container>
      </ng-container>

      <ng-template #notFoundState>
        <div class="style-detail__container">
          <div class="style-detail__not-found" data-testid="style-not-found">
            <h1 class="style-detail__not-found-title">
              {{ 'styles.detail.notFoundTitle' | translate }}
            </h1>
            <p class="style-detail__not-found-body">
              {{ 'styles.detail.notFoundBody' | translate }}
            </p>
            <a routerLink="/styles" class="style-detail__not-found-cta">
              {{ 'styles.detail.backToHub' | translate }}
            </a>
          </div>
        </div>
      </ng-template>
    </main>
  `,
  styleUrl: './style-detail.scss',
})
export class StyleDetailPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly styleService = inject(StyleService);
  private readonly wishlist = inject(WishlistService);
  private readonly auth = inject(AuthService);

  private readonly _style = signal<Style | null>(null);
  protected readonly style = this._style.asReadonly();

  private readonly _notFound = signal<boolean>(false);
  protected readonly notFound = this._notFound.asReadonly();

  async ngOnInit(): Promise<void> {
    const slugParam = this.route.snapshot.paramMap.get('slug');
    if (slugParam === null || slugParam.trim() === '') {
      this._notFound.set(true);
      return;
    }
    try {
      this._style.set(await this.styleService.getBySlug(slugParam.trim()));
    } catch {
      /* 404 / inactive → inline not-found, not a hard router error. */
      this._notFound.set(true);
    }
  }

  /** Whether the product (by v3 id) is in the user's wishlist. */
  protected isSaved(productId: number): boolean {
    return this.wishlist.isSaved(productId);
  }

  /**
   * Add a bundled product to the wishlist using its v3 id. Guests are
   * routed to sign-in (the wishlist is an authenticated surface). The
   * WishlistService only reads `id` off the product, so the StyleProduct
   * id is sufficient.
   */
  protected async onAddToWishlist(p: StyleProduct): Promise<void> {
    if (!this.auth.isAuthenticated()) {
      void this.router.navigate(['/login']);
      return;
    }
    if (this.wishlist.isSaved(p.id)) return;
    try {
      /* WishlistService.add only consumes `product.id`; pass a minimal
         id-bearing object (the rest of Product is unused server-side). */
      await this.wishlist.add({ id: p.id } as { id: number } as never);
    } catch {
      /* Swallow, the heart simply won't flip; no destructive failure. */
    }
  }

  protected initialOf(p: StyleProduct): string {
    return (p.name ?? '?').trim().charAt(0).toUpperCase() || '?';
  }

  protected trackById(_idx: number, p: { id: number }): number {
    return p.id;
  }
}
