import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
  PLATFORM_ID,
} from '@angular/core';
import { NgIf, NgFor, isPlatformBrowser } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import { ShareButtonsComponent } from '../../shared/ui/share-buttons';
import { AuthService } from '../../core/auth/auth.service';
import { HotlinkService } from '../../core/hotlinks/hotlink.service';
import { CartService } from '../../core/cart/cart.service';
import { ToastService } from '../../shared/forms';
import { WishlistService } from '../wishlist/wishlist.service';
import { StyleService } from './style.service';
import { ConciergeService, type RestyleResult } from '../ai-concierge/concierge.service';
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
  imports: [NgIf, NgFor, FormsModule, RouterLink, TranslatePipe, CfImagePipe, ShareButtonsComponent],
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
              <div class="style-detail__owner" *ngIf="isOwner()" data-testid="style-owner-actions">
                <a
                  [routerLink]="['/styles', style()!.slug, 'edit']"
                  class="style-detail__owner-btn"
                  data-testid="style-edit"
                >
                  {{ 'styles.detail.edit' | translate }}
                </a>
                <button
                  type="button"
                  class="style-detail__owner-btn is-danger"
                  [disabled]="deleting()"
                  (click)="onDelete()"
                  data-testid="style-delete"
                >
                  {{ (deleting() ? 'styles.detail.deleting' : 'styles.detail.delete') | translate }}
                </button>
              </div>
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

              <!-- Buy the look (adds every piece to the multi-vendor cart) + share. -->
              <div class="style-detail__actions-row">
                <button
                  type="button"
                  class="style-detail__buy-btn"
                  [disabled]="adding() || style()!.products.length === 0"
                  (click)="addTheLook()"
                  data-testid="style-add-look"
                >
                  {{ (adding() ? 'styles.detail.addingToCart' : 'styles.detail.addTheLook') | translate }}
                </button>
                <ui-share-buttons [url]="shareUrl()" [title]="style()!.name" />
              </div>
            </section>

            <!-- Restyle with Ain -->
            <section class="style-restyle" *ngIf="canRestyle()" data-testid="style-restyle">
              <h2 class="style-detail__section-title">{{ 'styles.restyle.heading' | translate }}</h2>
              <p class="style-restyle__intro">{{ 'styles.restyle.intro' | translate }}</p>
              <div class="style-restyle__form">
                <input
                  class="style-restyle__input"
                  type="text"
                  [(ngModel)]="restyleInstruction"
                  [placeholder]="'styles.restyle.placeholder' | translate"
                  [disabled]="restyleLoading()"
                />
                <button
                  type="button"
                  class="style-restyle__btn"
                  [disabled]="!restyleInstruction.trim() || restyleLoading()"
                  (click)="runRestyle()"
                >
                  {{ (restyleLoading() ? 'styles.restyle.working' : 'styles.restyle.cta') | translate }}
                </button>
              </div>
              <div class="style-restyle__chips">
                <button type="button" class="style-restyle__chip" *ngFor="let s of restyleSuggestions" (click)="useSuggestion(s)">
                  {{ s | translate }}
                </button>
              </div>

              <div class="style-restyle__preview" *ngIf="restylePreview() as pv">
                <p class="style-restyle__rationale" *ngIf="pv.rationale !== ''">{{ pv.rationale }}</p>
                <div class="style-detail__grid" *ngIf="pv.cards.length > 0">
                  <article class="style-product" *ngFor="let c of pv.cards; trackBy: trackById">
                    <a [routerLink]="['/product', c.slug]" class="style-product__image-wrap">
                      <img
                        *ngIf="(c.primary_image?.url ?? '') !== ''"
                        [src]="c.primary_image?.url | cfImage:'card'"
                        [alt]="c.name"
                        loading="lazy"
                        decoding="async"
                      />
                    </a>
                    <div class="style-product__meta">
                      <h3 class="style-product__name">{{ c.name }}</h3>
                      <p class="style-product__price">{{ c.price.amount }}</p>
                    </div>
                  </article>
                </div>
                <p class="style-restyle__empty" *ngIf="pv.cards.length === 0">{{ 'styles.restyle.noResults' | translate }}</p>
                <button
                  type="button"
                  class="style-restyle__save"
                  *ngIf="pv.cards.length > 0"
                  [disabled]="restyleSaving()"
                  (click)="saveRestyle()"
                >
                  {{ (restyleSaving() ? 'styles.restyle.saving' : 'styles.restyle.save') | translate }}
                </button>
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
  private readonly hotlinks = inject(HotlinkService);
  private readonly concierge = inject(ConciergeService);
  private readonly i18n = inject(TranslateService);
  private readonly cart = inject(CartService);
  private readonly toast = inject(ToastService);
  private readonly platformId = inject(PLATFORM_ID);

  private readonly _style = signal<Style | null>(null);
  protected readonly style = this._style.asReadonly();

  private readonly _notFound = signal<boolean>(false);
  protected readonly notFound = this._notFound.asReadonly();

  /** Buy-the-look / delete in-flight flags. */
  protected readonly adding = signal(false);
  protected readonly deleting = signal(false);

  // ── Restyle with Ain ──────────────────────────────────────────────────
  protected restyleInstruction = '';
  protected readonly restyleLoading = signal(false);
  protected readonly restyleSaving = signal(false);
  protected readonly restylePreview = signal<RestyleResult | null>(null);
  protected readonly restyleSuggestions = [
    'styles.restyle.s1',
    'styles.restyle.s2',
    'styles.restyle.s3',
  ];

  /** Restyle needs a signed-in user + a seed look with products. */
  protected canRestyle(): boolean {
    return this.auth.isAuthenticated() && (this.style()?.products.length ?? 0) > 0;
  }

  protected useSuggestion(key: string): void {
    this.restyleInstruction = this.i18n.instant(key);
  }

  protected async runRestyle(): Promise<void> {
    const slug = this.style()?.slug;
    const instruction = this.restyleInstruction.trim();
    if (!slug || instruction === '' || this.restyleLoading()) {
      return;
    }
    this.restyleLoading.set(true);
    this.restylePreview.set(null);
    try {
      this.restylePreview.set(await this.concierge.restyle(slug, instruction));
    } catch {
      this.restylePreview.set({ interactionId: null, cards: [], rationale: '' });
    } finally {
      this.restyleLoading.set(false);
    }
  }

  protected async saveRestyle(): Promise<void> {
    const preview = this.restylePreview();
    const seed = this.style();
    if (!preview || preview.cards.length === 0 || !seed || this.restyleSaving()) {
      return;
    }
    this.restyleSaving.set(true);
    try {
      const created = await this.styleService.createStyle({
        name: seed.name + ' — restyled',
        products: preview.cards.map((c) => c.id),
        source: 'ai',
        prompt: this.restyleInstruction.trim(),
        rationale: preview.rationale,
      });
      this.concierge.recordEvent('style_saved', {
        ...(preview.interactionId ? { interaction_id: preview.interactionId } : {}),
      });
      void this.router.navigate(['/styles', created.slug]);
    } catch {
      // keep the preview so the user can retry
    } finally {
      this.restyleSaving.set(false);
    }
  }

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
      return;
    }
    /* Upgrade the share link to a tracked Style-Me hotlink for signed-in
       sharers (best-effort — falls back to the plain /styles/:slug URL). */
    void this.prepareShareLink();
  }

  private async prepareShareLink(): Promise<void> {
    const slug = this.style()?.slug;
    if (slug === undefined || slug === '' || !this.auth.isAuthenticated()) {
      return;
    }
    const hotlink = await this.hotlinks.create('style', slug);
    if (hotlink !== null) {
      this._hotlinkUrl.set(hotlink.short_url);
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

  /** Whether the signed-in viewer owns this look (drives Edit/Delete). */
  protected isOwner(): boolean {
    return this.style()?.is_owner === true;
  }

  /** Absolute canonical URL for sharing this look. */
  /** A tracked Style-Me hotlink once created, else the plain storefront URL. */
  private readonly _hotlinkUrl = signal<string | null>(null);

  protected shareUrl(): string {
    const tracked = this._hotlinkUrl();
    if (tracked !== null) {
      return tracked;
    }
    const slug = this.style()?.slug ?? '';
    if (isPlatformBrowser(this.platformId) && typeof window !== 'undefined') {
      return `${window.location.origin}/styles/${slug}`;
    }
    return `/styles/${slug}`;
  }

  /**
   * Buy the look: add every bundled product to the (multi-vendor) cart,
   * then go to the cart. Each add is independent, an out-of-stock or
   * unorderable piece is skipped (partial success), mirroring the AI
   * Outfit page's addLook(). Uses the v3 product id, never total_price.
   */
  protected async addTheLook(): Promise<void> {
    const products = this.style()?.products ?? [];
    if (products.length === 0 || this.adding()) {
      return;
    }
    this.adding.set(true);
    let added = 0;
    for (const p of products) {
      try {
        await this.cart.addItem({
          product_id: p.id,
          quantity: 1,
          size: null,
          color: null,
          is_custom: false,
        });
        added++;
      } catch {
        /* Skip a piece that's out of stock / from a suspended store. */
      }
    }
    this.adding.set(false);
    if (added > 0) {
      this.toast.success('styles.detail.addedToCart', { count: added });
      void this.router.navigate(['/cart']);
    } else {
      this.toast.error('styles.detail.addFailed');
    }
  }

  /**
   * Soft-delete this look (owner only; the server re-checks ownership).
   * Confirms first, then routes back to the hub on success.
   */
  protected async onDelete(): Promise<void> {
    const s = this.style();
    if (!s || this.deleting()) {
      return;
    }
    if (
      isPlatformBrowser(this.platformId) &&
      typeof window !== 'undefined' &&
      !window.confirm(this.i18n.instant('styles.detail.deleteConfirm'))
    ) {
      return;
    }
    this.deleting.set(true);
    try {
      await this.styleService.deleteStyle(s.id);
      this.toast.success('styles.detail.deleted');
      void this.router.navigate(['/styles']);
    } catch {
      this.toast.error('styles.detail.deleteFailed');
      this.deleting.set(false);
    }
  }

  protected initialOf(p: StyleProduct): string {
    return (p.name ?? '?').trim().charAt(0).toUpperCase() || '?';
  }

  protected trackById(_idx: number, p: { id: number }): number {
    return p.id;
  }
}
