import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
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
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './store-orders.component.html',
  styleUrl: './store-orders.component.css',
})
export class StoreOrdersComponent implements OnInit {
  store_name = '';
  private storeId = 0;
  private vendorV3Id = 0;

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
    // The admin flow passes the v3 vendor id straight through as vendor_id;
    // only fall back to legacy-id resolution when it's absent (older links).
    this.vendorV3Id = Number(this.route.snapshot.queryParamMap.get('vendor_id')) || 0;
    this.resolveVendorThenBuild();
  }

  /** Use the v3 vendor id when supplied; otherwise resolve it from the legacy store id. */
  private resolveVendorThenBuild() {
    if (this.vendorV3Id > 0) {
      this.buildTable();
      return;
    }
    this.adapter.get_v3('GET /vendors/by-legacy-id/:id', { params: { id: String(this.storeId) } }).subscribe({
      next: (res: any) => {
        this.vendorV3Id = res?.data?.id ?? 0;
        this.buildTable();
      },
      error: () => {
        // Build anyway; without a vendor id the list would be cross-store,
        // so guard the fetch on a resolved id instead (see fetchOrders).
        this.buildTable();
      },
    });
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
      filters: [
        {
          key: 'status', label: 'Status', type: 'select',
          options: [
            { label: 'Pending', value: 'pending' },
            { label: 'Accepted', value: 'accepted' },
            { label: 'Preparing', value: 'preparing' },
            { label: 'Shipped', value: 'shipped' },
            { label: 'Delivered', value: 'delivered' },
            { label: 'Cancelled', value: 'cancelled' },
            { label: 'Returned', value: 'returned' },
          ],
        },
      ],
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
      rowActions: [
        { id: 'manage', label: 'Manage order', icon: 'tune' },
      ],
    };
  }

  private fetchOrders(query: AxQueryState) {
    // The list MUST be scoped to this store. /admin/orders filters by the
    // v3 vendor_id; we resolved it from the legacy id at init. If it isn't
    // ready (resolution failed), return empty rather than leaking a
    // cross-store list.
    if (!this.vendorV3Id) {
      return of({ rows: [], total: 0 } as AxServerFetchResult<OrderRow>);
    }
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
      vendor_id: this.vendorV3Id,
    };
    if (query.search) q.search = query.search;
    if (query.filters['status']) q.status = query.filters['status'];
    return this.adapter.get_v3('GET /admin/orders', { query: q }).pipe(
      map((response: any): AxServerFetchResult<OrderRow> => {
        const raw: any[] = response?.orders ?? (Array.isArray(response?.data) ? response.data : response?.data?.items ?? []);
        const rows = raw.map((o) => this.mapOrder(o));
        return { rows, total: response?.pagination?.total ?? response?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load store orders.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<OrderRow>);
      }),
    );
  }

  /** Flatten the admin order shape (items[], no customer) into a table row. */
  private mapOrder(o: any): OrderRow {
    const items: any[] = o.items ?? [];
    const first = items[0] ?? {};
    const qty = items.reduce((s, i) => s + (Number(i.quantity) || 0), 0);
    const productLabel = items.length > 1
      ? `${first.product_name ?? 'Item'} +${items.length - 1} more`
      : (first.product_name ?? `Order ${o.order_reference ?? o.id}`);
    const customer = o.customer ?? {};
    const name = `${customer.first_name ?? ''} ${customer.last_name ?? ''}`.trim();
    return {
      id: o.id,
      created: o.date ?? o.created_at ?? '',
      product: productLabel,
      name: name || customer.email || '—',
      quantity: qty,
      total_price: o.total ?? o.subtotal ?? '0',
      status: o.status ?? '',
    };
  }

  onRowAction(e: { action: { id: string }; row: OrderRow }) {
    // Open the order in the routed detail page (/single) — same pattern as
    // processing/logistics/deliveries and the corrected vendor portal —
    // instead of an in-place drawer.
    if (e.action.id === 'manage') {
      this.router.navigate(['/single'], { queryParams: { order: e.row.id } });
    }
  }

  // ── Display helpers ────────────────────────────────────────────────
  statusLabel(s: string): string {
    return (s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  statusBadge(s: string): string {
    switch (s) {
      case 'delivered': return 'ax-badge ax-badge-success';
      case 'shipped': case 'preparing': case 'accepted': return 'ax-badge ax-badge-info';
      case 'pending': return 'ax-badge ax-badge-warning';
      case 'cancelled': case 'rejected': case 'returned': case 'refunded': return 'ax-badge ax-badge-danger';
      default: return 'ax-badge ax-badge-neutral';
    }
  }

  goBack() { this.router.navigate(['/stores']); }
}
