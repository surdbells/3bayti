import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import { AuthService } from '../../core/auth/auth.service';
import { StyleService, STYLE_HUB_PAGE_SIZE, type StyleTab } from './style.service';
import type { Style } from './style.model';

/**
 * /styles — the Style Hub.
 *
 * Public storefront page (no auth guard). Three tabs:
 *   1. Community  — GET /styles?type=community  (shopper-submitted).
 *   2. 3bayti     — GET /styles?type=editorial  (curated by 3bayti).
 *   3. My styles  — GET /me/styles (AUTH). For guests this tab shows a
 *      sign-in prompt INSTEAD of calling the endpoint.
 *
 * Each tab renders a responsive grid of style cards. A style card is a
 * collage of the first three product images (one large + two small),
 * the style name, an item count ("{n} items") and the display
 * total_price. Tapping a card routes to /styles/:slug.
 *
 * Pagination: load-more advances the offset on the ACTIVE tab via the
 * service accumulator (meta.has_more). Per-tab loading skeletons +
 * empty states. The three tab accumulators are independent — switching
 * tabs never refetches an already-loaded tab.
 */
@Component({
  selector: 'app-styles-hub',
  standalone: true,
  imports: [NgIf, NgFor, RouterLink, TranslatePipe, CfImagePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="styles-hub" data-testid="styles-hub-page">
      <div class="styles-hub__container">
        <header class="styles-hub__header">
          <h1 class="styles-hub__title">{{ 'styles.title' | translate }}</h1>
          <p class="styles-hub__subtitle">{{ 'styles.subtitle' | translate }}</p>
        </header>

        <!-- Tabs -->
        <div class="styles-hub__tabs" role="tablist" data-testid="styles-tabs">
          <button
            *ngFor="let t of tabs"
            type="button"
            role="tab"
            class="styles-hub__tab"
            [class.is-active]="activeTab() === t"
            [attr.aria-selected]="activeTab() === t"
            [attr.data-testid]="'styles-tab-' + t"
            (click)="onSelectTab(t)"
          >
            {{ 'styles.tabs.' + t | translate }}
          </button>
        </div>

        <!-- My styles tab, guest → sign-in prompt instead of fetching -->
        <section
          *ngIf="activeTab() === 'mine' && !isAuthenticated(); else tabContent"
          class="styles-hub__sign-in"
          data-testid="styles-sign-in"
        >
          <p class="styles-hub__sign-in-text">
            {{ 'styles.signInToView' | translate }}
          </p>
          <a routerLink="/login" class="styles-hub__sign-in-cta" data-testid="styles-sign-in-cta">
            {{ 'styles.signIn' | translate }}
          </a>
        </section>

        <ng-template #tabContent>
          <!-- Create-a-style entry point (My styles tab, authed only) -->
          <div
            *ngIf="activeTab() === 'mine' && isAuthenticated()"
            class="styles-hub__create"
          >
            <a
              routerLink="/styles/create"
              class="styles-hub__create-btn"
              data-testid="styles-create-cta"
            >
              {{ 'styles.create.cta' | translate }}
            </a>
          </div>

          <ng-container *ngIf="styles().length > 0; else emptyOrLoading">
            <ul class="styles-grid" role="list" data-testid="styles-grid">
              <li
                *ngFor="let s of styles(); trackBy: trackBySlug"
                class="styles-grid__item"
                data-testid="styles-grid-item"
              >
                <a [routerLink]="['/styles', s.slug]" class="style-card">
                  <div class="style-card__collage" aria-hidden="true">
                    <div class="style-card__collage-main">
                      <img
                        *ngIf="imageAt(s, 0) as img; else mainBlank"
                        [src]="img | cfImage:'card'"
                        alt=""
                        loading="lazy"
                        decoding="async"
                      />
                      <ng-template #mainBlank>
                        <div class="style-card__blank"></div>
                      </ng-template>
                    </div>
                    <div class="style-card__collage-side">
                      <div class="style-card__collage-cell">
                        <img
                          *ngIf="imageAt(s, 1) as img; else cellBlank1"
                          [src]="img | cfImage:'thumb'"
                          alt=""
                          loading="lazy"
                          decoding="async"
                        />
                        <ng-template #cellBlank1>
                          <div class="style-card__blank"></div>
                        </ng-template>
                      </div>
                      <div class="style-card__collage-cell">
                        <img
                          *ngIf="imageAt(s, 2) as img; else cellBlank2"
                          [src]="img | cfImage:'thumb'"
                          alt=""
                          loading="lazy"
                          decoding="async"
                        />
                        <ng-template #cellBlank2>
                          <div class="style-card__blank"></div>
                        </ng-template>
                      </div>
                    </div>
                  </div>

                  <div class="style-card__meta">
                    <h3 class="style-card__name">{{ s.name }}</h3>
                    <div class="style-card__facts">
                      <span class="style-card__count">
                        {{ 'styles.itemCount' | translate:{ count: s.products.length } }}
                      </span>
                      <span class="style-card__price">
                        {{ 'styles.total' | translate }} {{ s.total_price }}
                      </span>
                    </div>
                  </div>
                </a>
              </li>
            </ul>

            <div *ngIf="hasMore()" class="styles-hub__load-more">
              <button
                type="button"
                class="styles-hub__load-more-btn"
                [disabled]="isLoading()"
                (click)="onLoadMore()"
                data-testid="styles-load-more"
              >
                {{ (isLoading() ? 'common.loading' : 'styles.loadMore') | translate }}
              </button>
            </div>
          </ng-container>

          <ng-template #emptyOrLoading>
            <!-- Skeletons while the first page loads -->
            <ul
              *ngIf="isLoading()"
              class="styles-grid"
              role="list"
              aria-hidden="true"
              data-testid="styles-loading"
            >
              <li *ngFor="let s of skeletons" class="styles-grid__item">
                <div class="style-card style-card--skeleton">
                  <div class="style-card__collage">
                    <div class="style-card__collage-main style-card__skeleton-box"></div>
                    <div class="style-card__collage-side">
                      <div class="style-card__collage-cell style-card__skeleton-box"></div>
                      <div class="style-card__collage-cell style-card__skeleton-box"></div>
                    </div>
                  </div>
                  <div class="style-card__meta">
                    <div class="style-card__skeleton-line"></div>
                    <div class="style-card__skeleton-line style-card__skeleton-line--short"></div>
                  </div>
                </div>
              </li>
            </ul>

            <div
              *ngIf="!isLoading()"
              class="styles-hub__empty"
              data-testid="styles-empty"
            >
              {{ 'styles.empty.' + activeTab() | translate }}
            </div>
          </ng-template>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './styles-hub.scss',
})
export class StylesHubPageComponent implements OnInit {
  private readonly styleService = inject(StyleService);
  private readonly auth = inject(AuthService);

  protected readonly tabs: StyleTab[] = ['community', 'editorial', 'mine'];
  protected readonly skeletons = Array.from({ length: 6 });

  protected readonly isAuthenticated = this.auth.isAuthenticated;

  private readonly _activeTab = signal<StyleTab>('community');
  protected readonly activeTab = this._activeTab.asReadonly();

  /* Bind the visible signals to the active tab so the template reacts
     to both tab switches and the underlying accumulator. Reads the
     service's flat per-tab snapshot (no nested computeds recreated on
     each recompute). */
  protected readonly styles = computed(() => this.styleService.snapshot(this._activeTab()).items);
  protected readonly hasMore = computed(() => this.styleService.snapshot(this._activeTab()).hasMore);
  protected readonly isLoading = computed(() => this.styleService.snapshot(this._activeTab()).isLoading);

  async ngOnInit(): Promise<void> {
    await this.ensureLoaded('community');
  }

  protected async onSelectTab(tab: StyleTab): Promise<void> {
    if (this._activeTab() === tab) return;
    this._activeTab.set(tab);
    await this.ensureLoaded(tab);
  }

  /** Load a tab's first page on first visit (idempotent). */
  private async ensureLoaded(tab: StyleTab): Promise<void> {
    if (tab === 'mine' && !this.isAuthenticated()) return;
    /* Already has content or is mid-flight → no refetch. */
    if (this.styleService.itemsOf(tab).length > 0 || this.styleService.isLoadingOf(tab)) {
      return;
    }
    try {
      await this.styleService.loadMore(tab, { offset: 0, limit: STYLE_HUB_PAGE_SIZE });
    } catch {
      /* Leave the empty state; the per-tab empty message covers it. */
    }
  }

  protected async onLoadMore(): Promise<void> {
    try {
      await this.styleService.loadMore(this._activeTab(), { limit: STYLE_HUB_PAGE_SIZE });
    } catch {
      /* Leave whatever's loaded; the grid/empty state handles the rest. */
    }
  }

  /** First non-empty primary image at the given product index, if any. */
  protected imageAt(style: Style, index: number): string | null {
    const url = style.products[index]?.primary_image_url;
    return url && url !== '' ? url : null;
  }

  protected trackBySlug(_idx: number, s: { slug: string }): string {
    return s.slug;
  }
}
