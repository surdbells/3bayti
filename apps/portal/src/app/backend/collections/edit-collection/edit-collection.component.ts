import {
  Component,
  ElementRef,
  HostListener,
  OnDestroy,
  OnInit,
  QueryList,
  ViewChild,
  ViewChildren,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { CdkDragDrop, DragDropModule, moveItemInArray } from '@angular/cdk/drag-drop';
import { Observable, Subject, Subscription, of, throwError } from 'rxjs';
import { catchError, debounceTime, distinctUntilChanged, map, switchMap, tap } from 'rxjs/operators';

import { NavigationHistoryService } from '../../../services/navigation-history.service';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { PermissionService } from '../../../services/permission.service';
import { HotToastService } from '../../../shared/toast/toast.service';
import { apiErrorMessage } from '../../../shared/http/api-error';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { AxComboboxComponent, AxComboboxOption } from '../../../shared/forms/ax-combobox.component';
import { AxConfirmService } from '../../../shared/overlays/ax-confirm.service';
import {
  CollectionRef,
  CuratedProduct,
  ProductDetail,
  alsoInLabel,
  detailFromApi,
  productsFromApi,
} from './collection-builder.model';
import { CollectionMembershipCache } from './collection-membership-cache';
import {
  CollectionProductPanelComponent,
  PanelDetailState,
} from './product-panel/collection-product-panel.component';

/** The "Add products" tabs: free-text search, or one of the browse views. */
type AddTab = 'search' | 'category' | 'best_sellers' | 'new_arrivals';
type BrowseMode = AddTab;

/** One page request for the shared, paged product grid. */
interface BrowseRequest {
  mode: BrowseMode;
  /** Category slug (By category only). */
  category: string | null;
  /** Search term (Search only, 2+ characters). */
  query: string | null;
  /** 1-based page number. */
  page: number;
}

interface BrowsePage {
  items: CuratedProduct[];
  total: number;
  hasMore: boolean;
}

/** The collection's own fields, as edited on this page. */
interface DetailsForm {
  name: string;
  description: string;
  is_active: boolean;
}

/** How the curated list is laid out. */
type CuratedView = 'grid' | 'list';

/** Products per browse page (divides into 2, 3, 4 and 6 grid columns). */
export const BROWSE_PAGE_SIZE = 24;
/** Minimum search length (matches the API's search minimum). */
const SEARCH_MIN = 2;
const CURATED_VIEW_KEY = 'ax.collectionBuilder.curatedView';

/**
 * Edit an admin-curated storefront collection: its own fields plus a visual
 * product builder.
 *
 * The collection is addressed by its v3 id from the `?id=` query param
 * (/admin/collections/edit?id=12); the page follows that param, so a link to
 * another collection (from the details panel) re-loads in place. Fields save
 * through PUT /admin/collections/:id; the curated list through
 * PUT /admin/collections/:id/products (array order = storefront order; the
 * FIRST product's image fronts the collection card).
 *
 * Builder: a paged card grid (Search / By category / Best sellers / New
 * arrivals, all GET /products) beside the curated list, which reorders by
 * drag and drop (or Move earlier / later buttons). Every card shows whether
 * the product is in THIS collection (live, including unsaved adds) and how
 * many OTHER collections it is in (GET /admin/collections/memberships,
 * batched per page and cached). Clicking a card opens a details panel.
 * Products may belong to any number of collections; nothing here blocks it.
 * Every add goes through the same guarded path (appendToCuration).
 */
@Component({
  selector: 'app-edit-collection',
  standalone: true,
  imports: [
    AdminShellComponent,
    CommonModule,
    FormsModule,
    RouterLink,
    DragDropModule,
    IconComponent,
    AxComboboxComponent,
    CollectionProductPanelComponent,
  ],
  templateUrl: './edit-collection.component.html',
  styleUrl: './edit-collection.component.css',
})
export class EditCollectionComponent implements OnInit, OnDestroy {
  ui = {
    is_loading: true,
    /** A save (details and/or products) is in flight. */
    is_saving: false,
    products_loading: false,
    products_saving: false,
    /**
     * The curated list failed to load. Curation is locked until a retry
     * succeeds, so a save can't overwrite the real list with an empty one.
     */
    products_error: false,
    /** A search term is waiting out the debounce. */
    searching: false,
  };

  collectionId = 0;
  slug = '';

  form: DetailsForm = { name: '', description: '', is_active: true };
  /** The fields as last loaded/saved (unsaved-changes + Discard). */
  private savedForm: DetailsForm = { name: '', description: '', is_active: true };

  /** Curated products, in storefront order (index 0 = cover). */
  items: CuratedProduct[] = [];
  /** The curated list as last loaded/saved (unsaved changes + Discard). */
  private savedItems: CuratedProduct[] = [];
  /** Product ids as last loaded/saved. */
  private savedIds: number[] = [];
  private savedIdSet = new Set<number>();

  /**
   * Set when a save found that this collection's products had been changed
   * somewhere else (a product page, another tab) since they were loaded, and
   * merged those changes into the list instead of overwriting them.
   */
  mergeNotice = '';

  productQuery = '';
  private search$ = new Subject<string>();
  private readonly sub = new Subscription();
  @ViewChild('searchInput') private searchInput?: ElementRef<HTMLInputElement>;
  /** Polite screen-reader announcement (adds, removes, moves). */
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

  // ── Shared paginated product grid (all four tabs) ────────────────
  browse = {
    loading: false,
    error: '',
    items: [] as CuratedProduct[],
    /** 1-based page currently shown (or loading). */
    page: 1,
    total: 0,
    /**
     * `total` belongs to the current view. False from a tab/category/search
     * switch until that view's first response lands, so the pager never
     * pairs a remembered page with a stale/zero total ("Page 3 of 1").
     */
    totalKnown: false,
    hasMore: false,
  };
  /**
   * The request the grid is showing / loading. Null when nothing is
   * requested (Search with < 2 characters, or By category with no category).
   */
  browseRequest: BrowseRequest | null = null;
  /** Last page viewed per tab, so flipping tabs comes back to it. */
  private browsePages: Record<BrowseMode, number> = { search: 1, category: 1, best_sellers: 1, new_arrivals: 1 };
  private browse$ = new Subject<BrowseRequest | null>();
  /** Memo for isAdded(): rebuilt whenever `items` is reassigned. */
  private addedIds: { items: CuratedProduct[]; ids: Set<number> } | null = null;

  // ── Memberships (contract A) ─────────────────────────────────────
  readonly memberships: CollectionMembershipCache;
  /** Memo for othersFor(): cached membership array → its "other" subset. */
  private othersMemo = new WeakMap<CollectionRef[], CollectionRef[]>();

  // ── Curated list layout ──────────────────────────────────────────
  curatedView: CuratedView = 'grid';
  /** Set on drop, so the click that ends a drag never opens the panel. */
  private lastDropAt = 0;

  // ── Details panel ────────────────────────────────────────────────
  panel: { product: CuratedProduct; detail: ProductDetail | null; state: PanelDetailState } | null = null;
  private panelReturnFocus: HTMLElement | null = null;
  private detail$ = new Subject<number | null>();
  private detailCache = new Map<number, ProductDetail>();

  readonly skeletonCards = Array.from({ length: 8 }, (_, i) => i);
  /** Image URLs that failed to load: their cards show the placeholder. */
  readonly brokenImages = new Set<string>();

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private navHistory: NavigationHistoryService,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
    private confirm: AxConfirmService,
    private perms: PermissionService,
    private host: ElementRef<HTMLElement>,
  ) {
    this.memberships = new CollectionMembershipCache((ids) =>
      this.adapter.get_v3('GET /admin/collections/memberships', { query: { product_ids: ids.join(',') } }),
    );
  }

  ngOnInit() {
    this.curatedView = this.readCuratedView();

    // Search: debounce typing, then load page 1 of the results grid.
    this.sub.add(
      this.search$.pipe(debounceTime(300)).subscribe((term) => {
        this.ui.searching = false;
        if (this.addTab !== 'search' || term !== this.productQuery.trim() || term.length < SEARCH_MIN) return;
        this.requestBrowse({ mode: 'search', category: null, query: term, page: 1 });
      }),
    );

    // Grid pages. switchMap cancels the in-flight request whenever a newer
    // one (another tab, category, search or page, or `null` for "nothing")
    // is pushed, so a slow older response can never land over the current
    // view. The identity check below is a second guard on top of that.
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
            // ONE membership request for the whole page.
            this.memberships.ensure(result.page.items.map((p) => p.id));
          } else {
            this.browse.items = [];
            this.browse.hasMore = false;
            this.browse.error = apiErrorMessage(result.err, 'Unable to load products.');
          }
        }),
    );

    // Details panel: the admin product record, loaded lazily per product.
    this.sub.add(
      this.detail$
        .pipe(
          switchMap((id) =>
            id == null
              ? of(null)
              : this.adapter.get_v3('GET /admin/products/:id', { params: { id: String(id) } }).pipe(
                  map((res: any) => ({ id, detail: detailFromApi(res), err: null as any })),
                  catchError((err: any) => of({ id, detail: null as ProductDetail | null, err })),
                ),
          ),
        )
        .subscribe((result) => {
          if (!result || !this.panel || this.panel.product.id !== result.id) return;
          if (result.detail) {
            this.detailCache.set(result.id, result.detail);
            this.panel = { ...this.panel, detail: result.detail, state: 'loaded' };
          } else {
            const status = result.err?.status;
            this.panel = { ...this.panel, state: status === 403 || status === 401 ? 'forbidden' : 'error' };
          }
        }),
    );

    // Follow ?id= so links to another collection (details panel) re-load
    // this page in place instead of showing the previous collection.
    this.sub.add(
      this.route.queryParamMap
        .pipe(
          map((q) => q.get('id') ?? ''),
          distinctUntilChanged(),
        )
        .subscribe((raw) => this.openCollection(raw)),
    );
  }

  ngOnDestroy() {
    this.sub.unsubscribe();
    this.memberships.dispose();
  }

  /** Browser tab close / reload with unsaved changes: ask first. */
  @HostListener('window:beforeunload', ['$event'])
  onBeforeUnload(event: BeforeUnloadEvent) {
    if (this.dirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  }

  /**
   * Route guard hook (see app.routes canDeactivate): leaving with unsaved
   * changes asks first. Also runs when ?id= changes to another collection.
   *
   * Leaving is blocked while a save is in flight: its response belongs to
   * THIS collection and must not land in another one's editor (or be lost).
   */
  canDeactivate(): boolean | Promise<boolean> {
    if (this.savingInFlight) {
      this.toast.error('Still saving this collection. Wait for the save to finish, then try again.');
      return false;
    }
    if (!this.dirty) return true;
    // The details panel sits above the overlay layer the confirm dialog opens
    // in (browser Back while it's open would hide the prompt behind it).
    this.closePanelNow();
    return this.confirm.confirm({
      title: 'Leave without saving?',
      message: 'This collection has unsaved changes. Leave the page and discard them?',
      confirmLabel: 'Leave and discard',
      cancelLabel: 'Stay',
      variant: 'danger',
    });
  }

  // ── load ─────────────────────────────────────────────────────────
  /** (Re)load the page for the collection with v3 id `raw`. */
  private openCollection(raw: string) {
    const id = Number(raw);
    if (!raw || !Number.isInteger(id) || id <= 0) {
      this.toast.error('Missing or invalid collection id.');
      this.ui.is_loading = false;
      this.router.navigate(['/admin/collections']);
      return;
    }
    this.collectionId = id;
    this.othersMemo = new WeakMap();
    this.mergeNotice = '';
    this.panel = null;
    this.panelReturnFocus = null;
    this.detail$.next(null);
    // Memberships may have changed on the collection we came from: refetch
    // the grid's cards (the curated list's are fetched once it loads).
    this.memberships.refresh(this.browse.items.map((p) => p.id));
    this.loadCollection();
    this.loadProducts();
  }

  private loadCollection() {
    this.ui.is_loading = true;
    const forId = this.collectionId;
    this.adapter.get_v3('GET /admin/collections/:id', { params: { id: String(forId) } }).subscribe({
      next: (res: any) => {
        if (forId !== this.collectionId) return;
        this.applyCollection(res?.data ?? res ?? {});
        this.ui.is_loading = false;
      },
      error: (err: any) => {
        if (forId !== this.collectionId) return;
        this.toast.error(apiErrorMessage(err, 'Unable to load this collection.'));
        this.ui.is_loading = false;
        this.router.navigate(['/admin/collections']);
      },
    });
  }

  private applyCollection(c: any) {
    this.form = {
      name: c.name ?? c.collection ?? '',
      description: c.description ?? '',
      is_active: c.is_active ?? true,
    };
    this.savedForm = { ...this.form };
    this.slug = c.slug ?? '';
    // display_order is not shown here: until the Collections list has been
    // reordered once, stored values can have gaps/duplicates (from the old
    // numeric field), so it isn't a list position. The list owns the order.
  }

  loadProducts() {
    this.ui.products_loading = true;
    this.ui.products_error = false;
    const forId = this.collectionId;
    this.adapter
      .get_v3('GET /admin/collections/:id/products', { params: { id: String(forId) } })
      .subscribe({
        next: (res: any) => {
          if (forId !== this.collectionId) return;
          this.setSavedProducts(productsFromApi(res));
          this.ui.products_loading = false;
          this.memberships.ensure(this.items.map((p) => p.id));
        },
        error: (err: any) => {
          if (forId !== this.collectionId) return;
          this.toast.error(apiErrorMessage(err, 'Unable to load the collection\'s products.'));
          this.setSavedProducts([]);
          this.ui.products_loading = false;
          this.ui.products_error = true;
        },
      });
  }

  /** Make `products` both the curated list and its saved baseline. */
  private setSavedProducts(products: CuratedProduct[]) {
    this.items = products;
    this.savedItems = [...products];
    this.savedIds = products.map((p) => p.id);
    this.savedIdSet = new Set(this.savedIds);
  }

  // ── search ───────────────────────────────────────────────────────
  onSearchChange(term: string) {
    this.productQuery = term;
    const t = term.trim();
    if (t.length < SEARCH_MIN) {
      this.ui.searching = false;
      // Push the short term through so a pending debounce is dropped.
      this.search$.next('');
      if (this.addTab === 'search') this.requestBrowse(null);
      return;
    }
    // The spinner covers the debounce window too, so "No matching products"
    // doesn't flash while the user is still typing.
    this.ui.searching = true;
    this.search$.next(t);
  }

  clearSearch() {
    this.onSearchChange('');
    this.searchInput?.nativeElement.focus();
  }

  // ── membership + state helpers ───────────────────────────────────
  isAdded(id: number): boolean {
    if (this.addedIds?.items !== this.items) {
      this.addedIds = { items: this.items, ids: new Set(this.items.map((i) => i.id)) };
    }
    return this.addedIds.ids.has(id);
  }

  /** In this collection as last saved. */
  isSaved(id: number): boolean {
    return this.savedIdSet.has(id);
  }

  /** OTHER collections the product is in (undefined while loading / failed). */
  othersFor(id: number): CollectionRef[] | undefined {
    const all = this.memberships.get(id);
    if (!all) return undefined;
    let others = this.othersMemo.get(all);
    if (!others) {
      others = all.filter((c) => c.id !== this.collectionId);
      this.othersMemo.set(all, others);
    }
    return others;
  }

  alsoInLabel(count: number): string {
    return alsoInLabel(count);
  }

  /** A card image failed (deleted / blocked): fall back to the placeholder. */
  onImageError(src: string | null) {
    if (src) this.brokenImages.add(src);
  }

  /**
   * Cards on screen (curated list + grid) whose other-collection lookup
   * failed, so the builder can't tell whether they're in other collections.
   */
  get membershipFailures(): number {
    let n = 0;
    const seen = new Set<number>();
    for (const p of [...this.items, ...this.browse.items]) {
      if (seen.has(p.id)) continue;
      seen.add(p.id);
      if (this.memberships.isUnknown(p.id)) n++;
    }
    return n;
  }

  /** Why the lookup failed, in words (a missing permission is the usual cause). */
  get membershipErrorText(): string {
    const err = this.memberships.lastError as { status?: number } | null;
    if (err?.status === 403) {
      return 'You need permission to view collections (catalog.collections_view) to see which other collections products are in.';
    }
    return apiErrorMessage(err, 'The lookup failed.');
  }

  /** Look up again every card on screen whose lookup failed. */
  retryMemberships() {
    const ids = new Set<number>([...this.items.map((p) => p.id), ...this.browse.items.map((p) => p.id)]);
    if (this.panel) ids.add(this.panel.product.id);
    this.memberships.retry(ids);
  }

  /** The details panel's Retry for its product's collections. */
  retryPanelMemberships() {
    if (this.panel) this.memberships.retry([this.panel.product.id]);
  }

  /** Tooltip / accessible list of the other collections' names. */
  othersTitle(others: CollectionRef[]): string {
    return 'Also in: ' + others.map((c) => c.name + (c.is_active ? '' : ' (inactive)')).join(', ');
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

  /** A save is in flight. */
  get savingInFlight(): boolean {
    return this.ui.products_saving || this.ui.is_saving;
  }

  /** The curated list differs (membership or order) from what's saved. */
  get productsDirty(): boolean {
    if (this.ui.products_loading || this.ui.products_error) return false;
    const ids = this.items.map((p) => p.id);
    return ids.length !== this.savedIds.length || ids.some((id, i) => id !== this.savedIds[i]);
  }

  /** The collection's own fields differ from what's saved. */
  get detailsDirty(): boolean {
    if (this.ui.is_loading) return false;
    return (
      this.form.name.trim() !== this.savedForm.name.trim() ||
      (this.form.description ?? '').trim() !== (this.savedForm.description ?? '').trim() ||
      !!this.form.is_active !== !!this.savedForm.is_active
    );
  }

  get dirty(): boolean {
    return this.productsDirty || this.detailsDirty;
  }

  /** Curated products added since the last save. */
  get unsavedAddCount(): number {
    return this.items.filter((p) => !this.savedIdSet.has(p.id)).length;
  }

  /** Saved products removed since the last save. */
  get unsavedRemoveCount(): number {
    const now = new Set(this.items.map((p) => p.id));
    return this.savedIds.filter((id) => !now.has(id)).length;
  }

  // ── add / remove ─────────────────────────────────────────────────
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
    if (fresh.length) {
      this.items = [...this.items, ...fresh];
      this.memberships.ensure(fresh.map((p) => p.id));
    }
    return fresh.length;
  }

  /** Add one product (card button, details panel). Focus stays where it is. */
  addProduct(p: CuratedProduct) {
    if (this.curationLocked || this.isAdded(p.id)) return;
    if (!this.appendToCuration([p])) return;
    this.announce(`Added ${p.name} to the collection, position ${this.items.length}.`);
  }

  /** Remove one product by id (card button, details panel). */
  removeProduct(p: CuratedProduct) {
    if (this.curationLocked) return;
    const index = this.items.findIndex((i) => i.id === p.id);
    if (index < 0) return;
    this.items = this.items.filter((_, i) => i !== index);
    this.announce(`Removed ${p.name} from the collection.`);
  }

  /** Add when absent, remove when present. */
  toggleProduct(p: CuratedProduct) {
    if (this.curationLocked) return;
    this.isAdded(p.id) ? this.removeProduct(p) : this.addProduct(p);
  }

  /** A card's own Add/Remove button: never opens the details panel. */
  onCardToggle(p: CuratedProduct, event: Event) {
    event.stopPropagation();
    this.toggleProduct(p);
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
      const term = this.productQuery.trim();
      this.requestBrowse(
        term.length >= SEARCH_MIN ? { mode: 'search', category: null, query: term, page: this.browsePages.search } : null,
      );
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
      query: null,
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
    this.requestBrowse(slug ? { mode: 'category', category: slug, query: null, page: 1 } : null);
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

  // ── Browse: shared paginated grid ────────────────────────────────
  /**
   * Show `req` in the grid (or clear it with null). Results of the previous
   * request are dropped at once, so a page/tab/category/search switch never
   * shows another view's products, and the stream's switchMap cancels the
   * previous request. The total is kept only while paging within the same
   * view so the pager stays put during the load.
   */
  private requestBrowse(req: BrowseRequest | null) {
    const prev = this.browseRequest;
    const sameView = !!req && !!prev && prev.mode === req.mode && prev.category === req.category && prev.query === req.query;
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
    // pages; a category lists newest first, a search by relevance.
    // GET /products only returns storefront-visible products (active,
    // approved store).
    const query: Record<string, string | number> = {
      sort: req.mode === 'best_sellers' ? 'best_seller' : req.mode === 'search' ? 'relevance' : 'newest',
      limit: BROWSE_PAGE_SIZE,
      offset,
    };
    if (req.mode === 'category' && req.category) query['category'] = req.category;
    if (req.mode === 'search' && req.query) query['q'] = req.query;
    return this.adapter.get_v3('GET /products', { query }).pipe(
      map((res: any) => {
        const raw: any[] = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : res?.data?.items ?? [];
        const items = productsFromApi(res);
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
      this.announce(`Added ${added} product${added === 1 ? '' : 's'} to the collection.`);
    }
  }

  /** Heading for the grid. */
  get browseTitle(): string {
    switch (this.browseRequest?.mode) {
      case 'search':
        return `Results for “${this.browseRequest.query}”`;
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

  /** How the current grid is ordered. */
  get browseHint(): string {
    switch (this.browseRequest?.mode) {
      case 'search':
        return 'Best matches first. Only products the storefront shows are listed.';
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

  // ── curated list: reorder ────────────────────────────────────────
  /** Drag-and-drop reorder of the curated list. */
  onCuratedDrop(event: CdkDragDrop<CuratedProduct[]>) {
    this.lastDropAt = Date.now();
    if (this.curationLocked || event.previousIndex === event.currentIndex) return;
    const next = [...this.items];
    moveItemInArray(next, event.previousIndex, event.currentIndex);
    this.items = next;
    const moved = next[event.currentIndex];
    this.announce(this.positionMessage(moved, event.currentIndex));
  }

  moveUp(index: number) {
    if (this.curationLocked || index <= 0) return;
    this.swap(index, index - 1);
  }

  moveDown(index: number) {
    if (this.curationLocked || index >= this.items.length - 1) return;
    this.swap(index, index + 1);
  }

  /** Make a curated product the cover (first). */
  makeCover(index: number) {
    if (this.curationLocked || index <= 0 || index >= this.items.length) return;
    const next = [...this.items];
    moveItemInArray(next, index, 0);
    this.items = next;
    this.announce(this.positionMessage(next[0], 0));
    this.refocusCurated(next[0].id, 'title');
  }

  private swap(a: number, b: number) {
    const next = [...this.items];
    [next[a], next[b]] = [next[b], next[a]];
    this.items = next;
    const moved = next[b];
    this.announce(this.positionMessage(moved, b));
    // Moving the DOM node drops focus in some browsers; put it back on the
    // same control (or its sibling when that one just became disabled).
    const prefer = b < a ? 'up' : 'down';
    const atEdge = prefer === 'up' ? b === 0 : b === next.length - 1;
    this.refocusCurated(moved.id, atEdge ? (prefer === 'up' ? 'down' : 'up') : prefer);
  }

  private positionMessage(p: CuratedProduct, index: number): string {
    const cover = index === 0 ? ' It is now the collection cover.' : '';
    return `Moved ${p.name} to position ${index + 1} of ${this.items.length}.${cover}`;
  }

  /** Remove a curated product (its own button); focus moves to a neighbour. */
  removeItem(index: number) {
    if (this.curationLocked) return;
    const removed = this.items[index];
    if (!removed) return;
    this.items = this.items.filter((_, i) => i !== index);
    this.announce(`Removed ${removed.name} from the collection.`);
    const neighbour = this.items[index] ?? this.items[index - 1];
    if (neighbour) {
      this.refocusCurated(neighbour.id, 'title');
    } else {
      setTimeout(() => this.host.nativeElement.querySelector<HTMLElement>('#ec_curated_title')?.focus());
    }
  }

  /** After a re-render, focus a control of the curated card for `id`. */
  private refocusCurated(id: number, control: 'up' | 'down' | 'title') {
    setTimeout(() => {
      const card = this.host.nativeElement.querySelector<HTMLElement>(`[data-curated-id="${id}"]`);
      card?.querySelector<HTMLElement>(`[data-focus="${control}"]`)?.focus();
    });
  }

  setCuratedView(view: CuratedView) {
    this.curatedView = view;
    try {
      localStorage.setItem(CURATED_VIEW_KEY, view);
    } catch {
      /* storage unavailable: the choice just isn't remembered */
    }
  }

  private readCuratedView(): CuratedView {
    try {
      return localStorage.getItem(CURATED_VIEW_KEY) === 'list' ? 'list' : 'grid';
    } catch {
      return 'grid';
    }
  }

  // ── details panel ────────────────────────────────────────────────
  /** A click anywhere on a card (except its own buttons) opens the panel. */
  onCardClick(p: CuratedProduct, event: MouseEvent) {
    if (Date.now() - this.lastDropAt < 250) return;
    const target = event.target as HTMLElement | null;
    if (target?.closest('[data-card-action]')) return;
    const card = event.currentTarget as HTMLElement | null;
    this.openPanel(p, card?.querySelector<HTMLElement>('[data-focus="title"]') ?? card);
  }

  openPanel(p: CuratedProduct, returnFocus: HTMLElement | null = null) {
    this.panelReturnFocus = returnFocus;
    const cached = this.detailCache.get(p.id) ?? null;
    this.panel = { product: p, detail: cached, state: cached ? 'loaded' : 'loading' };
    // retry (not ensure): a product whose lookup failed earlier is looked up
    // again when its panel opens, instead of showing the failure forever.
    this.memberships.retry([p.id]);
    this.detail$.next(cached ? null : p.id);
  }

  retryPanelDetail() {
    if (!this.panel) return;
    this.panel = { ...this.panel, state: 'loading' };
    this.detail$.next(this.panel.product.id);
  }

  closePanel() {
    if (!this.panel) return;
    const productId = this.panel.product.id;
    this.panel = null;
    this.detail$.next(null);
    const el = this.panelReturnFocus;
    this.panelReturnFocus = null;
    // Back to the card that opened it (or, if that card re-rendered, the
    // same product's card wherever it is now).
    setTimeout(() => {
      if (el?.isConnected) {
        el.focus();
        return;
      }
      this.host.nativeElement
        .querySelector<HTMLElement>(`[data-product-card="${productId}"] [data-focus="title"]`)
        ?.focus();
    });
  }

  /**
   * Close the panel and put focus back on its card synchronously, so a dialog
   * opened right after (the leave prompt) returns focus to the card, not to
   * the removed panel.
   */
  private closePanelNow() {
    if (!this.panel) return;
    const productId = this.panel.product.id;
    const el = this.panelReturnFocus;
    this.panel = null;
    this.panelReturnFocus = null;
    this.detail$.next(null);
    const target = el?.isConnected
      ? el
      : this.host.nativeElement.querySelector<HTMLElement>(`[data-product-card="${productId}"] [data-focus="title"]`);
    target?.focus();
  }

  /** 1-based curated position of a product (null when not curated). */
  positionOf(id: number): number | null {
    const i = this.items.findIndex((p) => p.id === id);
    return i < 0 ? null : i + 1;
  }

  get canOpenProduct(): boolean {
    return this.perms.can('products.view');
  }

  // ── save / discard ───────────────────────────────────────────────
  /** Save what changed and stay on the page (sticky bar "Save"). */
  saveChanges() {
    this.persist(false);
  }

  /** Save what changed, then return to the list ("Save & close"). */
  save() {
    this.persist(true);
  }

  private persist(close: boolean) {
    if (this.savingInFlight) return;
    const name = this.form.name.trim();
    if (name.length === 0) {
      this.toast.error('Collection name cannot be empty');
      return;
    }
    const detailsDirty = this.detailsDirty;
    const productsDirty = this.productsDirty;
    if (!detailsDirty && !productsDirty) {
      if (close) this.router.navigate(['/admin/collections']);
      return;
    }

    // Every response below is checked against the collection it was sent for:
    // the page can be re-pointed at another collection (?id=), and a late
    // answer must never become that one's saved state.
    const forId = this.collectionId;
    let stage: 'check' | 'details' | 'products' = productsDirty ? 'check' : 'details';
    this.ui.is_saving = true;
    const current$: Observable<CuratedProduct[] | null> = productsDirty ? this.fetchServerProducts(forId) : of(null);
    current$
      .pipe(
        switchMap((server): Observable<{ merged: CuratedProduct[] | null }> => {
          // The products were changed elsewhere since they were loaded: merge
          // instead of overwriting them, and let the admin review first.
          if (server && !this.sameIds(server.map((p) => p.id), this.savedIds)) {
            return of({ merged: server });
          }
          stage = 'details';
          return (detailsDirty ? this.putDetails(name, forId) : of(null)).pipe(
            switchMap((): Observable<CuratedProduct[] | null> => {
              stage = 'products';
              if (!productsDirty) return of(null);
              // The API re-checks the baseline under its lock (expected_product_ids):
              // a change made between the read above and this write comes back
              // as 409 CONFLICT_STALE and is merged exactly like the case above.
              return this.putProducts(forId).pipe(
                map(() => null),
                catchError((err) => (isStaleConflict(err) ? this.fetchServerProducts(forId) : throwError(() => err))),
              );
            }),
            map((merged) => ({ merged })),
          );
        }),
      )
      .subscribe({
        next: ({ merged }) => {
          this.ui.is_saving = false;
          if (forId !== this.collectionId) return;
          if (merged) {
            this.mergeServerProducts(merged, detailsDirty && stage === 'products');
            return;
          }
          this.mergeNotice = '';
          this.toast.success(
            detailsDirty && productsDirty ? 'Collection and products saved.' : productsDirty ? 'Collection products saved.' : 'Collection saved.',
          );
          if (close) this.router.navigate(['/admin/collections']);
        },
        error: (err: any) => {
          this.ui.is_saving = false;
          this.ui.products_saving = false;
          this.toast.error(
            stage === 'check'
              ? apiErrorMessage(err, 'Couldn\'t check this collection\'s current products, so nothing was saved. Please try again.')
              : stage === 'products' && detailsDirty
                ? apiErrorMessage(err, 'Collection details saved, but its product list could not be saved. Please try again.')
                : apiErrorMessage(err, stage === 'products' ? 'Unable to save the collection\'s products.' : 'Unable to save the collection.'),
          );
        },
      });
  }

  /**
   * The collection's products as stored right now (GET, same shape and order
   * as the initial load). Read just before a products save so a change made
   * elsewhere meanwhile (the product page's Collections card, another tab)
   * isn't silently wiped by this page's full re-set.
   */
  private fetchServerProducts(forId: number): Observable<CuratedProduct[]> {
    return this.adapter
      .get_v3('GET /admin/collections/:id/products', { params: { id: String(forId) } })
      .pipe(map((res: any) => productsFromApi(res)));
  }

  private sameIds(a: number[], b: number[]): boolean {
    return a.length === b.length && a.every((id, i) => id === b[i]);
  }

  /**
   * Fold changes made elsewhere into this page's unsaved list, then make the
   * server's list the new baseline (nothing is saved; the admin reviews and
   * saves again):
   * - products removed elsewhere are dropped;
   * - products added elsewhere are appended (where the product page puts them);
   * - this page's own adds, removes and order are kept.
   */
  private mergeServerProducts(server: CuratedProduct[], detailsSaved = false) {
    const base = new Set(this.savedIds);
    const serverIds = new Set(server.map((p) => p.id));
    const local = new Set(this.items.map((p) => p.id));
    const removedElsewhere = this.savedIds.filter((id) => !serverIds.has(id));
    const addedElsewhere = server.filter((p) => !base.has(p.id));
    const orderOnly = !removedElsewhere.length && !addedElsewhere.length;

    const removed = new Set(removedElsewhere);
    const merged = [
      ...this.items.filter((p) => !removed.has(p.id)),
      ...addedElsewhere.filter((p) => !local.has(p.id)),
    ];
    this.setSavedProducts(server);
    this.items = merged;
    this.memberships.refresh(new Set<number>([
      ...merged.map((p) => p.id),
      ...this.browse.items.map((p) => p.id),
      ...(this.panel ? [this.panel.product.id] : []),
    ]));

    const what: string[] = [];
    if (addedElsewhere.length) what.push(`${addedElsewhere.length} product${addedElsewhere.length === 1 ? '' : 's'} added`);
    if (removedElsewhere.length) what.push(`${removedElsewhere.length} removed`);
    const changed = orderOnly
      ? 'This collection was reordered somewhere else while you were editing.'
      : `This collection's products were changed somewhere else while you were editing (${what.join(', ')}).`;
    const next = !this.productsDirty
      ? ' Nothing was saved; your list now matches the saved one.'
      : orderOnly
        ? ' Nothing was saved and your order is kept. Review it, then save again to apply it.'
        : ' Nothing was saved: those changes are now merged into your list together with your own. Review it, then save again.';
    this.mergeNotice = changed + (detailsSaved ? next.replace('Nothing was saved', 'Only the collection details were saved') : next);
    this.announce(this.mergeNotice);
  }

  dismissMergeNotice() {
    this.mergeNotice = '';
  }

  /**
   * PUT the collection's own fields. display_order is deliberately NOT sent:
   * storefront order is owned by the drag-and-drop Collections list.
   */
  private putDetails(name: string, forId: number): Observable<unknown> {
    const body = {
      name,
      description: this.form.description?.trim() || null,
      is_active: !!this.form.is_active,
    };
    return this.adapter
      .put_v3('PUT /admin/collections/:id', body, { params: { id: String(forId) } })
      .pipe(
        tap((res: any) => {
          if (forId !== this.collectionId) return;
          const c = res?.data;
          if (c && typeof c === 'object' && !Array.isArray(c)) {
            this.applyCollection(c);
          } else {
            this.form = { ...this.form, name, description: body.description ?? '' };
            this.savedForm = { ...this.form };
          }
        }),
      );
  }

  /**
   * PUT the curated ids in order. The response echoes the saved list (same
   * shape as GET), which becomes the new baseline. Memberships changed, so
   * the cache is refreshed for every card on screen.
   */
  private putProducts(forId: number): Observable<unknown> {
    this.ui.products_saving = true;
    const product_ids = this.items.map((p) => p.id);
    // The baseline this edit was made against; the API rejects the write with
    // 409 CONFLICT_STALE if the stored list no longer matches it.
    const expected_product_ids = [...this.savedIds];
    const sent = this.items;
    return this.adapter
      .put_v3('PUT /admin/collections/:id/products', { product_ids, expected_product_ids }, { params: { id: String(forId) } })
      .pipe(
        tap({
          next: (res: any) => {
            this.ui.products_saving = false;
            if (forId !== this.collectionId) return;
            const echoed = Array.isArray(res?.data) ? productsFromApi(res) : null;
            this.setSavedProducts(echoed ?? sent);
            this.refreshMemberships();
          },
          error: () => {
            this.ui.products_saving = false;
          },
        }),
      );
  }

  /** Refetch memberships for every card on screen (they changed). */
  private refreshMemberships() {
    const ids = new Set<number>([...this.items.map((p) => p.id), ...this.browse.items.map((p) => p.id)]);
    if (this.panel) ids.add(this.panel.product.id);
    this.memberships.refresh(ids);
  }

  /** Throw away unsaved changes (fields + curated list), after confirming. */
  async discard() {
    if (!this.dirty || this.savingInFlight) return;
    const ok = await this.confirm.confirm({
      title: 'Discard changes?',
      message: 'Revert this collection to how it was last saved? Unsaved product and detail changes are lost.',
      confirmLabel: 'Discard changes',
      cancelLabel: 'Keep editing',
      variant: 'danger',
    });
    if (!ok || this.savingInFlight) return;
    this.form = { ...this.savedForm };
    this.items = [...this.savedItems];
    this.mergeNotice = '';
    this.announce('Changes discarded.');
  }

  goBack() {
    this.navHistory.back('/admin/collections');
  }
}

/** 409 CONFLICT_STALE: the stored list changed since the client loaded it. */
function isStaleConflict(err: any): boolean {
  return err?.status === 409 && err?.error?.error?.code === 'CONFLICT_STALE';
}
