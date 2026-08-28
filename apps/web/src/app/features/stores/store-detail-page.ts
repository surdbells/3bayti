import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { ProductCardComponent } from '../catalog/product-card';
import { StoreService, DESIGNER_PAGE_SIZE } from '../catalog/store.service';
import type { Store, VendorLabel } from '../catalog/store.model';
import type { Product } from '../catalog/product.model';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';

/**
 * /store/:slug, a single store's page.
 *
 * Public storefront page. Layout:
 *   - Cover image banner (falls back to a gradient)
 *   - Header card: logo, name, verified badge, description (innerHTML)
 *   - Collection: product grid (ui-product-card) with load-more
 *
 * Data
 * ----
 *   - getBySlug(slug) for the header. A 404 (unknown / inactive slug)
 *     renders the inline not-found state (Q4.4) with a link back to
 *     /store, NOT a hard router error.
 *   - listProducts(slug, {limit, offset}) for the collection, with a
 *     local accumulator + hasMore for load-more (the service keeps
 *     product lists stateless so two store tabs don't collide).
 *
 * Description is rendered via [innerHTML]; the backend sanitises
 * vendor-authored HTML at write time (documented on the Store
 * model + VendorSerializer).
 *
 * SSR: prerendered for known vendor slugs at build time (see
 * app.routes.server.ts), runtime SSR for the long tail, same model
 * as /product/:slug. This is what makes the Y.4-D sitemap restoration
 * meaningful (crawlers hit real prerendered HTML, not a 404).
 */
