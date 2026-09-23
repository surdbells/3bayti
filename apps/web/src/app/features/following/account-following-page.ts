import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import { ToastService } from '../../shared/forms';
import { StoreService } from '../catalog/store.service';
import type { Store } from '../catalog/store.model';

/**
 * /account/following, the stores the user follows.
 *
 * Renders each followed store as a row (logo, name, description) with a link
 * to the storefront and an inline Unfollow that removes it from the list.
 * Load-more pagination; empty state with a CTA to browse stores. Mirrors the
 * wishlist page's shape.
 */
@Component({
  selector: 'app-account-following',
  standalone: true,
  imports: [NgIf, NgFor, RouterLink, TranslatePipe, CfImagePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="account-following" data-testid="account-following-page">
      <div class="account-following__container">
        <nav class="account-following__breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ 'account.following.title' | translate }}</span>
        </nav>

        <h1 class="account-following__title">{{ 'account.following.title' | translate }}</h1>

        <ng-container *ngIf="!isInitialLoading(); else loadingState">
          <ng-container *ngIf="stores().length > 0; else emptyState">
            <ul class="account-following__list" role="list">
              <li
                *ngFor="let store of stores(); trackBy: trackById"
                class="following-row"
                data-testid="following-row"
              >
                <a [routerLink]="['/stores', store.slug]" class="following-row__store">
                  <span class="following-row__logo" aria-hidden="true">
                    <img
                      *ngIf="(store.logo_url ?? '') !== ''; else logoBlank"
                      [src]="store.logo_url | cfImage:'thumb'"
                      alt=""
                      loading="lazy"
                      decoding="async"
                    />
                    <ng-template #logoBlank>
                      <span class="following-row__logo-blank">{{ initialOf(store) }}</span>
                    </ng-template>
                  </span>
                  <span class="following-row__text">
                    <span class="following-row__name">
                      {{ store.name }}
                      <span
                        *ngIf="store.is_verified"
                        class="following-row__verified"
                        [attr.title]="'stores.verified' | translate"
                        aria-hidden="true"
                      >✓</span>
                    </span>
                    <span
                      *ngIf="(store.description ?? '') !== ''"
                      class="following-row__desc"
                      [innerHTML]="store.description"
                    ></span>
                  </span>
                </a>
                <button
                  type="button"
                  class="following-row__unfollow"
                  [disabled]="isUnfollowing(store.id)"
                  (click)="unfollow(store)"
                  data-testid="following-unfollow"
                >
                  {{ (isUnfollowing(store.id) ? 'common.loading' : 'account.following.unfollow') | translate }}
                </button>
              </li>
            </ul>

            <div *ngIf="hasMore()" class="account-following__load-more">
              <button
                type="button"
                class="account-following__load-more-btn"
                [disabled]="isLoading()"
                (click)="onLoadMore()"
                data-testid="following-load-more"
              >
                {{ (isLoading() ? 'common.loading' : 'account.following.loadMore') | translate }}
              </button>
            </div>
          </ng-container>

          <ng-template #emptyState>
            <div class="account-following__empty" data-testid="following-empty">
              <p class="account-following__empty-text">{{ 'account.following.empty' | translate }}</p>
              <a routerLink="/stores" class="account-following__empty-cta">
                {{ 'account.following.browse' | translate }}
              </a>
            </div>
          </ng-template>
        </ng-container>

        <ng-template #loadingState>
          <div class="account-following__loading" data-testid="following-loading">
            {{ 'common.loading' | translate }}
          </div>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './account-following.scss',
})
export class AccountFollowingPageComponent implements OnInit {
  private readonly storeService = inject(StoreService);
  private readonly toast = inject(ToastService);

  private readonly _stores = signal<Store[]>([]);
  protected readonly stores = this._stores.asReadonly();

  private readonly _isLoading = signal<boolean>(false);
  protected readonly isLoading = this._isLoading.asReadonly();

  private readonly _hasMore = signal<boolean>(false);
  protected readonly hasMore = this._hasMore.asReadonly();

  private readonly _unfollowing = signal<ReadonlySet<number>>(new Set());

  private hasLoadedOnce = false;
  protected isInitialLoading = (): boolean => this._isLoading() && !this.hasLoadedOnce;

  async ngOnInit(): Promise<void> {
    await this.load(0);
    this.hasLoadedOnce = true;
  }

  private async load(offset: number): Promise<void> {
    this._isLoading.set(true);
    try {
      const page = await this.storeService.listFollowing({ offset });
      this._stores.set(offset === 0 ? page.items : [...this._stores(), ...page.items]);
      this._hasMore.set(page.hasMore);
    } catch {
      this.toast.error('account.following.errors.loadFailed');
    } finally {
      this._isLoading.set(false);
    }
  }

  protected onLoadMore(): void {
    void this.load(this._stores().length);
  }

  protected isUnfollowing(id: number): boolean {
    return this._unfollowing().has(id);
  }

  protected async unfollow(store: Store): Promise<void> {
    if (this.isUnfollowing(store.id)) {
      return;
    }
    this.setUnfollowing(store.id, true);
    try {
      await this.storeService.unfollowVendor(store.id);
      this._stores.set(this._stores().filter((s) => s.id !== store.id));
    } catch {
      this.toast.error('stores.followError');
    } finally {
      this.setUnfollowing(store.id, false);
    }
  }

  private setUnfollowing(id: number, on: boolean): void {
    const next = new Set(this._unfollowing());
    if (on) {
      next.add(id);
    } else {
      next.delete(id);
    }
    this._unfollowing.set(next);
  }

  protected initialOf(store: Store): string {
    return (store.name ?? '?').trim().charAt(0).toUpperCase() || '?';
  }

  protected trackById(_index: number, store: { id: number }): number {
    return store.id;
  }
}
