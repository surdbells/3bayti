import { Component, ElementRef, OnDestroy, OnInit, QueryList, ViewChild, ViewChildren } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Observable, Subject, Subscription, of } from 'rxjs';
import { catchError, debounceTime, map, switchMap, tap } from 'rxjs/operators';

import { NavigationHistoryService } from '../../../services/navigation-history.service';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { apiErrorMessage } from '../../../shared/http/api-error';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { AxComboboxComponent, AxComboboxOption } from '../../../shared/forms/ax-combobox.component';

/** One product in the collection's curated line-up (or a search/browse hit). */
interface CuratedProduct {
  /** v3 product id — what PUT /admin/collections/:id/products takes. */
  id: number;
  name: string;
  image: string | null;
  /** Regular price. */
  price: number | null;
  /** Set only when the product is genuinely on sale (> 0 and below `price`). */
  sale_price: number | null;
  vendor_name: string | null;
  /** false when the API says the product is out of stock (it won't show). */
  in_stock: boolean | null;
}

/** The "Add products" tabs: free-text search, or one of the browse views. */
type AddTab = 'search' | 'category' | 'best_sellers' | 'new_arrivals';
type BrowseMode = Exclude<AddTab, 'search'>;

/** One page request for the shared browse view. */
interface BrowseRequest {
  mode: BrowseMode;
  /** Category slug (By category only). */
  category: string | null;
  /** 1-based page number. */
  page: number;
}

interface BrowsePage {
  items: CuratedProduct[];
  total: number;
  hasMore: boolean;
}

/** Products per browse page. */
const BROWSE_PAGE_SIZE = 20;

/**
 * Edit an admin-curated storefront collection.
 *
 * The collection is addressed by its v3 id from the `?id=` query param
 * (/admin/collections/edit?id=12). The page edits the collection's own
 * fields (PUT /admin/collections/:id) and curates its products
 * (GET/PUT /admin/collections/:id/products): array order is the storefront
 * order, and the FIRST product's image fronts the collection card.
 *
 * Products are added by free-text search, or by browsing storefront-visible
 * products by category, best sellers or new arrivals (GET /products, paged).
 * Every add goes through the same guarded path (appendToCuration).
 */
@Component({
  selector: 'app-edit-collection',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent, AxComboboxComponent],
  templateUrl: './edit-collection.component.html',
  styleUrl: './edit-collection.component.css',
})
export class EditCollectionComponent implements OnInit, OnDestroy {
  ui = {
    is_loading: true,
    is_saving: false,
    products_loading: false,
    products_saving: false,
    /**
     * The curated list failed to load. Curation is locked until a retry
     * succeeds, so a save can't overwrite the real list with an empty one.
     */
    products_error: false,
    searching: false,
  };

  collectionId = 0;
  slug = '';

  form = {
    name: '',
    description: '',
    is_active: true,
    display_order: null as number | null,
  };

  /** Curated products, in storefront order (index 0 = cover). */
  items: CuratedProduct[] = [];
  /** Product ids as last loaded/saved — used to detect unsaved curation. */
  private savedIds: number[] = [];

  productQuery = '';
  searchResults: CuratedProduct[] = [];
  private search$ = new Subject<string>();
  private sub = new Subscription();
  @ViewChild('searchInput') private searchInput?: ElementRef<HTMLInputElement>;
  /** Polite screen-reader announcement for adds that show no toast. */
  announcement = '';

  // ── "Add products" tabs ──────────────────────────────────────────
  readonly addTabs: { id: AddTab; label: string; icon: string }[] = [
    { id: 'search', label: 'Search', icon: 'search' },
    { id: 'category', label: 'By category', icon: 'category' },
    { id: 'best_sellers', label: 'Best sellers', icon: 'trending_up' },
    { id: 'new_arrivals', label: 'New arrivals', icon: 'rocket_launch' },
  ];
  addTab: AddTab = 'search';
  @ViewChildren('addTabBtn') private addTabButtons?: QueryList<ElementRef<HTMLButtonElement>>;

