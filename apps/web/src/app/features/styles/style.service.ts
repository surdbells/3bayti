import { Injectable, signal, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type {
  Style,
  StyleType,
  StyleListParams,
  StylesPage,
  CreateStyleInput,
} from './style.model';

/** Default page size for the Style Hub tab grids (load-more). */
export const STYLE_HUB_PAGE_SIZE = 12;

/** The three Style Hub tabs. 'mine' is the owner-scoped (auth) tab. */
export type StyleTab = 'community' | 'editorial' | 'mine';

/** Per-tab accumulator state held in the service. */
interface TabState {
  items: Style[];
  hasMore: boolean;
  isLoading: boolean;
}

const EMPTY_TAB: TabState = { items: [], hasMore: false, isLoading: false };

/**
 * StyleService, storefront reads for the Style Hub (Community,
 * Editorial/3bayti) and the owner-scoped "My styles" tab, plus the
 * single-style detail fetch and community style creation.
 *
 * Routing
 * -------
 * The public list/detail reads are catalog reads, so they go through
 * RoutedHttpClient (like StoreService / HomeDataService), NOT the
 * Bearer-bound direct client. The authenticated reads (GET /me/styles)
 * and the create mutation (POST /me/styles) also go through
 * RoutedHttpClient, the auth interceptor attaches the Bearer token to
 * the v3 host, exactly as it does for the other /me/* routes the web
 * app already calls through this client.
 *
 * State surface
 * -------------
 * Each hub tab accumulates across load-more pages and lives in the
 * service (mirrors StoreService.directory) so the hub can switch tabs
 * and scroll without re-fetching page 0 every time:
 *   - items(tab)      Signal-derived Style[]
 *   - hasMore(tab)    whether the last page reported more
 *   - isLoading(tab)  in-flight flag for that tab
 *
 * Single-style detail is NOT held in service signals, the detail page
 * owns it (two style pages could be open in different tabs; per-page
 * ownership avoids cross-contamination), exactly as the store-detail
 * page does.
 */
@Injectable({ providedIn: 'root' })
export class StyleService {
  private readonly http = inject(RoutedHttpClient);

  private readonly _tabs = signal<Record<StyleTab, TabState>>({
    community: { ...EMPTY_TAB },
    editorial: { ...EMPTY_TAB },
    mine: { ...EMPTY_TAB },
  });

  /**
   * Reactive read of a tab's full state. Callers wrap this in their own
   * `computed()` so reading inside a reactive context tracks the
   * underlying `_tabs` signal. Returns a snapshot object (never null).
   */
  snapshot(tab: StyleTab): TabState {
    return this._tabs()[tab];
  }

  /** Read a tab's accumulated styles (one-shot, non-reactive). */
  itemsOf(tab: StyleTab): Style[] {
    return this._tabs()[tab].items;
  }

  /** Whether the given tab is currently fetching (one-shot). */
  isLoadingOf(tab: StyleTab): boolean {
    return this._tabs()[tab].isLoading;
  }

  /** Reset a single tab's accumulator (call before a fresh load). */
  reset(tab: StyleTab): void {
    this.patchTab(tab, { ...EMPTY_TAB });
  }

  /** Reset every tab (e.g. on logout, to drop the owner-scoped list). */
  resetAll(): void {
    this._tabs.set({
      community: { ...EMPTY_TAB },
      editorial: { ...EMPTY_TAB },
      mine: { ...EMPTY_TAB },
    });
  }

  /**
   * Load the next page into a hub tab's accumulator. offset defaults to
   * the current loaded count (load-more); pass offset=0 explicitly for a
   * fresh load. Community/Editorial hit GET /styles?type=…; 'mine' hits
   * the authenticated GET /me/styles.
   */
  async loadMore(tab: StyleTab, params: StyleListParams = {}): Promise<void> {
    const limit = params.limit ?? STYLE_HUB_PAGE_SIZE;
    const current = this._tabs()[tab];
    const offset = params.offset ?? current.items.length;

    this.patchTab(tab, { isLoading: true });
    try {
      const page =
        tab === 'mine'
          ? await this.listMyStyles({ limit, offset })
          : await this.listStyles(tabToType(tab), { limit, offset });

      const next = this._tabs()[tab];
      this.patchTab(tab, {
        items: offset === 0 ? page.items : [...next.items, ...page.items],
        hasMore: page.hasMore,
        isLoading: false,
      });
    } catch (err) {
      this.patchTab(tab, { isLoading: false });
      throw err;
    }
  }

  /**
   * A page of public styles by type. Stateless, loadMore() owns the
   * accumulator. Returns { items, hasMore } from the envelope's
   * meta.has_more.
   */
  async listStyles(type: StyleType, params: StyleListParams = {}): Promise<StylesPage> {
    const limit = params.limit ?? STYLE_HUB_PAGE_SIZE;
    const offset = params.offset ?? 0;
    const env = await firstValueFrom(
      this.http.get<Style[]>('GET /styles', {
        query: { type, limit, offset },
      }),
    );
    return {
      items: Array.isArray(env.data) ? env.data : [],
      hasMore: env.meta?.has_more ?? false,
    };
  }

  /**
   * A page of the authenticated user's own styles (Bearer auth).
   * Stateless, loadMore('mine') owns the accumulator. Callers must
   * only invoke this for signed-in users (the hub shows a sign-in
   * prompt for guests rather than firing it).
   */
  async listMyStyles(params: StyleListParams = {}): Promise<StylesPage> {
    const limit = params.limit ?? STYLE_HUB_PAGE_SIZE;
    const offset = params.offset ?? 0;
    const env = await firstValueFrom(
      this.http.get<Style[]>('GET /me/styles', {
        query: { limit, offset },
      }),
    );
    return {
      items: Array.isArray(env.data) ? env.data : [],
      hasMore: env.meta?.has_more ?? false,
    };
  }

  /** Single style by slug. Throws on 404 (unknown / inactive). */
  async getBySlug(slug: string): Promise<Style> {
    const env = await firstValueFrom(
      this.http.get<Style>('GET /styles/:slug', {
        params: { slug },
      }),
    );
    return env.data;
  }

  /**
   * Create a community style from up to 4 v3 product ids. Returns the
   * created style. The API also accepts a CSV string for `products`;
   * we send the array (HttpClient serialises it as JSON).
   */
  async createStyle(input: CreateStyleInput): Promise<Style> {
    const env = await firstValueFrom(
      this.http.post<Style>('POST /me/styles', {
        body: { name: input.name, products: input.products },
      }),
    );
    return env.data;
  }

  /* ------ Internals ----------------------------------------------- */

  private patchTab(tab: StyleTab, patch: Partial<TabState>): void {
    const all = this._tabs();
    this._tabs.set({ ...all, [tab]: { ...all[tab], ...patch } });
  }
}

/** Map a hub tab to the API's style_type query value. */
function tabToType(tab: 'community' | 'editorial'): StyleType {
  return tab === 'editorial' ? 'editorial' : 'community';
}
