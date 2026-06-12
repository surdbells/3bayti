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
  type AxDateRange,
} from '../../../shared/data/enterprise';

interface SaleRow extends Record<string, unknown> {
  id: number;
  order_ref: string;
  product_name: string;
  transaction_ref: string;
  quantity: number;
  total_price: string;
  commission: string;
  charges: string;
  vendor_pay: string;
  customer_name: string;
  status: string;
  created: string;
}

@Component({
  selector: 'app-store-sales',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective],
  templateUrl: './store-sales.component.html',
  styleUrl: './store-sales.component.css',
})
export class StoreSalesComponent implements OnInit {
  store_name = '';
  private storeId = 0;

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

  private money = (v: unknown) =>
    v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—';

  private buildTable() {
    this.dataSource = new AxServerDataSource<SaleRow>((q) => this.fetchSales(q));
    this.config = {
      tableId: 'store-sales',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search sales…',
      stickyHeader: true,
      hover: true,
      compact: true,
      emptyTitle: 'No sales',
      emptyDescription: 'This store has no sales in the selected range.',
      export: { enabled: true, formats: ['csv', 'xlsx', 'pdf'], filename: 'store-sales' },
      filters: [{ key: 'date', label: 'Date', type: 'date-range' }],
      columns: [
        { key: 'order_ref', label: 'Order ref', sortable: true, sticky: 'left', width: '12rem' },
        { key: 'product_name', label: 'Product' },
        { key: 'customer_name', label: 'Customer', hideOnMobile: true },
        { key: 'quantity', label: 'Qty', align: 'center', hideOnMobile: true },
        { key: 'total_price', label: 'Total', align: 'right', format: (v) => this.money(v) },
        { key: 'commission', label: 'Commission', align: 'right', hideOnMobile: true, format: (v) => this.money(v) },
        { key: 'vendor_pay', label: 'Vendor pay', align: 'right', format: (v) => this.money(v) },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'created', label: 'Date', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
      ],
    };
  }

  private fetchSales(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
      vendor: this.storeId,
    };
    if (query.search) q.search = query.search;
    const range = query.filters['date'] as AxDateRange | undefined;
    if (range?.from) q.since = range.from;
    if (range?.to) q.until = range.to;
    return this.adapter.get_v3('GET /admin/transactions', { query: q }).pipe(
      map((response: any): AxServerFetchResult<SaleRow> => {
        const raw: any[] = Array.isArray(response?.data) ? response.data : response?.data?.items ?? [];
        return { rows: raw as SaleRow[], total: response?.meta?.total ?? raw.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load store sales.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<SaleRow>);
      }),
    );
  }

  goBack() { this.router.navigate(['/stores']); }
}
