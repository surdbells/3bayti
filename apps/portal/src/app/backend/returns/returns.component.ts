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

interface AdminReturnRow extends Record<string, unknown> {
  id: number;
  order_id: number;
  order_ref: string;
  store: string;
  product: string;
  customer: string;
  quantity: number;
  total_price: string;
  reason: string;
  status: string;
  created: string;
}

/**
 * Admin cross-vendor returns queue, parity with legacy
 * admin/common/get-returns.php. Reads GET /admin/returns (all vendors)
 * with status filter + date range; shows which store each return belongs
 * to (admins oversee every vendor, unlike the vendor-scoped screen).
 */
@Component({
  selector: 'app-returns',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './returns.component.html',
  styleUrl: './returns.component.css',
})
export class ReturnsComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<AdminReturnRow>;
  dataSource!: AxServerDataSource<AdminReturnRow>;

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
    this.dataSource = new AxServerDataSource<AdminReturnRow>((q) => this.fetchReturns(q));
    this.config = {
      tableId: 'admin-returns',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search returns by store, product or customer…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No returns',
      emptyDescription: 'No return requests match these filters.',
      export: { enabled: true, formats: ['csv', 'xlsx', 'pdf'], filename: 'returns' },
      filters: [
        { key: 'date', label: 'Date', type: 'date-range' },
        {
          key: 'status', label: 'Status', type: 'select',
          options: [
            { label: 'Requested', value: 'requested' },
            { label: 'Approved', value: 'approved' },
            { label: 'Denied', value: 'denied' },
            { label: 'Picked up', value: 'picked_up' },
            { label: 'Refunded', value: 'refunded' },
            { label: 'Cancelled', value: 'cancelled' },
          ],
        },
      ],
      columns: [
        { key: 'created', label: 'Date', sortable: true, sticky: 'left', width: '11rem',
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
        { key: 'order_ref', label: 'Order ref' },
        { key: 'store', label: 'Store', hideOnMobile: true },
        { key: 'product', label: 'Product' },
        { key: 'customer', label: 'Customer', hideOnMobile: true },
        { key: 'quantity', label: 'Qty', align: 'center', hideOnMobile: true },
        { key: 'total_price', label: 'Total', align: 'right',
          format: (v) => (v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—') },
        { key: 'status', label: 'Status', align: 'center' },
      ],
      rowActions: [
        { id: 'open', label: 'View order', icon: 'visibility' },
      ],
    };
  }

  private fetchReturns(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    if (query.filters['status']) q.status = query.filters['status'];
    const range = query.filters['date'] as AxDateRange | undefined;
    if (range?.from) q.since = range.from;
    if (range?.to) q.until = range.to;

    return this.adapter.get_v3('GET /admin/returns', { query: q }).pipe(
      map((response: any): AxServerFetchResult<AdminReturnRow> => {
        const raw: any[] = response?.data ?? [];
        const rows = raw.map((r) => this.mapReturn(r));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load returns at this time.'));
        return of({ rows: [], total: 0 } as AxServerFetchResult<AdminReturnRow>);
      }),
    );
  }

  /** Flatten the admin return shape (items[] carry vendor_name) into a row. */
  private mapReturn(r: any): AdminReturnRow {
    const items: any[] = r.items ?? [];
    const first = items[0] ?? {};
    const qty = items.reduce((s: number, i: any) => s + (Number(i.quantity) || 0), 0);
    const productLabel = items.length > 1
      ? `${first.product_name ?? 'Item'} +${items.length - 1} more`
      : (first.product_name ?? `Return ${r.order_reference ?? r.id}`);
    const total = items.reduce((s: number, i: any) => s + (Number(i.line_subtotal) || 0), 0);
    const stores = Array.from(new Set(items.map((i: any) => i.vendor_name).filter(Boolean)));
    const storeLabel = stores.length > 1 ? `${stores[0]} +${stores.length - 1}` : (stores[0] ?? '—');
    return {
      ...r,
      id: r.id,
      order_id: r.order_id ?? r.id,
      order_ref: r.order_reference ?? `#${r.id}`,
      store: storeLabel,
      product: productLabel,
      customer: r.customer_email ?? '—',
      quantity: qty,
      total_price: String(total),
      reason: r.reason ?? '',
      status: r.status ?? '',
      created: r.requested_at ?? r.created_at ?? '',
    } as AdminReturnRow;
  }

  onRowAction(e: { action: { id: string }; row: AdminReturnRow }) {
    if (e.action.id === 'open') {
      this.router.navigate(['/', 'admin_order'], { queryParams: { id: e.row.order_id } });
    }
  }

  statusBadgeClass(status: string): string {
    const s = (status || '').toLowerCase();
    if (s.includes('approved') || s.includes('refunded') || s.includes('complete')) return 'ax-badge ax-badge-success';
    if (s.includes('denied') || s.includes('reject') || s.includes('cancel')) return 'ax-badge ax-badge-danger';
    if (s.includes('request')) return 'ax-badge ax-badge-warning';
    if (s.includes('picked')) return 'ax-badge ax-badge-info';
    return 'ax-badge ax-badge-neutral';
  }

  statusLabel(s: string): string {
    return (s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  goBack() { this.navHistory.back('/backend'); }
}
