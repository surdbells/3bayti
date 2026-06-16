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
import { DesignerCardComponent } from '../catalog/designer-card';
import { DesignerService } from '../catalog/designer.service';
import type { Designer } from '../catalog/designer.model';
import type { FeaturedVendor } from '../catalog/designer-card';

/**
 * /designer — the designer directory.
 *
 * Public storefront page (no auth guard). Two sections:
 *   1. Designer Spotlight — featured designers rendered with the
 *      existing DesignerCard (embedded product thumbnails + rating).
 *   2. All designers — a plain A-Z grid of every active designer,
 *      with load-more pagination.
 *
 * Data
 * ----
 *   - getFeatured() for the spotlight strip (fails soft: an error or
 *     empty result simply hides the strip — the directory below is
 *     the real content).
 *   - DesignerService directory accumulator for the grid (reset +
 *     loadMore on init; load-more button when hasMore).
 *
 * The featured strip and the directory are independent fetches; a
 * failure in one never blanks the other.
 */
@Component({
  selector: 'app-designer-directory',
  standalone: true,
  imports: [NgIf, NgFor, RouterLink, TranslatePipe, DesignerCardComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="designer-directory" data-testid="designer-directory-page">
      <div class="designer-directory__container">
        <header class="designer-directory__header">
          <h1 class="designer-directory__title">
            {{ 'designers.directory.title' | translate }}
          </h1>
          <p class="designer-directory__subtitle">
            {{ 'designers.directory.subtitle' | translate }}
          </p>
        </header>

        <!-- Designer Spotlight (featured) -->
        <section
          *ngIf="featured().length > 0"
          class="designer-directory__spotlight"
          aria-labelledby="spotlight-heading"
          data-testid="designer-spotlight"
        >
          <h2 id="spotlight-heading" class="designer-directory__section-title">
            {{ 'designers.directory.spotlightHeading' | translate }}
          </h2>
          <div class="designer-directory__spotlight-grid">
            <ui-designer-card
              *ngFor="let v of featured(); trackBy: trackBySlug"
              [vendor]="v"
            ></ui-designer-card>
          </div>
        </section>

        <!-- All designers -->
        <section
          class="designer-directory__all"
          aria-labelledby="all-heading"
        >
          <h2 id="all-heading" class="designer-directory__section-title">
            {{ 'designers.directory.allHeading' | translate }}
          </h2>

          <ng-container *ngIf="designers().length > 0; else emptyOrLoading">
            <ul class="designer-grid" role="list" data-testid="designer-grid">
              <li
                *ngFor="let d of designers(); trackBy: trackBySlug"
                class="designer-grid__item"
                data-testid="designer-grid-item"
              >
                <a
                  [routerLink]="['/stores', d.slug]"
                  class="designer-tile"
                  [attr.data-slug]="d.slug"
                >
                  <div class="designer-tile__media" aria-hidden="true">
                    <img
                      *ngIf="(d.cover_image_url ?? d.logo_url ?? '') !== ''; else tileBlank"
                      [src]="d.cover_image_url ?? d.logo_url"
                      alt=""
                      loading="lazy"
                    />
                    <ng-template #tileBlank>
                      <div class="designer-tile__media-blank"></div>
                    </ng-template>
                  </div>
                  <div class="designer-tile__body">
                    <h3 class="designer-tile__name">
                      {{ d.name }}
                      <span
                        *ngIf="d.is_verified"
                        class="designer-tile__verified"
                        [attr.title]="'designers.verified' | translate"
                        aria-hidden="true"
                      >✓</span>
                    </h3>
                  </div>
                </a>
              </li>
            </ul>

            <div
              *ngIf="hasMore()"
              class="designer-directory__load-more"
            >
              <button
                type="button"
                class="designer-directory__load-more-btn"
                [disabled]="isLoading()"
                (click)="onLoadMore()"
                data-testid="designer-load-more"
              >
                {{ (isLoading() ? 'common.loading' : 'designers.directory.loadMore') | translate }}
              </button>
            </div>
          </ng-container>

          <ng-template #emptyOrLoading>
            <div
              *ngIf="isLoading()"
              class="designer-directory__loading"
              data-testid="designer-loading"
            >
              {{ 'common.loading' | translate }}
            </div>
            <div
              *ngIf="!isLoading()"
              class="designer-directory__empty"
              data-testid="designer-empty"
            >
              {{ 'designers.directory.empty' | translate }}
            </div>
          </ng-template>
        </section>
      </div>
    </main>
  `,
  styleUrl: './designer-directory.scss',
})
export class DesignerDirectoryPageComponent implements OnInit {
  private readonly designerService = inject(DesignerService);

  protected readonly designers = this.designerService.directory;
  protected readonly isLoading = this.designerService.isLoadingList;
  protected readonly hasMore = this.designerService.hasMore;

  private readonly _featured = signal<FeaturedVendor[]>([]);
  protected readonly featured = this._featured.asReadonly();

  async ngOnInit(): Promise<void> {
    /* Featured strip — fail soft (hide on error/empty). Fired without
       await so the directory load isn't blocked behind it. */
    void this.loadFeatured();

    this.designerService.reset();
    await this.onLoadMore();
  }

  private async loadFeatured(): Promise<void> {
    try {
      this._featured.set(await this.designerService.getFeatured());
    } catch {
      /* Hide the spotlight; the directory below is the real content. */
      this._featured.set([]);
    }
  }

  protected async onLoadMore(): Promise<void> {
    try {
      await this.designerService.loadMore();
    } catch {
      /* Leave whatever's loaded; the empty/grid state handles the rest. */
    }
  }

  protected trackBySlug(_idx: number, entity: { slug: string }): string {
    return entity.slug;
  }
}
