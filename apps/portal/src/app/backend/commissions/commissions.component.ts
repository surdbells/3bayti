import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
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

export interface Sales extends Record<string, unknown> {
  id: number;
  order_ref: string;
  product_name: string;
  transaction_ref: string;
  quantity: number;
  price: number;
  total_price: string;
  delivery: string;
  total_paid: string;
  noon: string;
  commission: string;
  charges: string;
  vendor_pay: string;
  customer_email: string;
  customer_name: string;
  status: string;
  created: string;
}

@Component({
  selector: 'app-commissions',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './commissions.component.html',
  styleUrl: './commissions.component.css',
})
export class CommissionsComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<Sales>;
  dataSource!: AxServerDataSource<Sales>;

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
    this.dataSource = new AxServerDataSource<Sales>((q) => this.fetchCommissions(q));
    this.config = {
      tableId: 'admin-commissions',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search by order ref, product, customer…',
      stickyHeader: true,
      hover: true,
      compact: true,
      emptyTitle: 'No commissions found',
      emptyDescription: 'No commission records match your current filters.',
      export: { enabled: true, formats: ['csv', 'xlsx', 'pdf'], filename: 'commissions' },
      filters: [
        { key: 'date', label: 'Date', type: 'date-range' },
      ],
      columns: [
        { key: 'order_ref', label: 'Order ref', sortable: true, sticky: 'left', width: '14rem' },
        { key: 'total_paid', label: 'Order total', align: 'right', format: (v) => this.money(v) },
        { key: 'commission', label: 'Commission', align: 'right', format: (v) => this.money(v) },
        { key: 'vendor_pay', label: 'Vendor pay', align: 'right', format: (v) => this.money(v) },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'created', label: 'Date', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
      ],
    };
  }

  private fetchCommissions(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    const range = query.filters['date'] as AxDateRange | undefined;
    if (range?.from) q.since = range.from;
    if (range?.to) q.until = range.to;

    return this.adapter.get_v3('GET /admin/commissions', { query: q }).pipe(
      map((response: any): AxServerFetchResult<Sales> => {
        const raw: any[] = response?.data ?? response?.commissions ?? [];
        const rows = raw.map((o) => this.mapRow(o));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load commissions at this time.'));
        return of({ rows: [], total: 0 } as AxServerFetchResult<Sales>);
      }),
    );
  }

  /** Map the /admin/commissions order shape into the row. Commission and
   *  vendor-pay values are computed server-side; null until that lands. */
  private mapRow(o: any): Sales {
    return {
      ...o,
      id: o.id,
      order_ref: o.order_reference ?? o.id,
      total_paid: o.subtotal ?? o.total ?? '0',
      commission: o.commission_aed ?? null,
      vendor_pay: o.vendor_pay_aed ?? null,
      status: o.status ?? '',
      created: o.created_at ?? o.date ?? '',
    } as Sales;
  }

  goBack() { this.router.navigate(['/backend']); }
}
