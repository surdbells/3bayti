import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { AxConfirmService } from '../../../shared/overlays';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../../shared/data/enterprise';

interface ProductRow extends Record<string, unknown> {
  id: number;
  name: string;
  label: string;
  category: string;
  price: string;
  quantity: number;
  stock_status: string;
}

interface CategoryOption { id: number; name: string; }

interface ProductForm {
  id: number | null;
  name: string;
  description: string;
  price: string;
  stock_status: string;
  stock_quantity: number | null;
  category_id: number | null;
  status: string;
  primary_image_url: string;
}

const EMPTY_FORM: ProductForm = {
  id: null, name: '', description: '', price: '',
  stock_status: 'in_stock', stock_quantity: 0,
  category_id: null, status: 'published', primary_image_url: '',
};

@Component({
  selector: 'app-store-products',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './store-products.component.html',
  styleUrl: './store-products.component.css',
})
export class StoreProductsComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);

  store_name = '';
  private storeId = 0;
  private vendorV3Id = 0;

  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<ProductRow>;
  dataSource!: AxServerDataSource<ProductRow>;

  categories: CategoryOption[] = [];

  // Drawer state
  readonly drawerOpen = signal(false);
  readonly busy = signal(false);
  readonly editing = signal(false);
  form: ProductForm = { ...EMPTY_FORM };

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(
      sessionStorage.getItem('SESSION') ?? '',
    );
    this.storeId = Number(this.route.snapshot.queryParamMap.get('id'));
    this.store_name = this.route.snapshot.queryParamMap.get('name') ?? '';
    this.resolveVendor();
    this.loadCategories();
    this.buildTable();
  }

  /** Resolve legacy store id → v3 vendor id (needed to create products). */
  private resolveVendor() {
    this.adapter.get_v3('GET /vendors/by-legacy-id/:id', { params: { id: String(this.storeId) } }).subscribe({
      next: (res: any) => { this.vendorV3Id = res?.data?.id ?? this.vendorV3Id; },
      error: () => { /* falls back to meta.vendor_id from the list */ },
    });
  }

  private loadCategories() {
    this.adapter.get_v3('GET /utility/categories').subscribe({
      next: (res: any) => {
        const raw: any[] = res?.data ?? res?.categories ?? [];
        this.categories = raw.map((c) => ({ id: c.id, name: c.name }));
      },
      error: () => { /* non-critical */ },
    });
  }

  private buildTable() {
    this.dataSource = new AxServerDataSource<ProductRow>((q) => this.fetchProducts(q));
    this.config = {
      tableId: 'store-products',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search products…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No products',
      emptyDescription: 'This store has no products listed.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'store-products' },
      columns: [
        { key: 'name', label: 'Name', sortable: true, sticky: 'left', width: '18rem' },
        { key: 'category', label: 'Category', hideOnMobile: true },
        { key: 'price', label: 'Price', align: 'right',
          format: (v) => (v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—') },
        { key: 'stock_status', label: 'Stock', align: 'center' },
      ],
      rowActions: [
        { id: 'edit', label: 'Edit', icon: 'edit' },
        { id: 'delete', label: 'Delete', icon: 'delete', variant: 'danger' },
      ],
    };
  }

  private fetchProducts(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    return this.adapter.get_v3('GET /vendors/by-legacy-id/:id/products', {
      params: { id: String(this.storeId) },
      query: q,
    }).pipe(
      map((response: any): AxServerFetchResult<ProductRow> => {
        // Capture the resolved v3 vendor id for create operations.
        if (response?.meta?.vendor_id) this.vendorV3Id = response.meta.vendor_id;
        const raw: any[] = response?.data ?? response?.products ?? [];
        const rows = raw.map((p) => this.mapProduct(p));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load store products.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<ProductRow>);
      }),
    );
  }

  onRowAction(e: { action: { id: string }; row: ProductRow }) {
    if (e.action.id === 'edit') {
      // Open the full routed product editor (loads real product detail) —
      // same pattern as AdminProducts — instead of the partial inline drawer.
      this.router.navigate(['/admin_edit_product'], { queryParams: { id: e.row.id, slug: String(e.row['slug'] ?? '') } });
    } else if (e.action.id === 'delete') {
      this.confirmDelete(e.row);
    }
  }

  /** Map the catalog product shape (price.amount, in_stock, category_slug)
   *  into the flat row the table renders. */
  private mapProduct(p: any): ProductRow {
    const price = p.price?.amount ?? p.price ?? 0;
    const inStock = p.in_stock === true;
    const catSlug: string = p.category_slug ?? p.category?.slug ?? '';
    const category = p.category?.name
      ?? (catSlug ? catSlug.replace(/-/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase()) : '—');
    return {
      id: p.id,
      name: p.name ?? '—',
      label: p.vendor?.name ?? '',
      category,
      price: String(price),
      quantity: p.stock_quantity ?? 0,
      stock_status: p.stock_status ?? (inStock ? 'in_stock' : 'out_of_stock'),
      image: p.primary_image?.url ?? p.image ?? '',
      slug: p.slug ?? '',
    } as ProductRow;
  }

  // ── Create / Edit drawer ───────────────────────────────────────────
  openCreate() {
    // Route to the full create-product page, passing the store's vendor so it
    // can default to this store (the form still lets admin change it).
    this.router.navigate(['/admin_create_product'], { queryParams: { vendor_id: this.vendorV3Id } });
  }

  openEdit(row: ProductRow) {
    this.editing.set(true);
    this.drawerOpen.set(true);
    // The list row carries enough to edit; the detail fetch route isn't
    // registered for admin-by-id, so we populate from the row directly.
    this.form = {
      id: row.id,
      name: row.name ?? '',
      description: '',
      price: String(row.price ?? ''),
      stock_status: row.stock_status ?? 'in_stock',
      stock_quantity: row.quantity ?? 0,
      category_id: null,
      status: 'published',
      primary_image_url: (row['image'] as string) ?? (row['label'] as string) ?? '',
    };
  }

  closeDrawer() { this.drawerOpen.set(false); }

  save() {
    if (!this.form.name.trim()) { this.toast.error('Product name is required.'); return; }
    if (!this.form.price || Number(this.form.price) < 0) { this.toast.error('A valid price is required.'); return; }

    const body: any = {
      name: this.form.name.trim(),
      description: this.form.description,
      price: this.form.price,
      stock_status: this.form.stock_status,
      stock_quantity: this.form.stock_quantity,
      category_id: this.form.category_id,
      status: this.form.status,
      primary_image_url: this.form.primary_image_url || undefined,
    };

    this.busy.set(true);
    if (this.editing() && this.form.id) {
      this.adapter.put_v3('PUT /admin/products/:id', body, { params: { id: String(this.form.id) } }).subscribe({
        next: (r: any) => {
          if (r) { this.toast.success('Product updated.'); this.drawerOpen.set(false); this.dataSource.retry(); }
          this.busy.set(false);
        },
        error: () => { this.toast.error('Unable to update product.'); this.busy.set(false); },
      });
    } else {
      if (!this.vendorV3Id) { this.toast.error('Store reference not ready — reload and try again.'); this.busy.set(false); return; }
      body.vendor_id = this.vendorV3Id;
      this.adapter.post_v3('POST /admin/products', body).subscribe({
        next: (r: any) => {
          if (r) { this.toast.success('Product created.'); this.drawerOpen.set(false); this.dataSource.retry(); }
          this.busy.set(false);
        },
        error: () => { this.toast.error('Unable to create product.'); this.busy.set(false); },
      });
    }
  }

  private confirmDelete(row: ProductRow) {
    this.confirm.confirm({
      title: 'Delete product',
      message: `Delete "${row.name}"? This cannot be undone.`,
      confirmLabel: 'Delete', cancelLabel: 'Cancel', variant: 'danger',
    }).then((ok) => {
      if (!ok) return;
      this.adapter.delete_v3('DELETE /admin/products/:id', { params: { id: String(row.id) } }).subscribe({
        next: (r: any) => { if (r) { this.toast.success('Product deleted.'); this.dataSource.retry(); } },
        error: () => this.toast.error('Unable to delete product.'),
      });
    });
  }

  // ── Display helpers ────────────────────────────────────────────────
  stockBadge(status: string): string {
    switch (status) {
      case 'in_stock': return 'ax-badge ax-badge-success';
      case 'out_of_stock': return 'ax-badge ax-badge-danger';
      case 'on_backorder': return 'ax-badge ax-badge-warning';
      default: return 'ax-badge ax-badge-neutral';
    }
  }

  stockLabel(status: string): string {
    switch (status) {
      case 'in_stock': return 'In stock';
      case 'out_of_stock': return 'Out of stock';
      case 'on_backorder': return 'On backorder';
      default: return status;
    }
  }

  goBack() { this.router.navigate(['/stores']); }
}
