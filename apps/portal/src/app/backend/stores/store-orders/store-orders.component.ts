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

interface OrderRow extends Record<string, unknown> {
  id: number;
  created: string;
  product: string;
  name: string;
  quantity: number;
  total_price: string;
  status: string;
}

@Component({
  selector: 'app-store-orders',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective],
  templateUrl: './store-orders.component.html',
  styleUrl: './store-orders.component.css',
})
export class StoreOrdersComponent implements OnInit {
  store_name = '';
  private storeId = 0;

  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<OrderRow>;
  dataSource!: AxServerDataSource<OrderRow>;

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
    this.dataSource = new AxServerDataSource<OrderRow>((q) => this.fetchOrders(q));
    this.config = {
      tableId: 'store-orders',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search orders…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No orders',
      emptyDescription: 'This store has no orders yet.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'store-orders' },
      columns: [
        { key: 'created', label: 'Date', sortable: true, sticky: 'left', width: '11rem',
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
        { key: 'product', label: 'Product' },
        { key: 'name', label: 'Customer', hideOnMobile: true },
        { key: 'quantity', label: 'Qty', align: 'center', hideOnMobile: true },
        { key: 'total_price', label: 'Total', align: 'right',
          format: (v) => (v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—') },
        { key: 'status', label: 'Status', align: 'center' },
      ],
      rowActions: [{ id: 'view', label: 'View order', icon: 'visibility' }],
    };
  }

  private fetchOrders(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
      vendor: this.storeId,
    };
    if (query.search) q.search = query.search;
    return this.adapter.get_v3('GET /admin/orders', { query: q }).pipe(
      map((response: any): AxServerFetchResult<OrderRow> => {
        const raw: any[] = response?.orders ?? (Array.isArray(response?.data) ? response.data : response?.data?.items ?? []);
        return { rows: raw as OrderRow[], total: response?.pagination?.total ?? response?.meta?.total ?? raw.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load store orders.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<OrderRow>);
      }),
    );
  }

  onRowAction(e: { action: { id: string }; row: OrderRow }) {
    if (e.action.id === 'view') {
      this.router.navigate(['/', 'admin_order'], { queryParams: { id: e.row.id, name: e.row.product } });
    }
  }

  goBack() { this.router.navigate(['/stores']); }
}
