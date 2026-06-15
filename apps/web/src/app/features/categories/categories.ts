import {
  Component,
  ChangeDetectionStrategy,
  inject,
  computed,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of } from 'rxjs';

import { RoutedHttpClient } from '../../core/http/routed-http-client';
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
 * Lists all visible product categories as a clickable grid, fetched
 * client-side on load.
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
  private routed = inject(RoutedHttpClient);
  private seo = inject(SeoService);

  /**
   * Loaded categories. Starts as null (= loading); becomes Category[]
   * once data arrives. Errors resolve to empty array so the page still
   * renders — caller-side error handling can be added later.
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
   * Fetch the category list from the API via the routed client, which
   * resolves 'GET /categories' to v3 (per ENDPOINT_ROUTING). Errors
   * degrade to an empty array so the page still renders.
   */
  private fetchCategories$() {
    return this.routed.get<Category[]>('GET /categories').pipe(
      map((envelope) => envelope.data),
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
