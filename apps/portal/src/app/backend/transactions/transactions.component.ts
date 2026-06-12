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

export interface Transactions extends Record<string, unknown> {
  id: number;
  order_id: string;
  transaction_id: string;
  cart_code: string;
  merchantReference: string;
  amount: string;
  customer: string;
  status: string;
  created: string;
  updated: string;
}

@Component({
  selector: 'app-transactions',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective],
  templateUrl: './transactions.component.html',
  styleUrl: './transactions.component.css',
})
export class TransactionsComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<Transactions>;
  dataSource!: AxServerDataSource<Transactions>;

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
    this.dataSource = new AxServerDataSource<Transactions>((q) => this.fetchTransactions(q));
    this.config = {
      tableId: 'admin-transactions',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search by order, ref, customer…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No transactions found',
      emptyDescription: 'No transactions match your current filters.',
      export: { enabled: true, formats: ['csv', 'xlsx', 'pdf'], filename: 'transactions' },
      filters: [
        { key: 'date', label: 'Date', type: 'date-range' },
        {
          key: 'status', label: 'Status', type: 'select',
          options: [
            { label: 'Paid', value: 'paid' },
            { label: 'Pending', value: 'pending' },
            { label: 'Failed', value: 'failed' },
            { label: 'Refunded', value: 'refunded' },
          ],
        },
      ],
      columns: [
        { key: 'order_id', label: 'Order ID', sortable: true, sticky: 'left', width: '12rem' },
        { key: 'transaction_id', label: 'Transaction ref', hideOnMobile: true },
        { key: 'cart_code', label: 'Cart ID', hideOnMobile: true },
        { key: 'merchantReference', label: 'Merchant ref', hideOnMobile: true },
        { key: 'amount', label: 'Amount', align: 'right',
          format: (v) => (v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—') },
        { key: 'customer', label: 'Customer' },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'created', label: 'Initiated', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
      ],
    };
  }

  private fetchTransactions(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    const range = query.filters['date'] as AxDateRange | undefined;
    if (range?.from) q.since = range.from;
    if (range?.to) q.until = range.to;
    const status = query.filters['status'];
    if (status) q.status = status;

    return this.adapter.get_v3('GET /admin/transactions', { query: q }).pipe(
      map((response: any): AxServerFetchResult<Transactions> => {
        const raw: any[] = Array.isArray(response?.data)
          ? response.data
          : response?.data?.items ?? [];
        return { rows: raw as Transactions[], total: response?.meta?.total ?? raw.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load transactions at this time.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<Transactions>);
      }),
    );
  }

  goBack() { this.router.navigate(['/backend']); }
}
