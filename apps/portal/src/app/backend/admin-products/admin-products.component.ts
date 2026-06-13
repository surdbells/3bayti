import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';

interface ProductRow extends Record<string, unknown> {
  id: number;
  name: string;
  category_name: string;
  store: number;
  store_name: string;
  first_name: string;
  last_name: string;
  price: string;
  stock_status: string;
  created_at: string;
  colours: string[];
  status: string;
}

@Component({
  selector: 'app-admin-products',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './admin-products.component.html',
  styleUrl: './admin-products.component.css',
})
export class AdminProductsComponent implements OnInit {
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
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(
      sessionStorage.getItem('SESSION') ?? '',
    );
    this.buildTable();
  }

  private buildTable() {
    this.dataSource = new AxServerDataSource<ProductRow>((q) => this.fetchProducts(q));
    this.config = {
      tableId: 'admin-products',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search products by name…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No products found',
      emptyDescription: 'No products match your current filters.',
      export: { enabled: true, formats: ['csv', 'xlsx', 'pdf'], filename: 'products' },
      filters: [
        {
          key: 'status', label: 'Status', type: 'select',
          options: [
            { label: 'Published', value: 'published' },
            { label: 'Draft', value: 'draft' },
            { label: 'Deleted', value: 'deleted' },
          ],
        },
        {
          key: 'stock', label: 'Stock', type: 'select',
          options: [
            { label: 'In stock', value: 'in_stock' },
            { label: 'Out of stock', value: 'out_of_stock' },
            { label: 'On backorder', value: 'on_backorder' },
          ],
        },
      ],
      columns: [
        { key: 'name', label: 'Product', sortable: true, sticky: 'left', width: '16rem' },
        { key: 'category_name', label: 'Category', hideOnMobile: true },
        { key: 'store_name', label: 'Store', hideOnMobile: true },
        { key: 'price', label: 'Price', align: 'right',
          format: (v) => (v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—') },
        { key: 'stock_status', label: 'Stock', align: 'center' },
        { key: 'created_at', label: 'Created', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
        { key: 'status', label: 'Status', align: 'center' },
      ],
      rowActions: [
        { id: 'manage-store', label: 'Manage store', icon: 'storefront' },
      ],
    };
  }

  private fetchProducts(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    if (query.filters['status']) q.status = query.filters['status'];
    if (query.filters['stock']) q.stock_status = query.filters['stock'];

    return this.adapter.get_v3('GET /products', { query: q }).pipe(
      map((response: any): AxServerFetchResult<ProductRow> => {
        const raw: any[] = response?.data ?? [];
        const rows = raw.map((p) => this.mapProduct(p));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load products at this time.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<ProductRow>);
      }),
    );
  }

  /** Map the catalog product shape into the flat admin row. */
  private mapProduct(p: any): ProductRow {
    const catSlug: string = p.category_slug ?? p.category?.slug ?? '';
    const category = p.category?.name
      ?? (catSlug ? catSlug.replace(/-/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase()) : '—');
    const inStock = p.in_stock === true;
    return {
      id: p.id,
      name: p.name ?? '—',
      category_name: category,
      store: p.vendor?.id ?? 0,
      store_name: p.vendor?.name ?? '—',
      first_name: '',
      last_name: '',
      price: String(p.price?.amount ?? p.price ?? 0),
      stock_status: p.stock_status ?? (inStock ? 'in_stock' : 'out_of_stock'),
      created_at: p.created_at ?? '',
      colours: [],
      status: p.status ?? (p.is_published === false ? 'draft' : 'published'),
    } as ProductRow;
  }

  onRowAction(e: { action: { id: string }; row: ProductRow }) {
    if (e.action.id === 'manage-store') {
      const urlTree = this.router.createUrlTree(['/manage_store'], {
        queryParams: { id: e.row.store, name: e.row.store_name },
      });
      window.open(this.router.serializeUrl(urlTree), '_blank');
    }
  }

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

  statusBadge(status: string): string {
    switch (status) {
      case 'published': return 'ax-badge ax-badge-info';
      case 'draft': return 'ax-badge ax-badge-warning';
      case 'deleted': return 'ax-badge ax-badge-danger';
      default: return 'ax-badge ax-badge-neutral';
    }
  }

  goBack() { this.router.navigate(['/backend']); }
}
