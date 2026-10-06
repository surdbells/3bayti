import { Component, OnDestroy, OnInit } from '@angular/core';
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

/** One product in the collection's curated line-up (or a search hit). */
interface CuratedProduct {
  /** v3 product id — what PUT /admin/collections/:id/products takes. */
  id: number;
  name: string;
  image: string | null;
  price: number | null;
  vendor_name: string | null;
  /** false when the API says the product is out of stock (it won't show). */
  in_stock: boolean | null;
}

/**
 * Edit an admin-curated storefront collection.
 *
 * The collection is addressed by its v3 id from the `?id=` query param
 * (/admin/collections/edit?id=12). The page edits the collection's own
 * fields (PUT /admin/collections/:id) and curates its products
 * (GET/PUT /admin/collections/:id/products): array order is the storefront
 * order, and the FIRST product's image fronts the collection card.
 */
@Component({
  selector: 'app-edit-collection',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent],
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
    return this.items.some((i) => i.id === id);
  }

  addProduct(p: CuratedProduct) {
    if (this.ui.products_loading || this.ui.products_error) return;
    if (this.isAdded(p.id)) {
      this.toast.error('That product is already in this collection.');
      return;
    }
    this.items = [...this.items, p];
    this.productQuery = '';
    this.searchResults = [];
    this.search$.next('');
  }

  moveUp(index: number) {
    if (index <= 0) return;
    this.swap(index, index - 1);
  }

  moveDown(index: number) {
    if (index >= this.items.length - 1) return;
    this.swap(index, index + 1);
  }

  private swap(a: number, b: number) {
    const next = [...this.items];
    [next[a], next[b]] = [next[b], next[a]];
    this.items = next;
  }

  removeItem(index: number) {
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
    return {
      id: Number(src.id) || 0,
      name: src.name ?? '—',
      image: this.imageOf(src),
      price: this.priceOf(src),
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
    const amt = p?.price?.amount ?? p?.price;
    return typeof amt === 'number' ? amt : (amt != null && !isNaN(Number(amt)) ? Number(amt) : null);
  }
}
