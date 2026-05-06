import {
  Component,
  ChangeDetectionStrategy,
  inject,
  PLATFORM_ID,
  TransferState,
  makeStateKey,
  computed,
} from '@angular/core';
import { isPlatformServer } from '@angular/common';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, tap } from 'rxjs';

import { ApiClientService } from '../../core/api/api-client.service';
import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema } from '../../core/seo/schema.helpers';
import { environment } from '../../../environments/environment';
import {
  ContainerComponent,
  HeadingComponent,
  TextComponent,
  StackComponent,
} from '../../shared/ui';
import { Category } from './category.model';

/**
 * Categories index — `/category`.
 *
 * Lists all visible product categories as a clickable grid. Server-
 * rendered so search engines see the full list in the HTML (no
 * client-side fetching for crawlers).
 *
 * Why TransferState:
 *   The page renders during prerender via SSR. Without TransferState,
 *   the browser would re-fetch the same data after hydration —
 *   wasteful and visually disruptive. TransferState embeds the SSR
 *   response in a <script> tag in the HTML, and the browser picks up
 *   the cached data instead of making the round trip.
 *
 * Image handling:
 *   The backend returns Lucide icon names (e.g. "@tui.sparkles") in
 *   the `image.url` field, wrapped as if they were Cloudflare image
 *   URLs. Those URLs 404. Until the backend distinguishes icon names
 *   from image URLs (or hosts real category cover images), we render
 *   a letter-based fallback for the visual (first character of the
 *   category name in a brand-colored circle). When real images come
 *   online, the fallback will silently transition.
 */
@Component({
  selector: 'app-categories',
  standalone: true,
  imports: [
    ContainerComponent,
    HeadingComponent,
    TextComponent,
    StackComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './categories.html',
  styleUrl: './categories.scss',
})
export class CategoriesComponent {
  private api = inject(ApiClientService);
  private seo = inject(SeoService);
  private state = inject(TransferState);
  private platformId = inject(PLATFORM_ID);

  /** Cache key — must be stable across server/client for hydration to find it. */
  private readonly STATE_KEY = makeStateKey<Category[]>('categories-list');

  /**
   * Loaded categories. Starts as null (= loading); becomes Category[]
   * once data arrives (SSR or client). Errors resolve to empty array
   * so the page still renders — caller-side error handling can be
   * added later.
   */
  readonly categories = toSignal(this.fetchCategories$(), { initialValue: null });

  /** Convenience: sorted by product_count DESC so the most stocked
   *  categories surface first. */
  readonly sortedCategories = computed(() => {
    const cats = this.categories();
    if (!cats) return null;
    return [...cats].sort((a, b) => b.product_count - a.product_count);
  });

  /** Loading state — true until the first fetch resolves. */
  readonly loading = computed(() => this.categories() === null);

  constructor() {
    const siteUrl = environment.SITE_URL;

    /* SEO: indexable category index page. */
    this.seo.set({
      title: 'Shop by Category',
      description:
        'Browse abayas, kaftans, accessories and modest-wear collections from ' +
        'independent UAE designers. Find your style by category.',
      url: `${siteUrl}/category`,
      type: 'website',
    });

    /* Structured data: BreadcrumbList for SERP visibility. */
    this.seo.setStructuredData([
      breadcrumbSchema([
        { name: 'Home', url: `${siteUrl}/` },
        { name: 'Categories', url: `${siteUrl}/category` },
      ]),
    ]);
  }

  /**
   * Returns an Observable of Category[] that uses TransferState as a cache.
   *
   * On the server: fetch, store result in TransferState, return.
   * On the browser (after hydration): pull from TransferState (no re-fetch).
   * On the browser (no SSR cache, e.g. CSR-only build): fetch normally.
   */
  private fetchCategories$() {
    /* Browser-side: check if SSR seeded the cache. */
    const cached = this.state.get(this.STATE_KEY, null);
    if (cached !== null) {
      /* SSR-prerendered data is available — no need to fetch again. */
      return of(cached);
    }

    /* Cache miss — fetch from the API. Errors degrade to empty array
       so the page still renders (could swap to a "no data" UI in
       Phase 2 polish). */
    return this.api.getList<Category>('/categories').pipe(
      map((envelope) => envelope.data),
      tap((categories) => {
        /* Seed TransferState during SSR so the browser side picks it
           up after hydration without a re-fetch. */
        if (isPlatformServer(this.platformId)) {
          this.state.set(this.STATE_KEY, categories);
        }
      }),
      catchError(() => of([] as Category[])),
    );
  }

  /**
   * Build the URL for a category's detail page. Phase 2 will create
   * /category/:slug routes that this links to. Phase 1 just renders
   * the link with a `data-coming-soon` attribute for visual treatment.
   */
  categoryUrl(slug: string): string {
    return `/category/${slug}`;
  }

  /**
   * First letter of the category name, uppercased, for the letter
   * fallback avatar. Defensive against empty strings.
   */
  initial(name: string): string {
    return (name?.[0] || '?').toUpperCase();
  }
}
