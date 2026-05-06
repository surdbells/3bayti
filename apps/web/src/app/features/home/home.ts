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
import { organizationSchema, websiteSchema } from '../../core/seo/schema.helpers';
import { environment } from '../../../environments/environment';
import { SkeletonShimmerComponent } from '../../shared/ui/skeleton-shimmer';
import { ProductStripComponent } from '../../shared/ui/product-strip';
import { HeroCarouselComponent } from '../../shared/ui/hero-carousel';
import { DesignerCardComponent } from '../catalog/designer-card';
import type { Category } from '../categories/category.model';
import { categoryIconUrl, categoryHasIcon } from '../categories/category-icons';
import { HomeDataService } from './home-data.service';

/**
 * Home page — the canonical "/" route.
 *
 * Phase 1 W2 assembly: hero refresh + categories grid + 3 product
 * strips (Featured / Best Sellers / New Arrivals) + Designer
 * Spotlight + global footer (provided by app shell).
 *
 * SSR strategy:
 *   Every section is server-rendered with TransferState-cached data.
 *   The browser never re-fetches on hydration — it picks up the
 *   pre-fetched data the server emitted in <script id="ng-state">.
 *
 *   When the API is up at build/prerender time (which it is for
 *   /), all 5 sections render with real content. If any single API
 *   call fails, that section silently omits itself rather than
 *   showing a broken state — see HomeDataService for error handling.
 *
 * SEO:
 *   - <title> + <meta description> set via SeoService
 *   - JSON-LD WebSite schema with SearchAction (already wired before
 *     Phase 1 W2; reused here)
 *   - JSON-LD Organization schema for brand identity
 *   - All product cards link to canonical /product/:slug URLs
 *   - All category tiles link to canonical /category/:slug URLs
 *   - All designer cards link to /designer/:slug
 */
@Component({
  selector: 'app-home',
  standalone: true,
  imports: [
    SkeletonShimmerComponent,
    ProductStripComponent,
    HeroCarouselComponent,
    DesignerCardComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './home.html',
  styleUrl: './home.scss',
})
export class HomeComponent {
  private api = inject(ApiClientService);
  private seo = inject(SeoService);
  private state = inject(TransferState);
  private platformId = inject(PLATFORM_ID);
  private homeData = inject(HomeDataService);

  /* ----- Categories (one extra fetch beyond the 4 home-page endpoints)
   *
   * The home page wants the same categories list that /category renders.
   * Reusing the existing ApiClient.getList<Category>('/categories') call
   * with its own TransferState key. The /category index page uses a
   * different key — that's intentional, so each page's data is cached
   * independently and a stale entry for one doesn't poison the other.
   * ----------------------------------------------------------------- */

  private readonly KEY_CATEGORIES = makeStateKey<Category[]>('home-categories');

  /** Categories — null while loading, Category[] once loaded. */
  readonly categories = toSignal(this.fetchCategories$(), { initialValue: null });

  /* ----- Product strips: each is a signal that becomes data when the
   * Observable emits. null = loading state (renders skeletons), [] =
   * loaded but empty (strip silently omits itself), Product[] = real
   * data ready to render. ----------------------------------------- */

  readonly featured     = toSignal(this.homeData.featuredProducts$(),  { initialValue: null });
  readonly bestSellers  = toSignal(this.homeData.bestSellers$(),       { initialValue: null });
  readonly newArrivals  = toSignal(this.homeData.newArrivals$(),       { initialValue: null });
  readonly vendors      = toSignal(this.homeData.featuredVendors$(),   { initialValue: null });

  constructor() {
    const siteUrl = environment.SITE_URL;

    /* Per-page SEO. Idempotent — calling set() updates in place. */
    this.seo.set({
      title: 'Premium Abayas, Kaftans & Modest Wear',
      description:
        'Discover handcrafted abayas, kaftans, and modest wear from independent ' +
        'designers across the UAE. Curated styles, made-to-measure fits, delivered ' +
        'to your door.',
      url: `${siteUrl}/`,
      type: 'website',
      titleSuffix: false,  // home title doesn't need " | 3bayti" appended
    });

    /* Organization + WebSite schema — establishes brand identity for
       search engines and enables Google's sitelinks search box once
       /search ships in Phase 6. */
    this.seo.setStructuredData([
      organizationSchema({
        name: '3bayti',
        url: `${siteUrl}/`,
        logo: `${siteUrl}/logo-1200.png`,
        sameAs: [
          // Add social profile URLs here as they're created
        ],
      }),
      websiteSchema({
        name: '3bayti',
        url: `${siteUrl}/`,
        searchUrlTemplate: `${siteUrl}/search?q={search_term_string}`,
      }),
    ]);
  }

  /* ----- Constants used in template ------------------------------------
   * The "This week's edit" heading uses a curly apostrophe (’) instead
   * of a straight one (') because (a) the typography is Playfair Display
   * which has a beautifully drawn typographic apostrophe, and (b) putting
   * a literal apostrophe in an Angular template attribute binding clashes
   * with the parser. Defining it as a TS string is the cleaner approach. */
  readonly featuredHeading = 'This week\u2019s edit';

  /* ----- Helpers (used in template) ------------------------------------- */

  /** Build the URL for a category's detail page. */
  categoryUrl(slug: string): string {
    return `/category/${slug}`;
  }

  /** First letter of category name, uppercased, for the letter avatar fallback. */
  initial(name: string): string {
    return (name?.[0] || '?').toUpperCase();
  }

  /** Resolve the icon URL for a category. Returns null for unmapped slugs. */
  iconFor(slug: string): string | null {
    return categoryIconUrl(slug);
  }

  /* ----- Internal: TransferState-cached categories fetch ---------------- */

  private fetchCategories$() {
    const cached = this.state.get(this.KEY_CATEGORIES, null);
    if (cached !== null) {
      return of(cached);
    }
    return this.api.getList<Category>('/categories').pipe(
      map(envelope => envelope.data),
      tap(categories => {
        if (isPlatformServer(this.platformId)) {
          this.state.set(this.KEY_CATEGORIES, categories);
        }
      }),
      /* Filter out categories without bundled icons (currently just
         pyjamas — see category-icons.ts). The home-page row only shows
         visually-coherent tiles; /category index still lists everything. */
      map(cats => cats.filter(c => categoryHasIcon(c.slug))),
      /* Sort by product count DESC so the most-stocked categories
         appear first. Same sort as /category index uses. */
      map(cats => [...cats].sort((a, b) => b.product_count - a.product_count)),
      catchError(() => of([] as Category[])),
    );
  }
}
