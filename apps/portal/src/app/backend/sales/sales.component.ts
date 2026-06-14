import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
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
  type AxDateRange,
} from '../../shared/data/enterprise';
import { ORDER_STATUS_OPTIONS, loadAdminVendorOptions, prettyOrderStatus } from '../shared/order-filters';

interface SaleRow extends Record<string, unknown> {
  id: number;
  order_ref: string;
  product_name: string;
  customer_name: string;
  store: number;
  quantity: number;
  price: string;
  status: string;
  created: string;
}

@Component({
  selector: 'app-sales',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './sales.component.html',
  styleUrl: './sales.component.css',
})
export class SalesComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<SaleRow>;
  dataSource!: AxServerDataSource<SaleRow>;

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

  private money = (v: unknown) =>
    v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—';

  private buildTable() {
    this.dataSource = new AxServerDataSource<SaleRow>((q) => this.fetchSales(q));
    this.config = {
      tableId: 'admin-sales',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search sales by product or customer…',
      stickyHeader: true,
      hover: true,
      compact: true,
      emptyTitle: 'No sales found',
      emptyDescription: 'No sales match your current filters.',
      export: { enabled: true, formats: ['csv', 'xlsx', 'pdf'], filename: 'sales' },
      filters: [
        { key: 'date', label: 'Date', type: 'date-range' },
        { key: 'status', label: 'Status', type: 'select', options: ORDER_STATUS_OPTIONS },
        { key: 'vendor', label: 'Store', type: 'select', optionsLoader: () => loadAdminVendorOptions(this.adapter) },
      ],
      columns: [
        { key: 'order_ref', label: 'Order ref', sortable: true, sticky: 'left', width: '14rem' },
        { key: 'product_name', label: 'Product' },
        { key: 'customer_name', label: 'Customer', hideOnMobile: true },
        { key: 'quantity', label: 'Qty', align: 'center', hideOnMobile: true },
        { key: 'price', label: 'Total', align: 'right', format: (v) => this.money(v) },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'created', label: 'Date', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
      ],
      rowActions: [{ id: 'store', label: 'Store sales', icon: 'storefront' }],
    };
  }

  private fetchSales(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    if (query.filters['status']) q.status = query.filters['status'];
    if (query.filters['vendor']) q.vendor_id = query.filters['vendor'];
    const range = query.filters['date'] as AxDateRange | undefined;
    if (range?.from) q.since = range.from;
    if (range?.to) q.until = range.to;
    return this.adapter.get_v3('GET /admin/orders', { query: q }).pipe(
      map((response: any): AxServerFetchResult<SaleRow> => {
        const raw: any[] = response?.orders ?? (Array.isArray(response?.data) ? response.data : []);
        const rows = raw.map((o) => this.mapRow(o));
        return { rows, total: response?.pagination?.total ?? response?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load sales at this time.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<SaleRow>);
      }),
    );
  }

  /** Flatten an admin order into a sales row. */
  private mapRow(o: any): SaleRow {
    const items: any[] = o.items ?? [];
    const first = items[0] ?? {};
    const qty = items.reduce((s, i) => s + (Number(i.quantity) || 0), 0);
    const productLabel = items.length > 1
      ? `${first.product_name ?? 'Item'} +${items.length - 1} more`
      : (first.product_name ?? `Order ${o.order_reference ?? o.id}`);
    const customer = o.customer ?? {};
    const name = `${customer.first_name ?? ''} ${customer.last_name ?? ''}`.trim();
    return {
      ...o,
      id: o.id,
      order_ref: o.order_reference ?? o.id,
      product_name: productLabel,
      customer_name: name || customer.email || '—',
      store: first.vendor_id ?? first.store ?? 0,
      quantity: qty,
      price: o.total ?? o.subtotal ?? '0',
      status: o.status ?? '',
      created: o.date ?? o.created_at ?? '',
    } as SaleRow;
  }

  onRowAction(e: { action: { id: string }; row: SaleRow }) {    if (e.action.id === 'store') {
      window.open('/plural?vendor=' + e.row.store, '_blank', 'width=900,height=800');
    }
  }

  prettyStatus(status: string): string {
    return prettyOrderStatus(status);
  }

  goBack() { this.router.navigate(['/backend']); }
}
