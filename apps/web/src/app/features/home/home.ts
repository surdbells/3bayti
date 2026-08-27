import {
  Component,
  ChangeDetectionStrategy,
  computed,
  inject,
  signal,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NgTemplateOutlet } from '@angular/common';
import { RouterLink } from '@angular/router';
import { catchError, from, map, of } from 'rxjs';

import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { SeoService } from '../../core/seo/seo.service';
import { organizationSchema, websiteSchema } from '../../core/seo/schema.helpers';
import { environment } from '../../../environments/environment';
import { SkeletonShimmerComponent } from '../../shared/ui/skeleton-shimmer';
import { ProductStripComponent } from '../../shared/ui/product-strip';
import { CampaignSectionComponent } from './campaign-section';
import { TopSellersComponent } from './top-sellers';
import { AddPhonePromptComponent } from '../../shared/ui/add-phone-prompt';
import type { ActiveCampaigns } from '../campaigns/campaign.model';
import { StoreCardComponent } from '../catalog/store-card';
import { RecommendationsService } from '../catalog/recommendations.service';
import { SaleCountService } from '../../core/catalog/sale-count.service';
import type { Product } from '../catalog/product.model';
import { AuthService } from '../../core/auth/auth.service';
import type { Category } from '../categories/category.model';
import { categoryIconUrl } from '../categories/category-icons';
import { HomeDataService } from './home-data.service';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * Home page — the canonical "/" route.
 *
 * Phase 1 W2 assembly: hero refresh + categories grid + 3 product
 * strips (Featured / Best Sellers / New Arrivals) + Store
 * Spotlight + global footer (provided by app shell).
 *
 * Data:
 *   All 5 sections fetch client-side on load. If any single API call
 *   fails, that section silently omits itself rather than showing a
 *   broken state — see HomeDataService for error handling.
 *
 * SEO:
 *   - <title> + <meta description> set via SeoService
 *   - JSON-LD WebSite schema with SearchAction (already wired before
 *     Phase 1 W2; reused here)
 *   - JSON-LD Organization schema for brand identity
 *   - All product cards link to canonical /product/:slug URLs
 *   - All category tiles link to canonical /category/:slug URLs
 *   - All store cards link to /stores/:slug
 */
@Component({
  selector: 'app-home',
  standalone: true,
  imports: [
    SkeletonShimmerComponent,
    ProductStripComponent,
    StoreCardComponent,
    CampaignSectionComponent,
    TopSellersComponent,
    TranslatePipe,
    NgTemplateOutlet,
    RouterLink,
    AddPhonePromptComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './home.html',
  styleUrl: './home.scss',
})
export class HomeComponent {
  private routed = inject(RoutedHttpClient);
  private seo = inject(SeoService);
  private homeData = inject(HomeDataService);
  private auth = inject(AuthService);
  private recsService = inject(RecommendationsService);
  private saleCount = inject(SaleCountService);

  /* ----- Categories (one extra fetch beyond the 4 home-page endpoints)
   *
   * The home page wants the same categories list that /category renders,
   * via the routed.get<Category[]>('GET /categories') call (v3 through
   * ENDPOINT_ROUTING). ----------------------------------------------- */

  /** Categories — null while loading, Category[] once loaded. */
  readonly categories = toSignal(this.fetchCategories$(), { initialValue: null });

  /**
   * Total on-sale products — count badge on the Discounted category tile.
   * Shared with the header's Discounted nav badge via SaleCountService, so the
   * two surfaces stay in sync and the request fires at most once.
   */
  readonly discountedCount = this.saleCount.count;

  /* ----- Product strips: each is a signal that becomes data when the
   * Observable emits. null = loading state (renders skeletons), [] =
   * loaded but empty (strip silently omits itself), Product[] = real
   * data ready to render. ----------------------------------------- */
  readonly bestSellers  = toSignal(this.homeData.bestSellers$(),       { initialValue: null });
  readonly newArrivals  = toSignal(this.homeData.newArrivals$(),       { initialValue: null });
  readonly vendors      = toSignal(this.homeData.featuredVendors$(),   { initialValue: null });

  /* ----- Campaigns: the live Anniversary Deals + Flash Sale. The payload
   * is an object (server_now + the two campaigns), so the signal holds
   * ActiveCampaigns | null. Each section renders only when its campaign is
   * present; countdowns run against server_now. ------------------------- */
  readonly campaigns = toSignal(this.homeData.activeCampaigns$(), {
    initialValue: null as ActiveCampaigns | null,
  });
  readonly anniversary = computed(() => this.campaigns()?.anniversary ?? null);
  readonly flash       = computed(() => this.campaigns()?.flash ?? null);
  readonly serverNow   = computed(() => this.campaigns()?.server_now ?? new Date().toISOString());

  /* ----- "For you" strip — personalized (signed-in) OR a guest fallback.
     Signed-in users get the auth-gated recommendation engine (X.12 / W.1).
     Anonymous visitors can't — so rather than leave the slot empty (the page
     would go sparse below the fold for every signed-out visitor), guests get
     a "Trending now" strip sourced from the editorial `featured` ranking
     (HomeDataService.trending$ — deliberately distinct from Top Sellers'
     `popular` and New Arrivals' `newest`). Both paths resolve to a Product[]
     (possibly empty) and degrade to [] on error; the template still hides
     the strip when the resolved list is empty. ----- */
  readonly isGuestForYou = !this.auth.isAuthenticated();

  readonly forYou = toSignal(
    this.isGuestForYou
      ? this.homeData.trending$()
      : from(this.recsService.forMe()).pipe(
          map((recs) => recs.map((r) => r.product)),
          catchError(() => of([] as Product[])),
        ),
    { initialValue: [] as Product[] },
  );

  constructor() {
    // Shared on-sale count for the Discounted tile badge (idempotent load).
    this.saleCount.load();

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

  /* ----- Mobile-app store URLs (Get the app band) -----------------------
     Sourced from environment.appStores. Placeholder '#' until the operator
     fills in the live listings; the template degrades each badge to a
     non-navigating button while its URL is still '#'. ------------------- */
  // Widen to `string` so the template's `!== '#'` coming-soon check stays valid
  // even when the env literal is a real URL (`as const` would otherwise narrow
  // these to literal types and TS flags the comparison as non-overlapping).
  readonly appStoreUrl: string = environment.appStores.appStore;
  readonly playStoreUrl: string = environment.appStores.playStore;

  /* ----- Helpers (used in template) ------------------------------------- */

  /* ----- Store Spotlight reveal — show 6, then +6 per "Load more" tap.
     The featured-vendors feed is fetched up to 12 (curated, no pagination),
     so the button reveals what's already loaded, client-side. */
  readonly storesShown = signal(6);
  showMoreStores(): void {
    this.storesShown.update((n) => n + 6);
  }

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

  /* ----- Internal: categories fetch ------------------------------------- */

  private fetchCategories$() {
    return this.routed.get<Category[]>('GET /categories').pipe(
      map(envelope => envelope.data),
      /* Show every category on the home row — icon-less ones (e.g. pyjamas)
         fall back to a lettered tile in the template, so they're no longer
         hidden here. */
      /* Sort by product count DESC so the most-stocked categories
         appear first. Same sort as /category index uses. */
      map(cats => [...cats].sort((a, b) => b.product_count - a.product_count)),
      catchError(() => of([] as Category[])),
    );
  }
}
