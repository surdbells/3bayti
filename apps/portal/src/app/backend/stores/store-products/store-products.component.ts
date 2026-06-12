import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
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
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective],
  templateUrl: './store-products.component.html',
  styleUrl: './store-products.component.css',
})
export class StoreProductsComponent implements OnInit {
  store_name = '';
  private storeId = 0;

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
    this.buildTable();
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
        { key: 'name', label: 'Name', sortable: true, sticky: 'left', width: '16rem' },
        { key: 'label', label: 'Label', hideOnMobile: true },
        { key: 'category', label: 'Category', hideOnMobile: true },
        { key: 'price', label: 'Price', align: 'right',
          format: (v) => (v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—') },
        { key: 'quantity', label: 'Qty', align: 'center', hideOnMobile: true },
        { key: 'stock_status', label: 'Stock', align: 'center' },
      ],
      rowActions: [{ id: 'view', label: 'View product', icon: 'visibility' }],
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
        const raw: any[] = response?.data ?? response?.products ?? [];
        return { rows: raw as ProductRow[], total: response?.meta?.total ?? raw.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load store products.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<ProductRow>);
      }),
    );
  }

  onRowAction(e: { action: { id: string }; row: ProductRow }) {
    if (e.action.id === 'view') {
      this.router.navigate(['/', 'adminviewproduct'], { queryParams: { id: e.row.id } });
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

  goBack() { this.router.navigate(['/stores']); }
}
