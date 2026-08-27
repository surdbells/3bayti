import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { ProductCardComponent } from '../catalog/product-card';
import { FilterBarComponent } from '../catalog/filter-bar';
import { CatalogService, type CatalogFilters, type CatalogSort } from '../categories/catalog.service';
import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema } from '../../core/seo/schema.helpers';
import { environment } from '../../../environments/environment';

/**
 * Route `data` contract for a curated product listing. One component
 * powers several "view all, sorted by X" pages (Best Sellers, New
 * Arrivals), each route supplies its own sort + copy keys so we don't
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
  /**
   * When true, this listing is restricted to on-sale products, the sale
   * filter is forced on every load (grid + facets + load-more) regardless
   * of the user's other filter selections. Powers the /discounted route.
   */
  saleOnly?: boolean;
}

/**
 * /best-sellers, /new-arrivals, curated product listings.
 *
 * A product grid sorted by the route's `sort`, with the shared FilterBar
 * (size / colour / price / sort) above it and "load more" pagination.
 * The route's sort is the *default*; users can refine via the bar.
 * Filter state is mirrored to the URL query string so a filtered view is
 * shareable, and read back from it on entry (deep links). Reuses
 * CatalogService (GET /products + /products/facets) and ProductCard.
 */
@Component({
  selector: 'app-product-listing',
  standalone: true,
  imports: [RouterLink, TranslatePipe, ProductCardComponent, FilterBarComponent],
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

        <app-filter-bar
          class="listing-page__filters"
          [filters]="activeFilters()"
          [facets]="facets()"
          (filterChange)="onFilterChange($event)"
        />

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
  private readonly router = inject(Router);
  private readonly catalog = inject(CatalogService);
  private readonly seo = inject(SeoService);

  /** Accumulated product list + paging state, owned by CatalogService. */
  protected readonly products = this.catalog.products;
  protected readonly hasMore = this.catalog.hasMore;
  protected readonly isLoading = this.catalog.isLoadingList;
  /** Disjunctive facet counts for the filter bar. */
  protected readonly facets = this.catalog.facets;

  /** Skeleton placeholders shown on first load. */
  protected readonly skeletons = Array.from({ length: 8 });

  /** i18n key segment for the active listing (e.g. 'bestSellers'). */
  protected readonly i18nKey = signal<string>('');

  /** Live filter state (sort + facet selections); drives the FilterBar. */
  protected readonly activeFilters = signal<CatalogFilters>({ sort: 'newest' });

  /** The route's default sort, used to keep it out of the URL when unchanged. */
  private baseSort: CatalogSort = 'newest';
  /** When true, every load pins the sale filter on (the /discounted route). */
  private saleOnly = false;
  private readonly page = signal(0);

  ngOnInit(): void {
    const data = this.route.snapshot.data as Partial<ProductListingRouteData>;
    this.baseSort = data.sort ?? 'newest';
    this.saleOnly = data.saleOnly === true;
    this.i18nKey.set(data.i18nKey ?? '');

    // Seed filter state from the URL query string (deep links / refresh).
    this.activeFilters.set(this.parseQuery());

    /* CatalogService is a shared singleton (also used by the category
       browser); reset its accumulator before loading this listing. */
    this.catalog.reset();
    this.page.set(0);
    void this.catalog.loadProducts(this.buildFilters(), 0, false);
    void this.catalog.loadFacets(this.buildFilters());

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

  /** Apply a new filter set: persist to URL, reset paging, reload. */
  protected onFilterChange(filters: CatalogFilters): void {
    this.activeFilters.set(filters);
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: this.toQueryParams(filters),
      replaceUrl: true,
    });
    this.catalog.reset();
    this.page.set(0);
    void this.catalog.loadProducts(this.buildFilters(), 0, false);
    void this.catalog.loadFacets(this.buildFilters());
  }

  /** Load the next page and append to the grid. */
  protected async loadMore(): Promise<void> {
    const next = this.page() + 1;
    this.page.set(next);
    await this.catalog.loadProducts(this.buildFilters(), next, true);
  }

  /**
   * Minimal filter payload for the API: sort plus only the facet fields
   * that are actually set. With no active facets and the default sort this
   * is exactly `{ sort }`, keeping curated listings as lean as before.
   */
  private buildFilters(): CatalogFilters {
    const f = this.activeFilters();
    const out: CatalogFilters = { sort: f.sort ?? this.baseSort };
    if (f.sizes?.length) out.sizes = f.sizes;
    if (f.colors?.length) out.colors = f.colors;
    if (f.minPrice != null) out.minPrice = f.minPrice;
    if (f.maxPrice != null) out.maxPrice = f.maxPrice;
    /* Discounted listing: pin the sale filter on so the grid, facets and
       "load more" only ever surface on-sale products, whatever else the
       shopper refines. */
    if (this.saleOnly) out.sale = true;
    return out;
  }

  /** Build filter state from the current URL query params (SSR-safe). */
  private parseQuery(): CatalogFilters {
    const qp = this.route.snapshot.queryParamMap;
    const filters: CatalogFilters = { sort: this.baseSort };
    const size = qp?.get('size');
    const color = qp?.get('color');
    const min = qp?.get('minPrice');
    const max = qp?.get('maxPrice');
    const sort = qp?.get('sort') as CatalogSort | null;
    if (size) filters.sizes = size.split(',').filter(Boolean);
    if (color) filters.colors = color.split(',').filter(Boolean);
    if (min != null && min !== '') filters.minPrice = Number(min);
    if (max != null && max !== '') filters.maxPrice = Number(max);
    if (sort) filters.sort = sort;
    return filters;
  }

  /** Serialise filter state to URL query params (empty fields → removed). */
  private toQueryParams(f: CatalogFilters): Record<string, string | null> {
    return {
      size: f.sizes?.length ? f.sizes.join(',') : null,
      color: f.colors?.length ? f.colors.join(',') : null,
      minPrice: f.minPrice != null ? String(f.minPrice) : null,
      maxPrice: f.maxPrice != null ? String(f.maxPrice) : null,
      sort: f.sort && f.sort !== this.baseSort ? f.sort : null,
    };
  }
}
