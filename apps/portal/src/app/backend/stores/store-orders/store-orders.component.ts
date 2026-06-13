import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { AxConfirmService } from '../../../shared/overlays';
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

interface OrderItem {
  id: number;
  product_id: number;
  product_name: string;
  product_image: string;
  quantity: number;
  unit_price: string;
  subtotal: string;
  size: string | null;
  color: string | null;
  item_status: string;
}

interface OrderDetail {
  id: number;
  order_reference: string;
  status: string;
  date: string;
  subtotal: string;
  delivery_fee: string;
  discount: string;
  total: string;
  currency: string;
  paid_at: string | null;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  items: OrderItem[];
}

interface TimelineEntry {
  status?: string;
  action?: string;
  label?: string;
  at?: string;
  created_at?: string;
  note?: string;
}

/**
 * Client-side mirror of the server's OrderItem transition state machine
 * (src/Domain/Order/OrderItem.php). Lets the drawer show only valid next
 * statuses per item; the server re-validates, so this is UX-only.
 */
const ITEM_TRANSITIONS: Record<string, string[]> = {
  pending: ['accepted', 'rejected', 'cancelled'],
  accepted: ['preparing', 'cancelled'],
  preparing: ['shipped', 'cancelled'],
  shipped: ['delivered', 'returned'],
  delivered: ['returned'],
  rejected: [],
  cancelled: ['refunded'],
  returned: ['refunded'],
  refunded: [],
};

@Component({
  selector: 'app-store-orders',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './store-orders.component.html',
  styleUrl: './store-orders.component.css',
})
export class StoreOrdersComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);

  store_name = '';
  private storeId = 0;
  private vendorV3Id = 0;
  private isAdmin = false;

  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<OrderRow>;
  dataSource!: AxServerDataSource<OrderRow>;

  // Drawer state
  readonly drawerOpen = signal(false);
  readonly loadingDetail = signal(false);
  readonly busy = signal(false);
  readonly order = signal<OrderDetail | null>(null);
  readonly timeline = signal<TimelineEntry[]>([]);

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
    this.isAdmin = this.user_session.is_admin
      || (this.user_session as any).is_finance
      || (this.user_session as any).is_support;
    this.storeId = Number(this.route.snapshot.queryParamMap.get('id'));
    this.store_name = this.route.snapshot.queryParamMap.get('name') ?? '';
    this.resolveVendorThenBuild();
  }

  /** Resolve legacy store id → v3 vendor id, then build the (vendor-scoped) table. */
  private resolveVendorThenBuild() {
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
    if (e.action.id === 'manage') this.openOrder(e.row.id);
  }

  // ── Drawer / detail ────────────────────────────────────────────────
  openOrder(id: number) {
    this.drawerOpen.set(true);
    this.loadingDetail.set(true);
    this.order.set(null);
    this.timeline.set([]);

    this.adapter.get_v3('GET /admin/orders/:id', { params: { id: String(id) } }).subscribe({
      next: (res: any) => {
        this.order.set(res?.order ?? res?.data ?? null);
        this.loadingDetail.set(false);
      },
      error: () => {
        this.toast.error('Unable to load order.');
        this.loadingDetail.set(false);
        this.drawerOpen.set(false);
      },
    });

    this.adapter.get_v3('GET /admin/orders/:id/timeline', { params: { id: String(id) } }).subscribe({
      next: (res: any) => this.timeline.set(res?.data ?? res?.timeline ?? []),
      error: () => { /* timeline is non-critical */ },
    });
  }

  closeDrawer() { this.drawerOpen.set(false); }

  /** Valid next statuses for an item per the server state machine. */
  nextStatuses(item: OrderItem): string[] {
    return ITEM_TRANSITIONS[item.item_status] ?? [];
  }

  changeItemStatus(item: OrderItem, status: string) {
    const ord = this.order();
    if (!ord) return;
    this.confirm.confirm({
      title: 'Update item status',
      message: `Move "${item.product_name}" to ${this.statusLabel(status)}?`,
      confirmLabel: 'Update', cancelLabel: 'Cancel',
      variant: status === 'cancelled' || status === 'rejected' ? 'danger' : 'default',
    }).then((ok) => {
      if (!ok) return;
      this.busy.set(true);
      this.adapter.patch_v3('PATCH /admin/orders/:orderId/items/:itemId/status',
        { status },
        { params: { orderId: String(ord.id), itemId: String(item.id) } },
      ).subscribe({
        next: (r: any) => {
          if (r) { this.toast.success('Item status updated.'); this.openOrder(ord.id); this.dataSource.retry(); }
          this.busy.set(false);
        },
        error: () => { this.toast.error('Unable to update item status.'); this.busy.set(false); },
      });
    });
  }

  cancelOrder() {
    const ord = this.order();
    if (!ord) return;
    this.confirm.confirm({
      title: 'Cancel order',
      message: `Cancel order ${ord.order_reference}? This cannot be undone.`,
      confirmLabel: 'Cancel order', cancelLabel: 'Keep order', variant: 'danger',
    }).then((ok) => {
      if (!ok) return;
      this.busy.set(true);
      this.adapter.post_v3('POST /admin/orders/:id/cancel', { reason: 'admin_cancelled' }, { params: { id: String(ord.id) } })
        .subscribe({
          next: (r: any) => {
            if (r) { this.toast.success('Order cancelled.'); this.openOrder(ord.id); this.dataSource.retry(); }
            this.busy.set(false);
          },
          error: () => { this.toast.error('Unable to cancel order.'); this.busy.set(false); },
        });
    });
  }

  refundOrder() {
    const ord = this.order();
    if (!ord) return;
    this.confirm.confirm({
      title: 'Refund order',
      message: `Refund order ${ord.order_reference} (${this.money(ord.total)})?`,
      confirmLabel: 'Issue refund', cancelLabel: 'Cancel', variant: 'danger',
    }).then((ok) => {
      if (!ok) return;
      this.busy.set(true);
      this.adapter.post_v3('POST /admin/orders/:id/refund', { reason: 'admin_refund' }, { params: { id: String(ord.id) } })
        .subscribe({
          next: (r: any) => {
            if (r) { this.toast.success('Refund issued.'); this.openOrder(ord.id); this.dataSource.retry(); }
            this.busy.set(false);
          },
          error: () => { this.toast.error('Unable to issue refund.'); this.busy.set(false); },
        });
    });
  }

  canCancel(): boolean {
    const s = this.order()?.status ?? '';
    return !['delivered', 'cancelled', 'returned', 'refunded'].includes(s);
  }

  canRefund(): boolean {
    const s = this.order()?.status ?? '';
    return ['delivered', 'cancelled', 'returned'].includes(s);
  }

  // ── Display helpers ────────────────────────────────────────────────
  money(v: unknown): string {
    return v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—';
  }

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
