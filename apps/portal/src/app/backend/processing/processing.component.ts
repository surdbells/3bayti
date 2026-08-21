import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
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

export interface Transaction extends Record<string, unknown> {
  id: number;
  order_id: string;
  customer: string;
  items_count: number;
  total_paid: string;
  status: string;
  created: string;
}

@Component({
  selector: 'app-processing',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './processing.component.html',
  styleUrl: './processing.component.css',
})
export class ProcessingComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<Transaction>;
  dataSource!: AxServerDataSource<Transaction>;

  constructor(
    private router: Router,
    private navHistory: NavigationHistoryService,
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
    this.dataSource = new AxServerDataSource<Transaction>((q) => this.fetchOrders(q));
    this.config = {
      tableId: 'admin-processing',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search orders by reference or customer…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No orders found',
      emptyDescription: 'No orders match your current filters.',
      export: { enabled: true, formats: ['csv', 'xlsx', 'pdf'], filename: 'orders-processing' },
      filters: [
        { key: 'date', label: 'Date', type: 'date-range' },
        { key: 'status', label: 'Status', type: 'select', options: ORDER_STATUS_OPTIONS },
        { key: 'vendor', label: 'Store', type: 'select', optionsLoader: () => loadAdminVendorOptions(this.adapter) },
      ],
      columns: [
        { key: 'order_id', label: 'Order ref', sortable: true, sticky: 'left', width: '14rem' },
        { key: 'customer', label: 'Customer' },
        { key: 'items_count', label: 'Items', align: 'center', hideOnMobile: true },
        { key: 'total_paid', label: 'Total', align: 'right',
          format: (v) => (v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—') },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'created', label: 'Date', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
      ],
      rowActions: [
        { id: 'view', label: 'View order', icon: 'visibility' },
      ],
    };
  }

  private fetchOrders(query: AxQueryState) {
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
      map((response: any): AxServerFetchResult<Transaction> => {
        const raw: any[] = response?.orders ?? response?.data ?? [];
        const rows = raw.map((o) => this.mapRow(o));
        return { rows, total: response?.pagination?.total ?? response?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load orders at this time.'));
        return of({ rows: [], total: 0 } as AxServerFetchResult<Transaction>);
      }),
    );
  }

  /** Flatten the admin order shape into the processing row. */
  private mapRow(o: any): Transaction {
    const customer = o.customer ?? {};
    const name = `${customer.first_name ?? ''} ${customer.last_name ?? ''}`.trim();
    const items: any[] = o.items ?? [];
    return {
      ...o,
      id: o.id,
      order_id: o.order_reference ?? o.id,
      customer: name || customer.email || '—',
      items_count: items.reduce((s, i) => s + (Number(i.quantity) || 0), 0) || items.length,
      total_paid: o.total ?? o.subtotal ?? '0',
      status: o.status ?? '',
      created: o.date ?? o.created_at ?? '',
    } as Transaction;
  }

  prettyStatus(status: string): string {
    return prettyOrderStatus(status);
  }

  onRowAction(e: { action: { id: string }; row: Transaction }) {
    if (e.action.id === 'view') {
      this.router.navigate(['/single'], { queryParams: { order: e.row.id } });
    }
  }

  goBack() { this.navHistory.back('/backend'); }
}
