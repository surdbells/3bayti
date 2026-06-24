import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { StoreService, DESIGNER_PAGE_SIZE } from '../catalog/store.service';
import type { Store, StoreReview } from '../catalog/store.model';

/**
 * /stores/:slug/reviews — a store's public reviews.
 *
 * Surfaces the migrated store reviews (vendor-scoped, product_id null,
 * status 'approved') plus any product reviews, via
 * GET /v3/vendors/{slug}/reviews. This is also the target of
 * store-card.ts's vendorReviewsUrl(), so the rating chip on every store
 * card now leads somewhere real. Mirrors the mobile store-reviews page.
 *
 * Layout
 * ------
 *   - Header: store name + "Reviews" + a link back to the store page.
 *   - List: each review shows a 1–5 star rating, the reviewer name,
 *     an optional title, the comment, and the date.
 *   - Load-more pagination (meta.total drives hasMore) + empty state.
 *
 * Data
 * ----
 *   - getBySlug(slug) for the header (a 404 → inline not-found, same as
 *     the store detail page — never a hard router error).
 *   - listReviews(slug, {limit, offset}) for the list, accumulated
 *     locally (the service keeps reviews stateless).
 *
 * The store-load and reviews-load are independent: a reviews failure
 * leaves the header intact and shows the empty state; a store 404 shows
 * the not-found block.
 */
