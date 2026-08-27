import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { TranslatePipe } from '@ngx-translate/core';
import { StoreCardComponent } from '../catalog/store-card';
import { StoreService, STORE_DIRECTORY_PAGE_SIZE } from '../catalog/store.service';
import type { Store } from '../catalog/store.model';
import type { FeaturedVendor } from '../catalog/store-card';

/**
 * /store, the store directory.
 *
 * Public storefront page (no auth guard). Two sections:
 *   1. Store Spotlight, featured stores rendered with the
 *      existing StoreCard (embedded product thumbnails + rating).
 *   2. All stores, a plain A-Z grid of every active store,
 *      with load-more pagination.
 *
 * Data
 * ----
 *   - getFeatured() for the spotlight strip (fails soft: an error or
 *     empty result simply hides the strip, the directory below is
 *     the real content).
 *   - StoreService directory accumulator for the grid (reset +
 *     loadMore on init; load-more button when hasMore).
 *
 * The featured strip and the directory are independent fetches; a
 * failure in one never blanks the other.
 */
@Component({
  selector: 'app-store-directory',
  standalone: true,
  imports: [NgIf, NgFor, TranslatePipe, StoreCardComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="store-directory" data-testid="store-directory-page">
      <div class="store-directory__container">
        <header class="store-directory__header">
          <h1 class="store-directory__title">
            {{ 'stores.directory.title' | translate }}
          </h1>
          <p class="store-directory__subtitle">
            {{ 'stores.directory.subtitle' | translate }}
          </p>
        </header>

        <!-- Store Spotlight (featured) -->
        <section
          *ngIf="featured().length > 0"
          class="store-directory__spotlight"
          aria-labelledby="spotlight-heading"
          data-testid="store-spotlight"
        >
          <h2 id="spotlight-heading" class="store-directory__section-title">
            {{ 'stores.directory.spotlightHeading' | translate }}
          </h2>
          <div class="store-directory__spotlight-grid">
            <ui-store-card
              *ngFor="let v of featured(); trackBy: trackBySlug"
              [vendor]="v"
            ></ui-store-card>
          </div>
        </section>

        <!-- All stores -->
        <section
          class="store-directory__all"
          aria-labelledby="all-heading"
        >
          <h2 id="all-heading" class="store-directory__section-title">
            {{ 'stores.directory.allHeading' | translate }}
          </h2>

          <ng-container *ngIf="stores().length > 0; else emptyOrLoading">
            <ul class="store-grid" role="list" data-testid="store-grid">
              <li
                *ngFor="let d of stores(); trackBy: trackBySlug"
                class="store-grid__item"
                data-testid="store-grid-item"
              >
                <ui-store-card [vendor]="d"></ui-store-card>
              </li>
            </ul>

            <div
              *ngIf="hasMore()"
              class="store-directory__load-more"
            >
              <button
                type="button"
                class="store-directory__load-more-btn"
                [disabled]="isLoading()"
                (click)="onLoadMore()"
                data-testid="store-load-more"
              >
                {{ (isLoading() ? 'common.loading' : 'stores.directory.loadMore') | translate }}
              </button>
            </div>
          </ng-container>

          <ng-template #emptyOrLoading>
            <div
              *ngIf="isLoading()"
              class="store-directory__loading"
              data-testid="store-loading"
            >
              {{ 'common.loading' | translate }}
            </div>
            <div
              *ngIf="!isLoading()"
              class="store-directory__empty"
              data-testid="store-empty"
            >
              {{ 'stores.directory.empty' | translate }}
            </div>
          </ng-template>
        </section>
      </div>
    </main>
  `,
  styleUrl: './store-directory.scss',
})
export class StoreDirectoryPageComponent implements OnInit {
  private readonly storeService = inject(StoreService);

  protected readonly stores = this.storeService.directory;
  protected readonly isLoading = this.storeService.isLoadingList;
  protected readonly hasMore = this.storeService.hasMore;

  private readonly _featured = signal<FeaturedVendor[]>([]);
  protected readonly featured = this._featured.asReadonly();

  async ngOnInit(): Promise<void> {
    /* Featured strip, fail soft (hide on error/empty). Fired without
       await so the directory load isn't blocked behind it. */
    void this.loadFeatured();

    this.storeService.reset();
    await this.onLoadMore();
  }

  private async loadFeatured(): Promise<void> {
    try {
      this._featured.set(await this.storeService.getFeatured());
    } catch {
      /* Hide the spotlight; the directory below is the real content. */
      this._featured.set([]);
    }
  }

  protected async onLoadMore(): Promise<void> {
    try {
      await this.storeService.loadMore({ limit: STORE_DIRECTORY_PAGE_SIZE });
    } catch {
      /* Leave whatever's loaded; the empty/grid state handles the rest. */
    }
  }

  protected trackBySlug(_idx: number, entity: { slug: string }): string {
    return entity.slug;
  }
}
