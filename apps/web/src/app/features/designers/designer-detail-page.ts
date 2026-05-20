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
import { DesignerService, DESIGNER_PAGE_SIZE } from '../catalog/designer.service';
import type { Designer } from '../catalog/designer.model';
import type { Product } from '../catalog/product.model';

/**
 * /designer/:slug — a single designer's page.
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
 *     /designer — NOT a hard router error.
 *   - listProducts(slug, {limit, offset}) for the collection, with a
 *     local accumulator + hasMore for load-more (the service keeps
 *     product lists stateless so two designer tabs don't collide).
 *
 * Description is rendered via [innerHTML]; the backend sanitises
 * vendor-authored HTML at write time (documented on the Designer
 * model + VendorSerializer).
 *
 * SSR: prerendered for known vendor slugs at build time (see
 * app.routes.server.ts), runtime SSR for the long tail — same model
 * as /product/:slug. This is what makes the Y.4-D sitemap restoration
 * meaningful (crawlers hit real prerendered HTML, not a 404).
 */
@Component({
  selector: 'app-designer-detail',
  standalone: true,
  imports: [NgIf, NgFor, RouterLink, TranslatePipe, ProductCardComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="designer-detail" data-testid="designer-detail-page">
      <ng-container *ngIf="!notFound(); else notFoundState">
        <ng-container *ngIf="designer() !== null">
          <!-- Cover banner -->
          <div class="designer-detail__cover" aria-hidden="true">
            <img
              *ngIf="(designer()!.cover_image_url ?? '') !== ''; else coverBlank"
              [src]="designer()!.cover_image_url"
              alt=""
            />
            <ng-template #coverBlank>
              <div class="designer-detail__cover-blank"></div>
            </ng-template>
          </div>

          <div class="designer-detail__container">
            <header class="designer-detail__header">
              <div
                *ngIf="(designer()!.logo_url ?? '') !== ''"
                class="designer-detail__logo"
                aria-hidden="true"
              >
                <img [src]="designer()!.logo_url" alt="" />
              </div>
              <div class="designer-detail__heading">
                <h1 class="designer-detail__name">
                  {{ designer()!.name }}
                  <span
                    *ngIf="designer()!.is_verified"
                    class="designer-detail__verified"
                    [attr.title]="'designers.verified' | translate"
                    aria-hidden="true"
                  >✓</span>
                </h1>
                <p
                  *ngIf="(designer()!.description ?? '') !== ''"
                  class="designer-detail__description"
                  [innerHTML]="designer()!.description"
                  data-testid="designer-description"
                ></p>
              </div>
            </header>

            <section
              class="designer-detail__collection"
              aria-labelledby="collection-heading"
            >
              <h2 id="collection-heading" class="designer-detail__section-title">
                {{ 'designers.detail.productsHeading' | translate }}
              </h2>

              <ng-container *ngIf="products().length > 0; else emptyOrLoadingProducts">
                <div class="designer-detail__grid" data-testid="designer-product-grid">
                  <ui-product-card
                    *ngFor="let p of products(); trackBy: trackById"
                    [product]="p"
                  />
                </div>

                <div
                  *ngIf="productsHasMore()"
                  class="designer-detail__load-more"
                >
                  <button
                    type="button"
                    class="designer-detail__load-more-btn"
                    [disabled]="isLoadingProducts()"
                    (click)="onLoadMoreProducts()"
                    data-testid="designer-products-load-more"
                  >
                    {{ (isLoadingProducts() ? 'common.loading' : 'designers.detail.loadMore') | translate }}
                  </button>
                </div>
              </ng-container>

              <ng-template #emptyOrLoadingProducts>
                <div
                  *ngIf="isLoadingProducts()"
                  class="designer-detail__loading"
                  data-testid="designer-products-loading"
                >
                  {{ 'common.loading' | translate }}
                </div>
                <div
                  *ngIf="!isLoadingProducts()"
                  class="designer-detail__empty"
                  data-testid="designer-products-empty"
                >
                  {{ 'designers.detail.emptyProducts' | translate }}
                </div>
              </ng-template>
            </section>
          </div>
        </ng-container>
      </ng-container>

      <ng-template #notFoundState>
        <div class="designer-detail__container">
          <div class="designer-detail__not-found" data-testid="designer-not-found">
            <h1 class="designer-detail__not-found-title">
              {{ 'designers.detail.notFoundTitle' | translate }}
            </h1>
            <p class="designer-detail__not-found-body">
              {{ 'designers.detail.notFoundBody' | translate }}
            </p>
            <a routerLink="/designer" class="designer-detail__not-found-cta">
              {{ 'designers.detail.backToDirectory' | translate }}
            </a>
          </div>
        </div>
      </ng-template>
    </main>
  `,
  styleUrl: './designer-detail.scss',
})
export class DesignerDetailPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly designerService = inject(DesignerService);

  private readonly _designer = signal<Designer | null>(null);
  protected readonly designer = this._designer.asReadonly();

  private readonly _notFound = signal<boolean>(false);
  protected readonly notFound = this._notFound.asReadonly();

  private readonly _products = signal<Product[]>([]);
  protected readonly products = this._products.asReadonly();

  private readonly _productsHasMore = signal<boolean>(false);
  protected readonly productsHasMore = this._productsHasMore.asReadonly();

  private readonly _isLoadingProducts = signal<boolean>(false);
  protected readonly isLoadingProducts = this._isLoadingProducts.asReadonly();

  private slug = '';

  async ngOnInit(): Promise<void> {
    const slugParam = this.route.snapshot.paramMap.get('slug');
    if (slugParam === null || slugParam.trim() === '') {
      this._notFound.set(true);
      return;
    }
    this.slug = slugParam.trim();

    try {
      this._designer.set(await this.designerService.getBySlug(this.slug));
    } catch {
      /* 404 / inactive → inline not-found (Q4.4), not a hard error. */
      this._notFound.set(true);
      return;
    }

    /* Designer loaded — fetch the first page of their collection. */
    await this.onLoadMoreProducts();
  }

  protected async onLoadMoreProducts(): Promise<void> {
    if (this._isLoadingProducts()) return;
    this._isLoadingProducts.set(true);
    try {
      const page = await this.designerService.listProducts(this.slug, {
        limit: DESIGNER_PAGE_SIZE,
        offset: this._products().length,
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
}
