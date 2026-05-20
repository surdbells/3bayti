import {
  Component,
  ChangeDetectionStrategy,
  inject,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { ProductCardComponent } from '../catalog/product-card';
import { ToastService } from '../../shared/forms';
import { WishlistService } from './wishlist.service';

/**
 * /account/wishlist — the user's saved products.
 *
 * Renders the saved products with the same ui-product-card used across
 * the catalogue (the heart on each card reflects + toggles saved
 * state, so un-saving here removes the card live via the service's
 * signal). Load-more pagination; empty state with a CTA to browse.
 */
@Component({
  selector: 'app-account-wishlist',
  standalone: true,
  imports: [NgIf, NgFor, RouterLink, TranslatePipe, ProductCardComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="account-wishlist" data-testid="account-wishlist-page">
      <div class="account-wishlist__container">
        <nav class="account-wishlist__breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ 'account.wishlist.title' | translate }}</span>
        </nav>

        <h1 class="account-wishlist__title">{{ 'account.wishlist.title' | translate }}</h1>

        <ng-container *ngIf="!isInitialLoading(); else loadingState">
          <ng-container *ngIf="products().length > 0; else emptyState">
            <div class="account-wishlist__grid">
              <ui-product-card
                *ngFor="let product of products(); trackBy: trackById"
                [product]="product"
              ></ui-product-card>
            </div>

            <div *ngIf="hasMore()" class="account-wishlist__load-more">
              <button
                type="button"
                class="account-wishlist__load-more-btn"
                [disabled]="isLoading()"
                (click)="onLoadMore()"
                data-testid="wishlist-load-more"
              >
                {{ (isLoading() ? 'common.loading' : 'account.wishlist.loadMore') | translate }}
              </button>
            </div>
          </ng-container>

          <ng-template #emptyState>
            <div class="account-wishlist__empty" data-testid="wishlist-empty">
              <p class="account-wishlist__empty-text">{{ 'account.wishlist.empty' | translate }}</p>
              <a routerLink="/" class="account-wishlist__empty-cta">
                {{ 'account.wishlist.browse' | translate }}
              </a>
            </div>
          </ng-template>
        </ng-container>

        <ng-template #loadingState>
          <div class="account-wishlist__loading" data-testid="wishlist-loading">
            {{ 'common.loading' | translate }}
          </div>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './account-wishlist.scss',
})
export class AccountWishlistPageComponent implements OnInit {
  private readonly wishlist = inject(WishlistService);
  private readonly toast = inject(ToastService);

  protected readonly products = this.wishlist.products;
  protected readonly isLoading = this.wishlist.isLoading;

  /** True only during the very first load (before any data arrives). */
  private hasLoadedOnce = false;
  protected isInitialLoading = (): boolean => this.isLoading() && !this.hasLoadedOnce;

  protected hasMore = (): boolean => this.wishlist.meta()?.has_more ?? false;

  async ngOnInit(): Promise<void> {
    try {
      await this.wishlist.load(24, 0);
    } catch {
      this.toast.error('account.wishlist.errors.loadFailed');
    } finally {
      this.hasLoadedOnce = true;
    }
  }

  protected async onLoadMore(): Promise<void> {
    try {
      await this.wishlist.loadMore();
    } catch {
      this.toast.error('account.wishlist.errors.loadFailed');
    }
  }

  protected trackById(_index: number, product: { id: number }): number {
    return product.id;
  }
}
