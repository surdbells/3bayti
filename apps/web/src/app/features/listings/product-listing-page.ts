import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { ProductCardComponent } from '../catalog/product-card';
import { CatalogService, type CatalogSort } from '../categories/catalog.service';
import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema } from '../../core/seo/schema.helpers';
import { environment } from '../../../environments/environment';

/**
 * Route `data` contract for a curated product listing. One component
 * powers several "view all, sorted by X" pages (Best Sellers, New
 * Arrivals) — each route supplies its own sort + copy keys so we don't
 * duplicate the grid/pagination/SEO plumbing.
 */
export interface ProductListingRouteData {
  /** Sort passed to GET /products. */
  sort: CatalogSort;
  /** i18n key segment under `listing.` (e.g. 'bestSellers'). */
  i18nKey: string;
  /** Canonical path for SEO + breadcrumb (e.g. '/best-sellers'). */
  canonicalPath: string;
  /** Crawler-facing English title (kept stable + locale-independent). */
  seoTitle: string;
  /** Crawler-facing English meta description. */
  seoDescription: string;
}

/**
 * /best-sellers, /new-arrivals — curated product listings.
 *
 * A flat, filter-free product grid sorted by the route's `sort`, with
 * "load more" pagination. Reuses CatalogService (GET /products) and the
 * shared ProductCard. Filters/facets are intentionally omitted — these
 * are curated entry points, not the faceted category browser.
 */
@Component({
  selector: 'app-product-listing',
  standalone: true,
  imports: [RouterLink, TranslatePipe, ProductCardComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="listing-page" data-testid="product-listing-page">
      <div class="listing-page__container">
        <nav class="listing-page__crumbs" [attr.aria-label]="'listing.breadcrumbAria' | translate">
          <a routerLink="/">{{ 'nav.home' | translate }}</a>
          <span class="listing-page__crumbs-sep" aria-hidden="true">/</span>
          <span aria-current="page">{{ titleKey() | translate }}</span>
        </nav>

        <header class="listing-page__header">
          <h1 class="listing-page__title" data-testid="listing-title">
            {{ titleKey() | translate }}
          </h1>
          <p class="listing-page__subtitle">{{ subtitleKey() | translate }}</p>
        </header>

        @if (isLoading() && products().length === 0) {
          <ul class="listing-page__grid" aria-hidden="true">
            @for (s of skeletons; track $index) {
              <li class="listing-skeleton"></li>
            }
          </ul>
        } @else if (products().length === 0) {
          <p class="listing-page__empty" data-testid="listing-empty">
            {{ emptyKey() | translate }}
          </p>
        } @else {
          <ul class="listing-page__grid" role="list" data-testid="listing-grid">
            @for (p of products(); track p.id) {
              <li><ui-product-card [product]="p" /></li>
            }
          </ul>

          @if (hasMore()) {
            <div class="listing-page__more">
              <button
                type="button"
                class="listing-page__more-btn"
                (click)="loadMore()"
                [disabled]="isLoading()"
                data-testid="listing-load-more"
              >
                {{ (isLoading() ? 'common.loading' : 'listing.loadMore') | translate }}
              </button>
            </div>
          }
        }
      </div>
    </main>
  `,
  styleUrl: './product-listing-page.scss',
})
export class ProductListingPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly catalog = inject(CatalogService);
  private readonly seo = inject(SeoService);

  /** Accumulated product list + paging state, owned by CatalogService. */
  protected readonly products = this.catalog.products;
  protected readonly hasMore = this.catalog.hasMore;
  protected readonly isLoading = this.catalog.isLoadingList;

  /** Skeleton placeholders shown on first load. */
  protected readonly skeletons = Array.from({ length: 8 });

  /** i18n key segment for the active listing (e.g. 'bestSellers'). */
  protected readonly i18nKey = signal<string>('');

  private sort: CatalogSort = 'newest';
  private readonly page = signal(0);

  ngOnInit(): void {
    const data = this.route.snapshot.data as Partial<ProductListingRouteData>;
    this.sort = data.sort ?? 'newest';
    this.i18nKey.set(data.i18nKey ?? '');

    /* CatalogService is a shared singleton (also used by the category
       browser); reset its accumulator before loading this listing. */
    this.catalog.reset();
    this.page.set(0);
    void this.catalog.loadProducts({ sort: this.sort }, 0, false);

    const url = `${environment.SITE_URL}${data.canonicalPath ?? '/'}`;
    this.seo.set({
      title: data.seoTitle ?? '3bayti',
      description: data.seoDescription ?? '',
      url,
      type: 'website',
    });
    this.seo.setStructuredData([
      breadcrumbSchema([
        { name: 'Home', url: `${environment.SITE_URL}/` },
        { name: data.seoTitle ?? '', url },
      ]),
    ]);
  }

  protected titleKey(): string {
    return `listing.${this.i18nKey()}.title`;
  }
  protected subtitleKey(): string {
    return `listing.${this.i18nKey()}.subtitle`;
  }
  protected emptyKey(): string {
    return `listing.${this.i18nKey()}.empty`;
  }

  /** Load the next page and append to the grid. */
  protected async loadMore(): Promise<void> {
    const next = this.page() + 1;
    this.page.set(next);
    await this.catalog.loadProducts({ sort: this.sort }, next, true);
  }
}
