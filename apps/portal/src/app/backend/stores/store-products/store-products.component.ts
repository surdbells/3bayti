import { Component, OnInit, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { NavigationHistoryService } from '../../../services/navigation-history.service';
import { CommonModule } from '@angular/common';
import { Observable, of } from 'rxjs';
import { map, catchError, switchMap, shareReplay } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { apiErrorMessage } from '../../../shared/http/api-error';
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

@Component({
  selector: 'app-store-products',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './store-products.component.html',
  styleUrl: './store-products.component.css',
})
export class StoreProductsComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);
  private readonly navHistory = inject(NavigationHistoryService);

  store_name = '';
  private storeId = 0;
  private vendorV3Id = 0;
  /** Resolves the legacy store id → v3 vendor id once, then replays. */
  private vendorV3Id$!: Observable<number>;

  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<ProductRow>;
  dataSource!: AxServerDataSource<ProductRow>;

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
    // A v3 vendor id may be passed straight from the stores row; otherwise
    // resolve it from the legacy id. Either way the products list is keyed
    // by the v3 id (the admin route is ungated and works for inactive stores).
    this.vendorV3Id = Number(this.route.snapshot.queryParamMap.get('vendor_id')) || 0;
    this.vendorV3Id$ = this.resolveVendor().pipe(shareReplay(1));
    this.buildTable();
  }

  /** Resolve legacy store id → v3 vendor id (needed to list + create products). */
  private resolveVendor(): Observable<number> {
    if (this.vendorV3Id > 0) return of(this.vendorV3Id);
    return this.adapter
      .get_v3('GET /vendors/by-legacy-id/:id', { params: { id: String(this.storeId) } })
      .pipe(
        map((res: any) => {
          this.vendorV3Id = res?.data?.id ?? res?.meta?.vendor_id ?? 0;
          return this.vendorV3Id;
        }),
        catchError(() => of(0)),
      );
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
    // List via the ADMIN-scoped, ungated endpoint keyed by the v3 vendor id:
    // returns ALL of the store's products in every state and works for
    // inactive/pending stores (the public storefront route hides them, so
    // admins used to see an empty list for real stores).
    return this.vendorV3Id$.pipe(
      switchMap((vendorId) => {
        if (!vendorId) {
          this.toast.error('Unable to load store products.');
          return of({ rows: [], total: 0 } as AxServerFetchResult<ProductRow>);
        }
        return this.adapter.get_v3('GET /admin/vendors/:id/products', {
          params: { id: String(vendorId) },
          query: q,
        }).pipe(
          map((response: any): AxServerFetchResult<ProductRow> => {
            if (response?.meta?.vendor_id) this.vendorV3Id = response.meta.vendor_id;
            const raw: any[] = response?.data ?? response?.products ?? [];
            const rows = raw.map((p) => this.mapProduct(p));
            return { rows, total: response?.meta?.total ?? rows.length };
          }),
          catchError((err: any) => {
            this.toast.error(apiErrorMessage(err, 'Unable to load store products.'));
            return of({ rows: [], total: 0 } as AxServerFetchResult<ProductRow>);
          }),
        );
      }),
    );
  }

  onRowAction(e: { action: { id: string }; row: ProductRow }) {
    if (e.action.id === 'edit') {
      // Open the full routed product editor (loads real product detail) -
      // same pattern as AdminProducts, instead of the partial inline drawer.
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

  /** Create a product for this store via the full routed create page. */
  openCreate() {
    // Route to the full create-product page, passing the store's vendor so it
    // can default to this store (the form still lets admin change it).
    this.router.navigate(['/admin_create_product'], { queryParams: { vendor_id: this.vendorV3Id } });
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
        error: (err: any) => this.toast.error(apiErrorMessage(err, 'Unable to delete product.')),
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

  goBack() { this.navHistory.back('/stores'); }
}