  // ── Browse by category ───────────────────────────────────────────
  /** Flattened category tree; ids are slugs, labels carry the parent trail. */
  categoryOptions: AxComboboxOption[] = [];
  categories = { loading: false, loaded: false, error: '' };
  /** Selected category slug. */
  selectedCategory: string | null = null;

  // ── Shared paginated browse view (category / best sellers / new) ─
  browse = {
    loading: false,
    error: '',
    items: [] as CuratedProduct[],
    /** 1-based page currently shown (or loading). */
    page: 1,
    total: 0,
    /**
     * `total` belongs to the current view. False from a tab/category switch
     * until that view's first response lands, so the pager never pairs a
     * remembered page with a stale/zero total ("Page 3 of 1").
     */
    totalKnown: false,
    hasMore: false,
  };
  /**
   * The request the browse view is showing / loading. Null when nothing is
   * requested (Search tab, or By category with no category chosen).
   */
  browseRequest: BrowseRequest | null = null;
  /** Last page viewed per browse tab, so flipping tabs comes back to it. */
  private browsePages: Record<BrowseMode, number> = { category: 1, best_sellers: 1, new_arrivals: 1 };
  private browse$ = new Subject<BrowseRequest | null>();
  /** Memo for isAdded(): rebuilt whenever `items` is reassigned. */
  private addedIds: { items: CuratedProduct[]; ids: Set<number> } | null = null;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private navHistory: NavigationHistoryService,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    const rawId = this.route.snapshot.queryParamMap.get('id');
    const id = Number(rawId);
    if (!rawId || !Number.isInteger(id) || id <= 0) {
      this.toast.error('Missing or invalid collection id.');
      this.ui.is_loading = false;
      this.router.navigate(['/admin/collections']);
      return;
    }
    this.collectionId = id;

    this.sub.add(
      this.search$
        .pipe(
          debounceTime(300),
          switchMap((term) => this.searchProducts(term)),
        )
        .subscribe((results) => {
          this.ui.searching = false;
          // Ignore a late response if the box was cleared in the meantime.
          this.searchResults = this.productQuery.trim().length >= 2 ? results : [];
        }),
    );

    // Browse pages. switchMap cancels the in-flight request whenever a newer
    // one (another tab, category or page, or `null` for "nothing") is pushed,
    // so a slow older response can never land over the current view. The
    // identity check below is a second guard on top of that.
    this.sub.add(
      this.browse$
        .pipe(
          switchMap((req) =>
            req
              ? this.fetchBrowsePage(req).pipe(
                  map((page) => ({ req, page, err: null as unknown })),
                  catchError((err: unknown) => of({ req, page: null as BrowsePage | null, err })),
                )
              : of(null),
          ),
        )
        .subscribe((result) => {
          if (!result || result.req !== this.browseRequest) return;
          if (result.page && !result.page.items.length && result.req.page > 1) {
            // A remembered page that no longer exists (the list shrank):
            // step back to the real last page instead of an empty one.
            const last = Math.max(1, Math.ceil(result.page.total / BROWSE_PAGE_SIZE));
            if (last < result.req.page) {
              this.requestBrowse({ ...result.req, page: last });
              return;
            }
          }
          this.browse.loading = false;
          if (result.page) {
            this.browse.items = result.page.items;
            // A page that returned products exists, whatever the reported
            // total says, so the pager can never read "Page N of M" with N > M.
            const shownThrough = (result.req.page - 1) * BROWSE_PAGE_SIZE + result.page.items.length;
            this.browse.total = Math.max(result.page.total, shownThrough);
            this.browse.totalKnown = true;
            this.browse.hasMore = result.page.hasMore;
          } else {
            this.browse.items = [];
            this.browse.hasMore = false;
            this.browse.error = apiErrorMessage(result.err, 'Unable to load products.');
          }
        }),
    );

