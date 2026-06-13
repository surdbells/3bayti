import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
  type AxDateRange,
} from '../../shared/data/enterprise';

export interface Transaction extends Record<string, unknown> {
  id: number;
  order_id: string;
  transaction_id: string;
  merchantReference: string;
  customer: string;
  total_paid: string;
  delivery_fee: string;
  status: string;
  created: string;
}

@Component({
  selector: 'app-processing',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective],
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
        {
          key: 'status', label: 'Status', type: 'select',
          options: [
            { label: 'Pending', value: 'pending' },
            { label: 'Accepted', value: 'accepted' },
            { label: 'Preparing', value: 'preparing' },
            { label: 'Shipped', value: 'shipped' },
            { label: 'Delivered', value: 'delivered' },
            { label: 'Returned', value: 'returned' },
            { label: 'Cancelled', value: 'cancelled' },
          ],
        },
      ],
      columns: [
        { key: 'order_id', label: 'Order ID', sortable: true, sticky: 'left', width: '13rem' },
        { key: 'transaction_id', label: 'Transaction ID', hideOnMobile: true },
        { key: 'merchantReference', label: 'Merchant ref', hideOnMobile: true },
        { key: 'customer', label: 'Customer' },
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
    const range = query.filters['date'] as AxDateRange | undefined;
    if (range?.from) q.since = range.from;
    if (range?.to) q.until = range.to;

    return this.adapter.get_v3('GET /admin/orders', { query: q }).pipe(
      map((response: any): AxServerFetchResult<Transaction> => {
        const raw: any[] = response?.orders ?? response?.data ?? [];
        const rows = raw.map((o) => this.mapRow(o));
        return { rows, total: response?.pagination?.total ?? response?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load orders at this time.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<Transaction>);
      }),
    );
  }

  /** Flatten the admin order shape into the processing row. */
  private mapRow(o: any): Transaction {
    const customer = o.customer ?? {};
    const name = `${customer.first_name ?? ''} ${customer.last_name ?? ''}`.trim();
    return {
      ...o,
      id: o.id,
      order_id: o.order_reference ?? o.id,
      transaction_id: o.order_reference ?? '',
      customer: name || customer.email || '—',
      total_paid: o.total ?? o.subtotal ?? '0',
      delivery_fee: o.delivery_fee ?? '0',
      status: o.status ?? '',
      created: o.date ?? o.created_at ?? '',
    } as Transaction;
  }

  onRowAction(e: { action: { id: string }; row: Transaction }) {
    if (e.action.id === 'view') {
      this.openPopup('/single?order=' + e.row.id);
    }
  }

  openPopup(path: string) {
    window.open(path, '_blank', 'width=900,height=800');
  }

  goBack() { this.router.navigate(['/backend']); }
}