@Component({
  selector: 'app-store-reviews',
  standalone: true,
  imports: [NgIf, NgFor, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="store-reviews" data-testid="store-reviews-page">
      <ng-container *ngIf="!notFound(); else notFoundState">
        <div class="store-reviews__container">
          <header class="store-reviews__header">
            <a
              *ngIf="store() as s"
              [routerLink]="['/stores', s.slug]"
              class="store-reviews__back"
            >
              <span aria-hidden="true" class="store-reviews__back-icon">‹</span>
              {{ s.name }}
            </a>
            <h1 class="store-reviews__title">
              {{ 'stores.reviews.title' | translate }}
            </h1>
            <p
              *ngIf="total() > 0"
              class="store-reviews__count"
              data-testid="store-reviews-count"
            >
              {{ reviewCountLabel(total()) }}
            </p>
          </header>

          <ng-container *ngIf="reviews().length > 0; else emptyOrLoading">
            <ul class="store-reviews__list" data-testid="store-reviews-list">
              <li
                *ngFor="let r of reviews(); trackBy: trackById"
                class="store-reviews__item"
              >
                <div class="store-reviews__item-head">
                  <div
                    class="store-reviews__stars"
                    [attr.aria-label]="starsAria(r.star)"
                  >
                    <span
                      *ngFor="let s of stars; let i = index"
                      class="store-reviews__star"
                      [class.is-filled]="i < r.star"
                      aria-hidden="true"
                    >★</span>
                  </div>
                  <span
                    *ngIf="formatDate(r.created_at) as d"
                    class="store-reviews__date"
                  >{{ d }}</span>
                </div>

                <p class="store-reviews__author">
                  {{ r.reviewer || ('stores.reviews.anonymous' | translate) }}
                  <span
                    *ngIf="r.is_verified"
                    class="store-reviews__verified"
                    [attr.title]="'stores.reviews.verified' | translate"
                  >✓</span>
                </p>

                <h2 *ngIf="r.title" class="store-reviews__item-title">
                  {{ r.title }}
                </h2>
                <p *ngIf="r.comment" class="store-reviews__comment">
                  {{ r.comment }}
                </p>

                <p
                  *ngIf="r.vendor_reply"
                  class="store-reviews__reply"
                  data-testid="store-review-reply"
                >
                  <span class="store-reviews__reply-label">
                    {{ 'stores.reviews.vendorReply' | translate }}
                  </span>
                  {{ r.vendor_reply }}
                </p>
              </li>
            </ul>

            <div *ngIf="hasMore()" class="store-reviews__load-more">
              <button
                type="button"
                class="store-reviews__load-more-btn"
                [disabled]="isLoading()"
                (click)="onLoadMore()"
                data-testid="store-reviews-load-more"
              >
                {{ (isLoading() ? 'common.loading' : 'stores.reviews.loadMore') | translate }}
              </button>
            </div>
          </ng-container>

          <ng-template #emptyOrLoading>
            <div
              *ngIf="isLoading()"
              class="store-reviews__status"
              data-testid="store-reviews-loading"
            >
              {{ 'common.loading' | translate }}
            </div>
            <div
              *ngIf="!isLoading()"
              class="store-reviews__empty"
              data-testid="store-reviews-empty"
            >
              <h2 class="store-reviews__empty-title">
                {{ 'stores.reviews.emptyTitle' | translate }}
              </h2>
              <p class="store-reviews__empty-hint">
                {{ 'stores.reviews.emptyBody' | translate }}
              </p>
            </div>
          </ng-template>
        </div>
      </ng-container>

      <ng-template #notFoundState>
        <div class="store-reviews__container">
          <div class="store-reviews__not-found" data-testid="store-reviews-not-found">
            <h1 class="store-reviews__not-found-title">
              {{ 'stores.detail.notFoundTitle' | translate }}
            </h1>
            <p class="store-reviews__not-found-body">
              {{ 'stores.detail.notFoundBody' | translate }}
            </p>
            <a routerLink="/stores" class="store-reviews__not-found-cta">
              {{ 'stores.detail.backToDirectory' | translate }}
            </a>
          </div>
        </div>
      </ng-template>
    </main>
  `,
  styleUrl: './store-reviews.scss',
})
export class StoreReviewsPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly storeService = inject(StoreService);
  private readonly i18n = inject(TranslateService);

  private readonly _store = signal<Store | null>(null);
  protected readonly store = this._store.asReadonly();

  private readonly _notFound = signal<boolean>(false);
  protected readonly notFound = this._notFound.asReadonly();

  private readonly _reviews = signal<StoreReview[]>([]);
  protected readonly reviews = this._reviews.asReadonly();

  private readonly _total = signal<number>(0);
  protected readonly total = this._total.asReadonly();

  private readonly _hasMore = signal<boolean>(false);
  protected readonly hasMore = this._hasMore.asReadonly();

  private readonly _isLoading = signal<boolean>(false);
  protected readonly isLoading = this._isLoading.asReadonly();

  /** Five fixed positions for the star row. */
  protected readonly stars = [0, 1, 2, 3, 4] as const;

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
      /* Unknown / inactive slug → inline not-found, mirroring store detail. */
      this._notFound.set(true);
      return;
    }

    await this.onLoadMore();
  }

  protected async onLoadMore(): Promise<void> {
    if (this._isLoading()) return;
    this._isLoading.set(true);
    try {
      const page = await this.storeService.listReviews(this.slug, {
        limit: DESIGNER_PAGE_SIZE,
        offset: this._reviews().length,
      });
      this._reviews.set([...this._reviews(), ...page.items]);
      this._total.set(page.total);
      this._hasMore.set(page.hasMore);
    } catch {
      /* Leave whatever's loaded; the empty state covers a first-page failure. */
    } finally {
      this._isLoading.set(false);
    }
  }

  protected trackById(_idx: number, r: StoreReview): number {
    return r.id;
  }

  /** "1 review" vs "5 reviews", localised. */
  protected reviewCountLabel(count: number): string {
    return this.i18n.instant(
      count === 1 ? 'stores.reviews.countOne' : 'stores.reviews.countMany',
      { count },
    );
  }

  /** Accessible label for a star row, e.g. "4 / 5". */
  protected starsAria(star: number): string {
    return this.i18n.instant('stores.reviews.starsAria', { star });
  }

  /** Short, locale-aware date; null (omitted) when missing/invalid. */
  protected formatDate(iso: string | null | undefined): string | null {
    if (!iso) return null;
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return null;
    return date.toLocaleDateString('en-AE', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  }
}