    this.loadCollection();
    this.loadProducts();
  }

  ngOnDestroy() {
    this.sub.unsubscribe();
  }

  // ── load ─────────────────────────────────────────────────────────
  private loadCollection() {
    this.ui.is_loading = true;
    this.adapter.get_v3('GET /admin/collections/:id', { params: { id: String(this.collectionId) } }).subscribe({
      next: (res: any) => {
        const c = res?.data ?? res ?? {};
        this.form = {
          name: c.name ?? c.collection ?? '',
          description: c.description ?? '',
          is_active: c.is_active ?? true,
          display_order: c.display_order ?? null,
        };
        this.slug = c.slug ?? '';
        this.ui.is_loading = false;
      },
      error: (err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load this collection.'));
        this.ui.is_loading = false;
        this.router.navigate(['/admin/collections']);
      },
    });
  }

  loadProducts() {
    this.ui.products_loading = true;
    this.ui.products_error = false;
    this.adapter
      .get_v3('GET /admin/collections/:id/products', { params: { id: String(this.collectionId) } })
      .subscribe({
        next: (res: any) => {
          const raw: any[] = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : res?.data?.items ?? [];
          this.items = raw.map((p) => this.productFromApi(p)).filter((p) => p.id > 0);
          this.savedIds = this.items.map((p) => p.id);
          this.ui.products_loading = false;
        },
        error: (err: any) => {
          this.toast.error(apiErrorMessage(err, 'Unable to load the collection\'s products.'));
          this.items = [];
          this.savedIds = [];
          this.ui.products_loading = false;
          this.ui.products_error = true;
        },
      });
  }

  // ── product search ───────────────────────────────────────────────
  onSearchChange(term: string) {
    this.productQuery = term;
    const t = term.trim();
    if (t.length < 2) {
      this.searchResults = [];
      this.ui.searching = false;
      // Push the short term through so any in-flight search is dropped.
      this.search$.next('');
      return;
    }
    // Spinner covers the debounce window too, so 'No matching products'
    // doesn't flash while the user is still typing.
    this.ui.searching = true;
    this.search$.next(t);
  }

  private searchProducts(term: string): Observable<CuratedProduct[]> {
    if (term.length < 2) return of([]);
    return this.adapter.get_v3('GET /products', { query: { q: term, limit: 8 } }).pipe(
      map((res: any) => {
        const raw: any[] = Array.isArray(res?.data) ? res.data : res?.data?.items ?? [];
        return raw.map((p) => this.productFromApi(p)).filter((p) => p.id > 0);
      }),
      catchError((err: any) => {
        this.toast.error(apiErrorMessage(err, 'Product search failed.'));
        return of([] as CuratedProduct[]);
      }),
    );
  }

  isAdded(id: number): boolean {
    if (this.addedIds?.items !== this.items) {
      this.addedIds = { items: this.items, ids: new Set(this.items.map((i) => i.id)) };
    }
    return this.addedIds.ids.has(id);
  }

  /**
   * Curation (add / remove / reorder) is locked while the saved list is
   * loading or failed to load (a save could otherwise overwrite the real list
   * with an empty one), and while a save is in flight: the PUT response
   * replaces `items`, so a change made meanwhile would be silently dropped.
   */
  get curationLocked(): boolean {
    return this.ui.products_loading || this.ui.products_error || this.ui.products_saving || this.ui.is_saving;
  }

  /** A save (products only, or the whole collection) is in flight. */
  get savingInFlight(): boolean {
    return this.ui.products_saving || this.ui.is_saving;
  }

  /**
   * The ONE add path for search and browse (no-op while curation is locked).
   * Skips products already curated (and repeats within `products`), appends
   * the rest in the given order and returns how many were added. Appending
   * is what flags unsaved changes (productsDirty).
   */
  private appendToCuration(products: CuratedProduct[]): number {
    if (this.curationLocked) return 0;
    const seen = new Set(this.items.map((i) => i.id));
    const fresh: CuratedProduct[] = [];
    for (const p of products) {
      if (p.id > 0 && !seen.has(p.id)) {
        seen.add(p.id);
        fresh.push(p);
      }
    }
    if (fresh.length) this.items = [...this.items, ...fresh];
    return fresh.length;
  }

  /**
   * Add one product (a search hit or a browse row). A browse row keeps its
   * single button, which just switches to an "Added" state, so keyboard focus
   * stays on it; a search hit's list closes, so focus returns to the search box.
   */
  addProduct(p: CuratedProduct, from: 'search' | 'browse' = 'search') {
    if (this.curationLocked) return;
    if (this.isAdded(p.id)) {
      // The browse button already reads "Added" (aria-disabled); stay quiet.
      if (from === 'search') this.toast.error('That product is already in this collection.');
      return;
    }
    if (!this.appendToCuration([p])) return;
    this.announce(`Added ${p.name} to the collection.`);
    if (from === 'search') {
      this.productQuery = '';
      this.searchResults = [];
      this.search$.next('');
      this.searchInput?.nativeElement.focus();
    }
  }

  /** Update the polite live region (cleared first so a repeat is re-read). */
  private announce(message: string) {
    this.announcement = '';
    setTimeout(() => (this.announcement = message), 50);
  }

  // ── "Add products" tabs ──────────────────────────────────────────
  selectAddTab(tab: AddTab) {
    if (tab === this.addTab) return;
    this.addTab = tab;
    if (tab === 'search') {
      this.requestBrowse(null);
      return;
    }
    if (tab === 'category') {
      if (!this.categories.loaded && !this.categories.loading) this.loadCategories();
      if (!this.selectedCategory) {
        this.requestBrowse(null);
        return;
      }
    }
    this.requestBrowse({
      mode: tab,
      category: tab === 'category' ? this.selectedCategory : null,
      page: this.browsePages[tab],
    });
  }

  /** ARIA tabs keyboard model: arrows / Home / End move + activate. */
  onAddTabKeydown(event: KeyboardEvent, index: number) {
    const total = this.addTabs.length;
    let next: number;
    switch (event.key) {
      case 'ArrowRight': next = (index + 1) % total; break;
      case 'ArrowLeft': next = (index - 1 + total) % total; break;
      case 'Home': next = 0; break;
      case 'End': next = total - 1; break;
      default: return;
    }
    event.preventDefault();
    this.selectAddTab(this.addTabs[next].id);
    this.addTabButtons?.get(next)?.nativeElement.focus();
  }

  // ── Browse: categories ───────────────────────────────────────────
  loadCategories() {
    this.categories = { loading: true, loaded: false, error: '' };
    this.adapter.get_v3('GET /categories').subscribe({
      next: (res: any) => {
        const tree: any[] = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : res?.data?.items ?? [];
        this.categoryOptions = this.flattenCategories(tree);
        this.categories = { loading: false, loaded: true, error: '' };
      },
      error: (err: any) => {
        this.categories = { loading: false, loaded: false, error: apiErrorMessage(err, 'Unable to load categories.') };
      },
    });
  }

  onCategoryChange(value: string | number | null) {
    const slug = value == null || value === '' ? null : String(value);
    if (slug === this.selectedCategory) return;
    this.selectedCategory = slug;
    this.browsePages.category = 1;
    if (this.addTab !== 'category') return;
    this.requestBrowse(slug ? { mode: 'category', category: slug, page: 1 } : null);
  }

  /** The chosen category has sub-categories (products are filed under the exact category). */
  get selectedCategoryHasChildren(): boolean {
    return !!this.categoryOptions.find((o) => o.id === this.selectedCategory)?.data?.hasChildren;
  }

  /**
   * Flatten the public category tree into picker options, depth-first so
   * children follow their parent. Labels carry the parent trail
   * ("Abayas › Open abayas") so same-named sub-categories are told apart
   * and searching a parent's name also finds its children.
   */
  private flattenCategories(nodes: any[], trail: string[] = [], out: AxComboboxOption[] = [], depth = 0): AxComboboxOption[] {
    if (!Array.isArray(nodes) || depth > 8) return out;
    for (const n of nodes) {
      const slug = typeof n?.slug === 'string' ? n.slug.trim() : '';
      const name = String(n?.name ?? slug ?? '').trim();
      const children: any[] = Array.isArray(n?.children) ? n.children : [];
      const path = name ? [...trail, name] : trail;
      if (slug) {
        out.push({ id: slug, label: path.join(' › ') || slug, data: { hasChildren: children.length > 0 } });
      }
      if (children.length) this.flattenCategories(children, path, out, depth + 1);
    }
    return out;
  }

  // ── Browse: shared paginated view ────────────────────────────────
  /**
   * Show `req` in the browse view (or clear it with null). Results of the
   * previous request are dropped at once, so a page/tab/category switch
   * never shows another view's products, and the stream's switchMap cancels
   * the previous request. The total is kept only while paging within the
   * same view so the pager stays put during the load.
   */
  private requestBrowse(req: BrowseRequest | null) {
    const prev = this.browseRequest;
    const sameView = !!req && !!prev && prev.mode === req.mode && prev.category === req.category;
    this.browseRequest = req;
    this.browse.items = [];
    this.browse.error = '';
    this.browse.loading = !!req;
    if (!sameView) {
      // New view: its total is unknown until the response lands, so the
      // pager is hidden meanwhile (see browse.totalKnown).
      this.browse.total = 0;
      this.browse.totalKnown = false;
      this.browse.hasMore = false;
    }
    if (req) {
      this.browse.page = req.page;
      this.browsePages[req.mode] = req.page;
    }
    this.browse$.next(req);
  }

  private fetchBrowsePage(req: BrowseRequest): Observable<BrowsePage> {
    const offset = (req.page - 1) * BROWSE_PAGE_SIZE;
    // Same sorts the storefront uses for its /best-sellers and /new-arrivals
    // pages; a category lists newest first. GET /products only returns
    // storefront-visible products (active, approved store).
    const query: Record<string, string | number> = {
      sort: req.mode === 'best_sellers' ? 'best_seller' : 'newest',
      limit: BROWSE_PAGE_SIZE,
      offset,
    };
    if (req.mode === 'category' && req.category) query['category'] = req.category;
    return this.adapter.get_v3('GET /products', { query }).pipe(
      map((res: any) => {
        const raw: any[] = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : res?.data?.items ?? [];
        const items = raw.map((p) => this.productFromApi(p)).filter((p) => p.id > 0);
        const meta = res?.meta ?? res?.data?.meta ?? {};
        const total = Number(meta.total);
        const hasMore = typeof meta.has_more === 'boolean' ? meta.has_more : null;
        const safeTotal = Number.isFinite(total) && total >= 0
          ? total
          : offset + raw.length + (hasMore ? 1 : 0);
        return {
          items,
          total: safeTotal,
          hasMore: hasMore ?? offset + raw.length < safeTotal,
        };
      }),
    );
  }

  /** Last page number for the current total (at least 1). */
  get browseLastPage(): number {
    return Math.max(1, Math.ceil(this.browse.total / BROWSE_PAGE_SIZE));
  }

  get browsePrevDisabled(): boolean {
    return !this.browseRequest || this.browse.page <= 1;
  }

  /**
   * Next is open while the total says there's a later page, or (once the
   * page has loaded) the API says has_more.
   */
  get browseNextDisabled(): boolean {
    if (!this.browseRequest) return true;
    const byTotal = this.browse.page < this.browseLastPage;
    const byFlag = !this.browse.loading && this.browse.hasMore;
    return !(byTotal || byFlag);
  }

  prevBrowsePage() {
    if (this.browsePrevDisabled || !this.browseRequest) return;
    this.requestBrowse({ ...this.browseRequest, page: this.browse.page - 1 });
  }

  nextBrowsePage() {
    if (this.browseNextDisabled || !this.browseRequest) return;
    this.requestBrowse({ ...this.browseRequest, page: this.browse.page + 1 });
  }

  retryBrowse() {
    if (this.browseRequest) this.requestBrowse({ ...this.browseRequest });
  }

  /** Products on the current page not yet in the collection. */
  get browseAddableCount(): number {
    return this.browse.items.filter((p) => !this.isAdded(p.id)).length;
  }

  /**
   * "Add all on this page" has nothing to do: loading, empty, every product
   * already added, or curation locked. Rendered as aria-disabled (not
   * `disabled`) so a click that adds the last products keeps focus on it.
   */
  get addAllDisabled(): boolean {
    return this.curationLocked || this.browse.loading || !this.browse.items.length || this.browseAddableCount === 0;
  }

  /** Append every not-yet-added product on this page, in displayed order. */
  addAllOnPage() {
    if (this.addAllDisabled) return;
    const added = this.appendToCuration(this.browse.items);
    if (added > 0) {
      this.toast.success(`Added ${added} product${added === 1 ? '' : 's'}.`);
    }
  }

  /** Heading for the browse list. */
  get browseTitle(): string {
    switch (this.browseRequest?.mode) {
      case 'category':
        return this.categoryOptions.find((o) => o.id === this.browseRequest?.category)?.label ?? 'Category';
      case 'best_sellers':
        return 'Best sellers';
      case 'new_arrivals':
        return 'New arrivals';
      default:
        return '';
    }
  }

  /** How the current browse list is ordered. */
  get browseHint(): string {
    switch (this.browseRequest?.mode) {
      case 'best_sellers':
        return 'Most units sold in the last 30 days, as on the storefront\'s Best sellers page.';
      case 'new_arrivals':
        return 'Newest first, as on the storefront\'s New arrivals page.';
      case 'category':
        return 'Newest first. Only products the storefront shows are listed.';
      default:
        return '';
    }
  }

  trackProductById = (_: number, p: CuratedProduct) => p.id;

  moveUp(index: number) {
    if (this.curationLocked || index <= 0) return;
    this.swap(index, index - 1);
  }

  moveDown(index: number) {
    if (this.curationLocked || index >= this.items.length - 1) return;
    this.swap(index, index + 1);
  }

  private swap(a: number, b: number) {
    const next = [...this.items];
    [next[a], next[b]] = [next[b], next[a]];
    this.items = next;
  }

  removeItem(index: number) {
    if (this.curationLocked) return;
    this.items = this.items.filter((_, i) => i !== index);
  }

  /** The curated list differs (membership or order) from what's saved. */
  get productsDirty(): boolean {
    if (this.ui.products_loading || this.ui.products_error) return false;
    const ids = this.items.map((p) => p.id);
    return ids.length !== this.savedIds.length || ids.some((id, i) => id !== this.savedIds[i]);
  }

  // ── save ─────────────────────────────────────────────────────────
  /**
   * Save the collection's fields, plus its product list when that has
   * unsaved changes, then return to the list.
   */
  save() {
    if (this.ui.is_saving || this.ui.products_saving) return;
    const name = this.form.name.trim();
    if (name.length === 0) {
      this.toast.error('Collection name cannot be empty');
      return;
    }
    const order = this.form.display_order;
    if (order != null && (!Number.isInteger(Number(order)) || Number(order) < 0)) {
      this.toast.error('Display order must be a whole number (0 or more).');
      return;
    }

    const body = {
      name,
      description: this.form.description?.trim() || null,
      is_active: !!this.form.is_active,
      display_order: order != null ? Number(order) : null,
    };

    this.ui.is_saving = true;
    this.adapter
      .put_v3('PUT /admin/collections/:id', body, { params: { id: String(this.collectionId) } })
      .subscribe({
        next: () => {
          if (!this.productsDirty) {
            this.ui.is_saving = false;
            this.toast.success('Collection saved.');
            this.router.navigate(['/admin/collections']);
            return;
          }
          // Details are saved; now persist the curated product list too.
          this.putProducts().subscribe({
            next: () => {
              this.ui.is_saving = false;
              this.toast.success('Collection and products saved.');
              this.router.navigate(['/admin/collections']);
            },
            error: (err: any) => {
              this.ui.is_saving = false;
              this.toast.error(
                apiErrorMessage(err, 'Collection details saved, but its product list could not be saved. Please try again.'),
              );
            },
          });
        },
        error: (err: any) => {
          this.ui.is_saving = false;
          this.toast.error(apiErrorMessage(err, 'Unable to save the collection.'));
        },
      });
  }

  /** Save only the curated product list (stays on the page). */
  saveProducts() {
    if (this.ui.products_saving || this.ui.is_saving || !this.productsDirty) return;
    this.putProducts().subscribe({
      next: () => this.toast.success('Collection products saved.'),
      error: (err: any) => this.toast.error(apiErrorMessage(err, 'Unable to save the collection\'s products.')),
    });
  }

  /**
   * PUT the curated ids in order. The response echoes the saved list (same
   * shape as GET), which becomes the new baseline.
   */
  private putProducts(): Observable<unknown> {
    this.ui.products_saving = true;
    const product_ids = this.items.map((p) => p.id);
    return this.adapter
      .put_v3('PUT /admin/collections/:id/products', { product_ids }, { params: { id: String(this.collectionId) } })
      .pipe(
        tap({
          next: (res: any) => {
            const raw: any[] | null = Array.isArray(res?.data) ? res.data : null;
            if (raw) {
              this.items = raw.map((p) => this.productFromApi(p)).filter((p) => p.id > 0);
            }
            this.savedIds = raw ? this.items.map((p) => p.id) : product_ids;
            this.ui.products_saving = false;
          },
          error: () => {
            this.ui.products_saving = false;
          },
        }),
      );
  }

  goBack() {
    this.navHistory.back('/admin/collections');
  }

  // ── helpers ──────────────────────────────────────────────────────
  private productFromApi(p: any): CuratedProduct {
    const src = p?.product ?? p ?? {};
    const price = this.priceOf(src);
    return {
      id: Number(src.id) || 0,
      name: src.name ?? '—',
      image: this.imageOf(src),
      price,
      sale_price: this.salePriceOf(src, price),
      vendor_name: src.vendor?.name ?? src.store_name ?? null,
      in_stock: typeof src.in_stock === 'boolean' ? src.in_stock : null,
    };
  }

  /**
   * Resolve a product's thumbnail URL across the shapes the API emits.
   * GET /v3/products (listShape) returns `primary_image` as an OBJECT
   * ({ url, alt, width, height }), NOT a string; binding that object straight
   * to [src] yields "[object Object]". Dig a plain URL out of whichever
   * shape is present.
   */
  private imageOf(p: any): string | null {
    const pi = p?.primary_image;
    if (typeof pi === 'string' && pi) return pi;
    if (pi && typeof pi === 'object' && pi.url) return pi.url;
    if (typeof p?.primary_image_url === 'string' && p.primary_image_url) return p.primary_image_url;
    if (typeof p?.image === 'string' && p.image) return p.image;
    const imgs = p?.images;
    if (Array.isArray(imgs) && imgs.length) {
      const first = imgs[0];
      if (typeof first === 'string') return first || null;
      if (first && typeof first === 'object' && first.url) return first.url;
    }
    return null;
  }

  private priceOf(p: any): number | null {
    return this.amountOf(p?.price);
  }

  /**
   * The sale price, only when the product is genuinely on sale: present,
   * above zero and below the regular price (the API already nulls a bogus
   * one; this keeps the display honest for any other shape).
   */
  private salePriceOf(p: any, price: number | null): number | null {
    const sale = this.amountOf(p?.sale_price);
    return sale != null && sale > 0 && price != null && sale < price ? sale : null;
  }

  /** Money as `{ amount }` (v3 shape), a number, or a numeric string. */
  private amountOf(v: any): number | null {
    const amt = v?.amount ?? v;
    return typeof amt === 'number' ? amt : (amt != null && !isNaN(Number(amt)) ? Number(amt) : null);
  }
}
