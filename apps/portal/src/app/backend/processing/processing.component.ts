import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { CommonModule } from '@angular/common';
import { of, firstValueFrom } from 'rxjs';
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
import {
  ORDER_STATUS_OPTIONS,
  ORDER_TYPE_OPTIONS,
  ACTIVE_ORDER_STATUSES,
  ACTIVE_ORDER_STATUS_VALUE,
  loadAdminVendorOptions,
  prettyOrderStatus,
} from '../shared/order-filters';

export interface Transaction extends Record<string, unknown> {
  id: number;
  order_id: string;
  customer: string;
  product_name: string;
  store: number;
  items_count: number;
  total_paid: string;
  status: string;
  created: string;
  /** Checkout channel the order came from ('MOBILE' | 'WEB'), null if untracked. */
  channel: string | null;
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
      searchPlaceholder: 'Search orders by reference, product or customer…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No orders found',
      emptyDescription: 'No orders match your current filters.',
      export: { enabled: true, formats: ['csv', 'xlsx', 'pdf'], filename: 'orders-and-sales' },
      filters: [
        // Status as a chip strip: defaults to "Active" (every status except
        // the exempt set, pending payment, cancelled, failed), each chip shows
        // a count. "All" and the individual statuses remain selectable.
        {
          key: 'status',
          label: 'Status',
          type: 'chips',
          options: [
            { label: 'Active', value: ACTIVE_ORDER_STATUS_VALUE },
            { label: 'All', value: '' },
            ...ORDER_STATUS_OPTIONS,
          ],
          defaultValue: ACTIVE_ORDER_STATUS_VALUE,
          countsLoader: () => this.loadStatusCounts(),
        },
        { key: 'date', label: 'Date', type: 'date-range' },
        { key: 'type', label: 'Type', type: 'select', options: ORDER_TYPE_OPTIONS },
        { key: 'vendor', label: 'Store', type: 'select', optionsLoader: () => loadAdminVendorOptions(this.adapter) },
      ],
      columns: [
        { key: 'order_id', label: 'Order ref', sortable: true, sticky: 'left', width: '14rem' },
        { key: 'customer', label: 'Customer' },
        { key: 'product_name', label: 'Products', hideOnMobile: true },
        { key: 'items_count', label: 'Items', align: 'center', hideOnMobile: true },
        { key: 'total_paid', label: 'Total', align: 'right',
          format: (v) => (v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—') },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'channel', label: 'Channel', align: 'center', hideOnMobile: true,
          format: (v) => (v === 'MOBILE' ? 'Mobile' : v === 'WEB' ? 'Web' : '—') },
        { key: 'created', label: 'Date', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
      ],
      rowActions: [
        { id: 'view', label: 'View order', icon: 'visibility' },
        // Jump to this order's store sales (first item's vendor). Disabled for
        // gift-card / vendorless orders, which have no store to drill into.
        { id: 'store', label: 'Store sales', icon: 'storefront', disabled: (row) => !row.store },
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
    // Order-type filter: 'product' (exclude gift cards) or 'gift_card' (only them).
    if (query.filters['type']) q.type = query.filters['type'];
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

  /** Flatten the admin order shape into the Orders & Sales row. */
  private mapRow(o: any): Transaction {
    const customer = o.customer ?? {};
    const name = `${customer.first_name ?? ''} ${customer.last_name ?? ''}`.trim();
    const items: any[] = o.items ?? [];
    const first = items[0] ?? {};
    // Product summary: single product name, or "First +N more" for multi-item
    // orders. Gift-card purchases carry a synthesized "… Gift Card" line, so
    // they read naturally here too.
    const productLabel = items.length > 1
      ? `${first.product_name ?? 'Item'} +${items.length - 1} more`
      : (first.product_name ?? '—');
    return {
      ...o,
      id: o.id,
      order_id: o.order_reference ?? o.id,
      customer: name || customer.email || '—',
      product_name: productLabel,
      store: first.vendor_id ?? first.store ?? 0,
      items_count: items.reduce((s, i) => s + (Number(i.quantity) || 0), 0) || items.length,
      total_paid: o.total ?? o.subtotal ?? '0',
      status: o.status ?? '',
      channel: o.channel ?? null,
      created: o.date ?? o.created_at ?? '',
    } as Transaction;
  }

  /**
   * Per-status order counts for the status chips. One cheap limit=1 query per
   * status (reads pagination.total only); the "All" bucket ('') is their sum.
   * Counts are totals, independent of the date/type/store filters, so they
   * stay stable as the admin drills in. Failures degrade to 0, never blocking
   * the table.
   */
  private async loadStatusCounts(): Promise<Record<string, number>> {
    const statuses = ORDER_STATUS_OPTIONS.map((o) => String(o.value));
    const totals = await Promise.all(
      statuses.map((s) =>
        firstValueFrom(this.adapter.get_v3('GET /admin/orders', { query: { status: s, limit: 1 } }))
          .then((r: any) => Number(r?.pagination?.total ?? r?.meta?.total ?? 0) || 0)
          .catch(() => 0),
      ),
    );
    const counts: Record<string, number> = {};
    let all = 0;
    statuses.forEach((s, i) => {
      counts[s] = totals[i];
      all += totals[i];
    });
    counts[''] = all;
    // "Active" bucket = sum of the non-exempt statuses (its CSV chip value).
    counts[ACTIVE_ORDER_STATUS_VALUE] = ACTIVE_ORDER_STATUSES.reduce(
      (sum, s) => sum + (counts[s] ?? 0),
      0,
    );
    return counts;
  }

  prettyStatus(status: string): string {
    return prettyOrderStatus(status);
  }

  onRowAction(e: { action: { id: string }; row: Transaction }) {
    if (e.action.id === 'view') {
      this.router.navigate(['/single'], { queryParams: { order: e.row.id } });
    } else if (e.action.id === 'store' && e.row.store) {
      this.router.navigate(['/plural'], { queryParams: { vendor: e.row.store } });
    }
  }

  goBack() { this.navHistory.back('/backend'); }
}