@Component({
  selector: 'app-store-detail',
  standalone: true,
  imports: [CfImagePipe, NgIf, NgFor, RouterLink, TranslatePipe, ProductCardComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="store-detail" data-testid="store-detail-page">
      <ng-container *ngIf="!notFound(); else notFoundState">
        <ng-container *ngIf="store() !== null">
          <!-- Cover banner -->
          <div class="store-detail__cover" aria-hidden="true">
            <img
              *ngIf="(store()!.cover_image_url ?? '') !== ''; else coverBlank"
              [src]="store()!.cover_image_url | cfImage:'cover'"
              alt=""
            />
            <ng-template #coverBlank>
              <div class="store-detail__cover-blank"></div>
            </ng-template>
          </div>

          <div class="store-detail__container">
            <header class="store-detail__header">
              <div
                *ngIf="(store()!.logo_url ?? '') !== ''"
                class="store-detail__logo"
                aria-hidden="true"
              >
                <img [src]="store()!.logo_url" alt="" />
              </div>
              <div class="store-detail__heading">
                <h1 class="store-detail__name">
                  {{ store()!.name }}
                  <span
                    *ngIf="store()!.is_verified"
                    class="store-detail__verified"
                    [attr.title]="'stores.verified' | translate"
                    aria-hidden="true"
                  >✓</span>
                </h1>
                <p
                  *ngIf="(store()!.description ?? '') !== ''"
                  class="store-detail__description"
                  [innerHTML]="store()!.description"
                  data-testid="store-description"
                ></p>
                <a
                  [routerLink]="['/stores', store()!.slug, 'reviews']"
                  class="store-detail__reviews-link"
                  data-testid="store-reviews-link"
                >
                  {{ 'stores.reviews.readReviews' | translate }}
                  <span aria-hidden="true">›</span>
                </a>
              </div>
            </header>

            <section
              class="store-detail__collection"
              aria-labelledby="collection-heading"
            >
              <h2 id="collection-heading" class="store-detail__section-title">
                {{ 'stores.detail.productsHeading' | translate }}
              </h2>

              <!-- Collection filter chips (labels), mirrors the mobile
                   vendor storefront. Hidden when the store has no labels. -->
              <div
                *ngIf="labels().length > 0"
                class="store-detail__labels"
                data-testid="store-labels"
              >
                <button
                  type="button"
                  class="store-detail__label-chip"
                  [class.store-detail__label-chip--active]="activeLabel() === null"
                  [attr.aria-pressed]="activeLabel() === null"
                  (click)="selectLabel(null)"
                >
                  {{ 'stores.detail.allLabel' | translate }}
                </button>
                <button
                  *ngFor="let l of labels(); trackBy: trackLabel"
                  type="button"
                  class="store-detail__label-chip"
                  [class.store-detail__label-chip--active]="activeLabel() === l.slug"
                  [attr.aria-pressed]="activeLabel() === l.slug"
                  (click)="selectLabel(l.slug)"
                >
                  {{ l.name }}
                  <span class="store-detail__label-count">{{ l.count }}</span>
                </button>
              </div>

              <ng-container *ngIf="products().length > 0; else emptyOrLoadingProducts">
                <div class="store-detail__grid" data-testid="store-product-grid">
                  <ui-product-card
                    *ngFor="let p of products(); trackBy: trackById"
                    [product]="p"
                  />
                </div>

                <div
                  *ngIf="productsHasMore()"
                  class="store-detail__load-more"
                >
                  <button
                    type="button"
                    class="store-detail__load-more-btn"
                    [disabled]="isLoadingProducts()"
                    (click)="onLoadMoreProducts()"
                    data-testid="store-products-load-more"
                  >
                    {{ (isLoadingProducts() ? 'common.loading' : 'stores.detail.loadMore') | translate }}
                  </button>
                </div>
              </ng-container>

              <ng-template #emptyOrLoadingProducts>
                <div
                  *ngIf="isLoadingProducts()"
                  class="store-detail__loading"
                  data-testid="store-products-loading"
                >
                  {{ 'common.loading' | translate }}
                </div>
                <div
                  *ngIf="!isLoadingProducts()"
                  class="store-detail__empty"
                  data-testid="store-products-empty"
                >
                  {{ 'stores.detail.emptyProducts' | translate }}
                </div>
              </ng-template>
            </section>
          </div>
        </ng-container>
      </ng-container>

      <ng-template #notFoundState>
        <div class="store-detail__container">
          <div class="store-detail__not-found" data-testid="store-not-found">
            <h1 class="store-detail__not-found-title">
              {{ 'stores.detail.notFoundTitle' | translate }}
            </h1>
            <p class="store-detail__not-found-body">
              {{ 'stores.detail.notFoundBody' | translate }}
            </p>
            <a routerLink="/stores" class="store-detail__not-found-cta">
              {{ 'stores.detail.backToDirectory' | translate }}
            </a>
          </div>
        </div>
      </ng-template>
    </main>
  `,
  styleUrl: './store-detail.scss',
})
export class StoreDetailPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly storeService = inject(StoreService);

  private readonly _store = signal<Store | null>(null);
  protected readonly store = this._store.asReadonly();

  private readonly _notFound = signal<boolean>(false);
  protected readonly notFound = this._notFound.asReadonly();

  private readonly _products = signal<Product[]>([]);
  protected readonly products = this._products.asReadonly();

  private readonly _productsHasMore = signal<boolean>(false);
  protected readonly productsHasMore = this._productsHasMore.asReadonly();

  private readonly _isLoadingProducts = signal<boolean>(false);
  protected readonly isLoadingProducts = this._isLoadingProducts.asReadonly();

  /** The store's collection labels (chips). Empty when the store has none. */
  private readonly _labels = signal<VendorLabel[]>([]);
  protected readonly labels = this._labels.asReadonly();

  /** Currently selected label slug, or null for "All". */
  private readonly _activeLabel = signal<string | null>(null);
  protected readonly activeLabel = this._activeLabel.asReadonly();

  private slug = '';

  async ngOnInit(): Promise<void> {
    const slugParam = this.route.snapshot.paramMap.get('slug');
    if (slugParam === null || slugParam.trim() === '') {
      this._notFound.set(true);
      return;
    }
    this.slug = slugParam.trim();

    try {
      this._store.set(await this.storeService.getBySlug(this.slug));
    } catch {
      /* 404 / inactive → inline not-found (Q4.4), not a hard error. */
      this._notFound.set(true);
      return;
    }

    /* Store loaded, fetch the first page of their collection + the label
       chips. Labels are best-effort (a failure just hides the chip row). */
    await Promise.all([this.onLoadMoreProducts(), this.loadLabels()]);
  }

  private async loadLabels(): Promise<void> {
    try {
      this._labels.set(await this.storeService.listLabels(this.slug));
    } catch {
      /* No chips rather than a broken page. */
      this._labels.set([]);
    }
  }

  /** Switch the active collection filter and reload the grid from page 0. */
  protected async selectLabel(slug: string | null): Promise<void> {
    if (this._activeLabel() === slug) return;
    this._activeLabel.set(slug);
    this._products.set([]);
    this._productsHasMore.set(false);
    await this.onLoadMoreProducts();
  }

  protected async onLoadMoreProducts(): Promise<void> {
    if (this._isLoadingProducts()) return;
    this._isLoadingProducts.set(true);
    try {
      const page = await this.storeService.listProducts(this.slug, {
        limit: DESIGNER_PAGE_SIZE,
        offset: this._products().length,
        label: this._activeLabel() ?? undefined,
      });
      this._products.set([...this._products(), ...page.items]);
      this._productsHasMore.set(page.hasMore);
    } catch {
      /* Leave whatever's loaded; the empty/grid state covers the rest. */
    } finally {
      this._isLoadingProducts.set(false);
    }
  }

  protected trackById(_idx: number, p: { id: number }): number {
    return p.id;
  }

  protected trackLabel(_idx: number, l: VendorLabel): number {
    return l.id;
  }
}
